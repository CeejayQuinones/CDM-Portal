<?php

namespace App\Services\Enrollment;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\EnrollmentSubject;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\EnrollmentNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class EnrollmentSchedulingService
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function __construct(private EnrollmentAcademicService $academic, private EnrollmentAuditWriter $audit) {}

    public function section(User $user, array $input, ?int $id = null): Section
    {
        return DB::transaction(function () use ($user, $input, $id) {
            $actor = EnrollmentAccess::require($user, true, true);
            $year = AcademicYear::lockForUpdate()->findOrFail($input['academic_year_id']);
            $semester = Semester::lockForUpdate()->findOrFail($input['semester_id']);
            $s = $id ? Section::lockForUpdate()->findOrFail($id) : new Section;
            if ($id) {
                abort_unless($s->version === $input['version'], 409, 'This section changed. Reload before saving.');
                abort_unless($s->academic_year_id === $input['academic_year_id'] && $s->semester_id === $input['semester_id'], 422, 'Section term cannot change.');
                $used = $s->enrollments()->lockForUpdate()->first() || $s->applications()->lockForUpdate()->first() || $s->schedules()->lockForUpdate()->first();
                if ($used) {
                    abort_unless($s->course_id === $input['course_id'] && $s->year_level === $input['year_level'], 422, 'A referenced section cannot change Course or year level.');
                }
                abort_unless($input['capacity'] >= $this->academic->occupied($s), 422, 'Capacity cannot be lower than enrolled and reserved seats.');
            }
            $course = Course::lockForUpdate()->findOrFail($input['course_id']);
            abort_unless($year->status === 'active' && $semester->status === 'active' && $course->status === 'active' && $input['year_level'] <= $course->years, 422, 'Invalid active academic term, Course or year level.');
            abort_if(Section::where('course_id', $course->id)->where('academic_year_id', $year->id)->where('semester_id', $semester->id)->where('section_name', $input['section_name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first(), 409, 'This section name already exists in the term.');
            if (! empty($input['adviser_id'])) {
                $this->professor($input['adviser_id']);
            }
            $s->fill(collect($input)->only(['course_id', 'academic_year_id', 'semester_id', 'section_name', 'year_level', 'adviser_id', 'capacity', 'status'])->all());
            if ($id) {
                $s->version++;
            }$s->save();
            $this->audit->record($actor, $id ? 'section_updated' : 'section_created', 'section', $s->id, ['version' => $s->version]);

            return $s;
        }, 3);
    }

    private function professor(int $id): void
    {
        $p = Professor::whereKey($id)->lockForUpdate()->firstOrFail();
        $user = User::with('role')->whereKey($p->user_id)->lockForUpdate()->first();
        abort_unless($p->status === 'active' && $user?->status === 'active' && $user->role?->role_name === Role::PROFESSOR, 422, 'Choose an active Professor account.');
    }

    public function conflicts(SectionSubject $a, SectionSubject $b): bool
    {
        return $a->id !== $b->id && $a->day === $b->day && $a->start_time < $b->end_time && $a->end_time > $b->start_time && ($a->section_id === $b->section_id || ($a->professor_id && $a->professor_id === $b->professor_id) || ($a->room && mb_strtolower(trim($a->room)) === mb_strtolower(trim($b->room))));
    }

    public function assertFinalSchedules(Collection $selected, int $year, int $semester): void
    {
        $all = SectionSubject::whereHas('section', fn ($q) => $q->where('academic_year_id', $year)->where('semester_id', $semester))->whereIn('day', $selected->pluck('day')->unique())->lockForUpdate()->get();
        foreach ($selected as $a) {
            abort_unless(in_array($a->day, self::DAYS, true), 422, 'A schedule has an invalid day.');
            foreach ($all as $b) {
                abort_if($this->conflicts($a, $b), 409, 'Resolve section, Professor or room conflicts before finalization.');
            }
        }
    }

    public function schedule(User $user, array $input, ?int $id = null, bool $delete = false): ?SectionSubject
    {
        return DB::transaction(function () use ($user, $input, $id, $delete) {
            $actor = EnrollmentAccess::require($user, true, true);
            $ref = $id ? SectionSubject::findOrFail($id) : null;
            $sectionRef = Section::findOrFail($ref?->section_id ?? $input['section_id']);
            $year = AcademicYear::lockForUpdate()->findOrFail($sectionRef->academic_year_id);
            $semester = Semester::lockForUpdate()->findOrFail($sectionRef->semester_id);
            $section = Section::lockForUpdate()->findOrFail($sectionRef->id);
            $s = $id ? SectionSubject::lockForUpdate()->findOrFail($id) : new SectionSubject;
            if ($id) {
                abort_unless($s->version === $input['version'], 409, 'This schedule changed. Reload before continuing.');
            }
            $used = $id ? EnrollmentSubject::where('subject_id', $s->subject_id)->whereHas('enrollment', fn ($q) => $q->where('section_id', $section->id))->lockForUpdate()->first() : null;
            if ($delete) {
                abort_if($used, 409, 'A schedule referenced by academic enrollment cannot be deleted.');
                $this->audit->record($actor, 'schedule_deleted', 'schedule', $s->id, ['version' => $s->version]);
                $s->delete();

                return null;
            }
            abort_unless($section->status === 'open' && $year->status === 'active' && $semester->status === 'active', 422, 'Choose an open section in an active academic term.');
            if ($id) {
                abort_unless($input['section_id'] === $section->id && (! $used || $input['subject_id'] === $s->subject_id), 422, 'Referenced schedules cannot change section or subject.');
            }
            $subject = Subject::with('curriculum')->whereKey($input['subject_id'])->lockForUpdate()->firstOrFail();
            abort_unless($subject->status === 'active' && $subject->curriculum?->course_id === $section->course_id && $subject->semester_id === $section->semester_id && (int) $subject->year_level === (int) $section->year_level && $subject->curriculum->status === 'active' && $subject->curriculum->effective_year <= now()->year, 422, 'Choose an active curriculum subject for this Course, year level and semester.');
            $this->professor($input['professor_id']);
            abort_if(SectionSubject::where('section_id', $section->id)->where('subject_id', $subject->id)->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first(), 409, 'This subject already has a meeting in the section.');
            $s->fill(collect($input)->only(['section_id', 'subject_id', 'professor_id', 'day', 'start_time', 'end_time', 'room'])->all());
            $s->room = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $input['room'])));
            // All schedule writers hold the academic-year parent lock, including when no meeting exists yet.
            $others = SectionSubject::whereHas('section', fn ($q) => $q->where('academic_year_id', $year->id)->where('semester_id', $semester->id))->where('day', $s->day)->where('start_time', '<', $s->end_time)->where('end_time', '>', $s->start_time)->lockForUpdate()->get();
            foreach ($others as $other) {
                abort_if($this->conflicts($s, $other), 409, 'Schedule overlaps an existing section, Professor or room meeting.');
            }
            if ($id) {
                $s->version++;
            }$s->save();
            if ($used) {
                EnrollmentSubject::where('subject_id', $s->subject_id)->whereHas('enrollment', fn ($q) => $q->where('section_id', $section->id)->where('status', 'enrolled'))->update(['professor_id' => $s->professor_id]);
            }
            $this->audit->record($actor, $id ? 'schedule_updated' : 'schedule_created', 'schedule', $s->id, ['version' => $s->version]);
            $recipients = User::whereHas('student', fn ($q) => $q->whereHas('enrollments', fn ($e) => $e->where('section_id', $section->id)->where('status', 'enrolled')))->get();
            Notification::send($recipients, new EnrollmentNotice('schedule_changed', 'Your section schedule has changed. Review My Schedule.', $section->id));

            return $s;
        }, 3);
    }
}
