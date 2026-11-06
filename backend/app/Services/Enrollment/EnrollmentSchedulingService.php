<?php

namespace App\Services\Enrollment;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\EnrollmentSubject;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\SectionSubjectMeeting;
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
            $curriculum = $this->curriculum($course->id, $input['curriculum_id'] ?? $s->curriculum_id);
            if ($id && ($s->applications()->exists() || $s->enrollments()->exists() || $s->schedules()->exists())) {
                abort_unless((int) $s->curriculum_id === $curriculum->id, 422, 'A referenced section cannot change curriculum.');
            }
            abort_if(Section::where('course_id', $course->id)->where('academic_year_id', $year->id)->where('semester_id', $semester->id)->where('section_name', $input['section_name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first(), 409, 'This section name already exists in the term.');
            if (! empty($input['adviser_id'])) {
                $this->professor($input['adviser_id']);
            }
            $input['curriculum_id'] = $curriculum->id;
            $s->fill(collect($input)->only(['course_id', 'curriculum_id', 'academic_year_id', 'semester_id', 'section_name', 'year_level', 'adviser_id', 'capacity', 'status'])->all());
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

    public function curriculum(int $courseId, ?int $curriculumId = null): Curriculum
    {
        $query = Curriculum::where('course_id', $courseId)->where('status', 'active');
        if ($curriculumId) {
            return $query->whereKey($curriculumId)->lockForUpdate()->firstOrFail();
        }
        $curricula = $query->orderBy('id')->lockForUpdate()->limit(2)->get();
        abort_unless($curricula->count() === 1, 422, 'Choose the Section curriculum explicitly.');

        return $curricula->first();
    }

    public function conflicts(SectionSubject $a, SectionSubject $b): bool
    {
        return $a->id !== $b->id && $a->day === $b->day && $a->start_time < $b->end_time && $a->end_time > $b->start_time && ($a->section_id === $b->section_id || ($a->professor_id && $a->professor_id === $b->professor_id) || ($a->room && mb_strtolower(trim($a->room)) === mb_strtolower(trim($b->room))));
    }

    private function meetingConflictTypes(array $a, array $b, int $sectionId, int $professorId, ?int $otherSectionId, ?int $otherProfessorId): array
    {
        if ($a['day'] !== $b['day'] || ! ($a['start_time'] < $b['end_time'] && $a['end_time'] > $b['start_time'])) {
            return [];
        }
        $types = [];
        if ($sectionId === $otherSectionId) {
            $types[] = 'section';
        }
        if ($professorId === $otherProfessorId) {
            $types[] = 'professor';
        }
        if ($a['room'] !== '' && mb_strtolower($a['room']) === mb_strtolower($b['room'])) {
            $types[] = 'room';
        }

        return $types;
    }

    private function meetingData(SectionSubjectMeeting $meeting): array
    {
        return [
            'day' => $meeting->day,
            'start_time' => substr((string) $meeting->start_time, 0, 8),
            'end_time' => substr((string) $meeting->end_time, 0, 8),
            'room' => mb_strtoupper(trim((string) $meeting->room)),
        ];
    }

    private function proposedMeetings(array $input): Collection
    {
        $room = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $input['room'])));

        return collect($input['meetings'] ?? [[
            'day' => $input['day'], 'start_time' => $input['start_time'], 'end_time' => $input['end_time'],
        ]])->map(fn (array $meeting): array => [
            'day' => $meeting['day'],
            'start_time' => substr($meeting['start_time'], 0, 8),
            'end_time' => substr($meeting['end_time'], 0, 8),
            'room' => $room,
        ])->sortBy(fn (array $meeting): int => array_search($meeting['day'], self::DAYS, true))->values();
    }

    private function conflictDetails(Collection $meetings, Collection $others, int $sectionId, int $professorId): array
    {
        $results = $meetings->map(fn (array $meeting): array => [
            'day' => $meeting['day'],
            'start_time' => substr($meeting['start_time'], 0, 5),
            'end_time' => substr($meeting['end_time'], 0, 5),
            'conflicts' => [],
        ])->all();

        foreach ($meetings as $index => $meeting) {
            foreach ($meetings as $otherIndex => $otherMeeting) {
                if ($otherIndex <= $index || $meeting['day'] !== $otherMeeting['day'] || ! ($meeting['start_time'] < $otherMeeting['end_time'] && $meeting['end_time'] > $otherMeeting['start_time'])) {
                    continue;
                }
                $conflict = [
                    'type' => 'batch',
                    'message' => 'This meeting overlaps another proposed meeting in the current schedule.',
                    'existing' => [
                        'day' => $otherMeeting['day'],
                        'start_time' => substr($otherMeeting['start_time'], 0, 5),
                        'end_time' => substr($otherMeeting['end_time'], 0, 5),
                        'room' => $otherMeeting['room'],
                    ],
                ];
                $results[$index]['conflicts'][] = $conflict;
                $results[$otherIndex]['conflicts'][] = array_replace_recursive($conflict, ['existing' => [
                    'day' => $meeting['day'],
                    'start_time' => substr($meeting['start_time'], 0, 5),
                    'end_time' => substr($meeting['end_time'], 0, 5),
                    'room' => $meeting['room'],
                ]]);
            }

            foreach ($others as $other) {
                foreach ($other->meetings as $otherMeeting) {
                    $otherData = $this->meetingData($otherMeeting);
                    foreach ($this->meetingConflictTypes($meeting, $otherData, $sectionId, $professorId, $other->section_id, $other->professor_id) as $type) {
                        $profile = $other->professor?->user?->profile;
                        $results[$index]['conflicts'][] = [
                            'type' => $type,
                            'message' => ucfirst($type).' is already assigned during this time.',
                            'existing' => [
                                'schedule_id' => $other->id,
                                'day' => $otherData['day'],
                                'start_time' => substr($otherData['start_time'], 0, 5),
                                'end_time' => substr($otherData['end_time'], 0, 5),
                                'room' => $otherData['room'],
                                'section' => $other->section ? ['id' => $other->section->id, 'name' => $other->section->section_name] : null,
                                'subject' => $other->subject ? ['id' => $other->subject->id, 'code' => $other->subject->subject_code, 'name' => $other->subject->subject_name] : null,
                                'professor' => $other->professor ? [
                                    'id' => $other->professor->id,
                                    'name' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')) ?: $other->professor->employee_number,
                                ] : null,
                            ],
                        ];
                    }
                }
            }
        }

        return array_map(fn (array $result): array => $result + ['available' => $result['conflicts'] === []], $results);
    }

    public function availability(User $user, array $input, ?int $ignoreId = null): array
    {
        EnrollmentAccess::require($user, true);
        $section = Section::findOrFail($input['section_id']);
        if ($ignoreId) {
            $ignored = SectionSubject::findOrFail($ignoreId);
            abort_unless($ignored->section_id === $section->id && $ignored->subject_id === $input['subject_id'], 422, 'The schedule being edited does not match the selected section and subject.');
        }
        $meetings = $this->proposedMeetings($input);
        $others = SectionSubject::with(['meetings', 'section', 'subject', 'professor.user.profile'])
            ->whereHas('section', fn ($query) => $query->where('academic_year_id', $section->academic_year_id)->where('semester_id', $section->semester_id))
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->orderBy('id')->get();
        $results = $this->conflictDetails($meetings, $others, $section->id, $input['professor_id']);

        return [
            'available' => collect($results)->every(fn (array $meeting): bool => $meeting['available']),
            'meetings' => $results,
        ];
    }

    public function assertFinalSchedules(Collection $selected, int $year, int $semester): void
    {
        $selected->loadMissing('meetings');
        $all = SectionSubject::with(['meetings', 'section', 'subject'])->whereHas('section', fn ($q) => $q->where('academic_year_id', $year)->where('semester_id', $semester))->lockForUpdate()->get();
        foreach ($selected as $a) {
            abort_if($a->meetings->isEmpty(), 422, 'Every selected subject needs at least one schedule meeting.');
            foreach ($a->meetings as $meeting) {
                abort_unless(in_array($meeting->day, self::DAYS, true), 422, 'A schedule has an invalid day.');
                foreach ($all as $b) {
                    foreach ($b->meetings as $other) {
                        if ($meeting->id === $other->id) {
                            continue;
                        }
                        abort_if($this->meetingConflictTypes($this->meetingData($meeting), $this->meetingData($other), $a->section_id, $a->professor_id, $b->section_id, $b->professor_id) !== [], 409, 'Resolve section, Professor or room conflicts before finalization.');
                    }
                }
            }
        }
    }

    public function schedule(User $user, array $input, ?int $id = null, bool $delete = false): ?SectionSubject
    {
        return DB::transaction(function () use ($user, $input, $id, $delete) {
            $actor = EnrollmentAccess::require($user, true, true);
            $assignmentId = $id ? null : ($input['assignment_id'] ?? null);
            $recordId = $id ?? $assignmentId;
            $ref = $recordId ? SectionSubject::findOrFail($recordId) : null;
            $sectionRef = Section::findOrFail($ref?->section_id ?? $input['section_id']);
            $year = AcademicYear::lockForUpdate()->findOrFail($sectionRef->academic_year_id);
            $semester = Semester::lockForUpdate()->findOrFail($sectionRef->semester_id);
            $section = Section::lockForUpdate()->findOrFail($sectionRef->id);
            $s = $recordId ? SectionSubject::lockForUpdate()->findOrFail($recordId) : new SectionSubject;
            $previousProfessorId = $recordId ? $s->professor_id : null;
            if ($id) {
                abort_unless($s->version === $input['version'], 409, 'This schedule changed. Reload before continuing.');
            }
            if ($assignmentId) {
                abort_unless($s->section_id === $input['section_id'] && $s->subject_id === $input['subject_id'], 422, 'The teaching assignment cannot change section or subject.');
                abort_if($s->meetings()->exists() || $s->day || $s->start_time || $s->end_time || $s->room, 409, 'This subject already has a schedule. Reload before continuing.');
            }
            $used = $recordId ? EnrollmentSubject::where('subject_id', $s->subject_id)->whereHas('enrollment', fn ($q) => $q->where('section_id', $section->id))->lockForUpdate()->first() : null;
            if ($delete) {
                abort_if($used, 409, 'A schedule referenced by academic enrollment cannot be deleted.');
                $this->audit->record($actor, 'schedule_deleted', 'schedule', $s->id, ['version' => $s->version]);
                $s->delete();

                return null;
            }
            abort_unless($year->status === 'active' && $semester->status === 'active', 422, 'Choose a Section in an active academic term.');
            if ($recordId) {
                abort_unless($input['section_id'] === $section->id && (! $used || $input['subject_id'] === $s->subject_id), 422, 'Referenced schedules cannot change section or subject.');
            }
            $curriculum = $this->curriculum($section->course_id, $section->curriculum_id);
            $subject = Subject::with('curriculum')->whereKey($input['subject_id'])->lockForUpdate()->firstOrFail();
            abort_unless($subject->status === 'active' && $subject->curriculum_id === $curriculum->id && $subject->semester_id === $section->semester_id && (int) $subject->year_level === (int) $section->year_level && $subject->curriculum?->status === 'active', 422, 'Choose an active subject from this Section curriculum, year level and semester.');
            $this->professor($input['professor_id']);
            $used ??= EnrollmentSubject::where('subject_id', $subject->id)
                ->whereHas('enrollment', fn ($q) => $q->where('section_id', $section->id))
                ->lockForUpdate()
                ->first();
            abort_if(SectionSubject::where('section_id', $section->id)->where('subject_id', $subject->id)->when($recordId, fn ($q) => $q->where('id', '!=', $recordId))->lockForUpdate()->first(), 409, 'This subject already has a meeting in the section.');
            $meetings = $this->proposedMeetings($input);
            $others = SectionSubject::with(['meetings', 'section', 'subject', 'professor.user.profile'])
                ->whereHas('section', fn ($query) => $query->where('academic_year_id', $year->id)->where('semester_id', $semester->id))
                ->when($recordId, fn ($query) => $query->where('id', '!=', $recordId))->orderBy('id')->lockForUpdate()->get();
            $conflict = collect($this->conflictDetails($meetings, $others, $section->id, $input['professor_id']))
                ->first(fn (array $meeting): bool => ! $meeting['available']);
            if ($conflict) {
                $detail = $conflict['conflicts'][0];
                $context = $detail['type'] === 'batch'
                    ? 'current unsaved schedule'
                    : ($detail['existing']['section']['name'] ?? 'Section').' / '.($detail['existing']['subject']['code'] ?? 'Subject');
                abort(409, "{$conflict['day']}, {$conflict['start_time']}–{$conflict['end_time']} conflicts with an existing schedule for this {$detail['type']} ({$context}).");
            }
            $primary = $meetings->first();
            $s->fill(collect($input)->only(['section_id', 'subject_id', 'professor_id'])->all() + $primary);
            if ($recordId) {
                $s->version++;
            }$s->save();
            $s->meetings()->delete();
            $s->meetings()->createMany($meetings->all());
            if ($recordId && (int) $previousProfessorId !== (int) $s->professor_id) {
                $sheet = GradeSheet::where('section_subject_id', $s->id)->lockForUpdate()->first();
                if ($sheet) {
                    abort_unless(in_array($sheet->status, ['draft', 'returned'], true), 409, 'A submitted, approved, or published grade sheet prevents Professor reassignment.');
                    $sheet->forceFill(['professor_id' => $s->professor_id, 'version' => $sheet->version + 1])->save();
                }
            }
            EnrollmentSubject::where('subject_id', $s->subject_id)
                ->whereHas('enrollment', fn ($q) => $q->where('section_id', $section->id)->where('status', 'enrolled'))
                ->update(['professor_id' => $s->professor_id]);
            $metadata = ['version' => $s->version];
            if ($recordId && (int) $previousProfessorId !== (int) $s->professor_id) {
                $metadata += ['previous_professor_id' => $previousProfessorId, 'professor_id' => $s->professor_id];
            }
            $this->audit->record($actor, $id ? 'schedule_updated' : 'schedule_created', 'schedule', $s->id, $metadata);
            $recipients = User::whereHas('student', fn ($q) => $q->whereHas('enrollments', fn ($e) => $e->where('section_id', $section->id)->where('status', 'enrolled')))->get();
            Notification::send($recipients, new EnrollmentNotice('schedule_changed', 'Your section schedule has changed. Review My Schedule.', $section->id));

            return $s->fresh(['meetings', 'section', 'subject', 'professor.user.profile']);
        }, 3);
    }
}
