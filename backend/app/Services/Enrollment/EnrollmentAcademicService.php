<?php

namespace App\Services\Enrollment;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\EnrollmentSubject;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Notifications\EnrollmentNotice;
use Illuminate\Support\Facades\DB;

class EnrollmentAcademicService
{
    public function __construct(private EnrollmentSubjectPolicy $subjects, private EnrollmentEligibilityService $eligibility, private EnrollmentAuditWriter $audit) {}

    public function application(User $user, int $id): EnrollmentApplication
    {
        $staff = in_array($user->fresh('role')->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
        $actor = EnrollmentAccess::require($user, $staff);

        return EnrollmentApplication::when(! $staff, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('user_id', $actor->id)))->findOrFail($id);
    }

    public function occupied(Section $section, bool $lock = true, ?int $except = null): int
    {
        $enrolled = Enrollment::where('section_id', $section->id)->whereIn('status', ['pending', 'enrolled'])->when($lock, fn ($q) => $q->lockForUpdate())->get(['id'])->count();
        $reserved = EnrollmentApplication::where('section_id', $section->id)->where('status', 'approved')->whereNull('enrollment_id')->when($except, fn ($q) => $q->where('id', '!=', $except))->when($lock, fn ($q) => $q->lockForUpdate())->get(['id'])->count();

        return $enrolled + $reserved;
    }

    public function change(User $user, int $id, string $action, array $input): EnrollmentApplication
    {
        return DB::transaction(function () use ($user, $id, $action, $input) {
            $staff = in_array($user->fresh('role')->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
            $actor = EnrollmentAccess::require($user, $staff, true);
            $ref = $this->application($actor, $id);
            $ownerId = Student::whereKey($ref->student_id)->value('user_id');
            $owner = User::lockForUpdate()->findOrFail($ownerId);
            $year = AcademicYear::lockForUpdate()->findOrFail($ref->academic_year_id);
            $semester = Semester::lockForUpdate()->findOrFail($ref->semester_id);
            $student = Student::lockForUpdate()->findOrFail($ref->student_id);
            abort_unless($student->user_id === $owner->id && ($staff || $owner->id === $actor->id), 403, 'Enrollment ownership changed.');
            $a = EnrollmentApplication::lockForUpdate()->findOrFail($id);
            if ($action === 'finalize' && $staff && $a->status === 'enrolled' && $a->enrollment_id) {
                return $a;
            }
            abort_unless($a->status === 'approved', 409, 'Only an approved application can proceed to academic enrollment.');
            abort_unless($a->version === $input['version'], 409, 'This application changed. Reload before continuing.');
            abort_unless($year->status === 'active' && $semester->status === 'active', 422, 'The academic term is not active.');
            $state = $this->eligibility->status($owner, true);
            abort_unless($state['academic_ready'] && $student->course_id === $a->course_id && $student->curriculum_id === $a->curriculum_id && $student->year_level === $a->year_level, 422, 'The Student academic identity needs review before finalization.');
            if ($action === 'subjects') {
                abort_unless($staff || $a->classification === 'irregular', 403, 'Only staff can prepare this subject load.');
                $ids = $a->classification === 'regular' ? $this->subjects->candidates($a, true)->where('year_level', $a->year_level)->pluck('id')->all() : $input['subject_ids'];
                $this->subjects->validate($a, $ids);
                $a->subjects()->sync($ids);
                $a->load_reviewed_at = $staff ? now() : null;
                $a->load_reviewed_by = $staff ? $actor->id : null;
                $event = $staff ? 'subjects_prepared' : 'subjects_updated';
                $message = $staff ? 'Your subject load is ready.' : 'Your subject selection has been saved for staff review.';
            } elseif ($action === 'assign') {
                abort_unless($staff, 403, 'Only Enrollment staff may assign sections.');
                $section = Section::lockForUpdate()->findOrFail($input['section_id']);
                $this->validSection($a, $section);
                abort_unless($this->occupied($section, true, $a->id) < $section->capacity, 409, 'The selected section is full.');
                $a->section_id = $section->id;
                $event = 'section_assigned';
                $message = 'Your enrollment section has been assigned.';
            } else {
                abort_unless($action === 'finalize' && $staff, 403, 'Only Enrollment staff may finalize enrollment.');
                abort_unless($a->load_reviewed_at && $a->load_reviewed_by && $a->section_id, 422, 'A staff-reviewed subject load and section are required.');
                $section = Section::lockForUpdate()->findOrFail($a->section_id);
                $this->validSection($a, $section);
                abort_unless($this->occupied($section, true, $a->id) < $section->capacity, 409, 'The section is full.');
                abort_if(Enrollment::where('student_id', $a->student_id)->where('academic_year_id', $a->academic_year_id)->where('semester_id', $a->semester_id)->lockForUpdate()->first(), 409, 'An academic enrollment already exists for this Student and term.');
                $ids = DB::table('enrollment_application_subjects')->where('application_id', $a->id)->lockForUpdate()->pluck('subject_id')->all();
                $load = $this->subjects->validate($a, $ids);
                $schedules = SectionSubject::where('section_id', $section->id)->whereIn('subject_id', $ids)->lockForUpdate()->get()->keyBy('subject_id');
                abort_unless($schedules->count() === $load->count(), 422, 'Every selected subject needs a schedule in the assigned section.');
                $profIds = $schedules->pluck('professor_id')->filter()->unique();
                $professors = Professor::whereIn('id', $profIds)->where('status', 'active')->lockForUpdate()->get();
                $professorRole = Role::where('role_name', Role::PROFESSOR)->value('id');
                $activeUsers = User::whereIn('id', $professors->pluck('user_id'))->where('status', 'active')->where('role_id', $professorRole)->lockForUpdate()->pluck('id')->all();
                $activeProf = $professors->whereIn('user_id', $activeUsers)->pluck('id')->all();
                foreach ($schedules as $s) {
                    abort_unless($s->day && $s->start_time && $s->end_time && $s->start_time < $s->end_time && $s->room && in_array($s->professor_id, $activeProf), 422, 'Every subject needs a complete schedule and active Professor.');
                }
                app(EnrollmentSchedulingService::class)->assertFinalSchedules($schedules, $a->academic_year_id, $a->semester_id);
                $e = Enrollment::create(['student_id' => $a->student_id, 'section_id' => $section->id, 'academic_year_id' => $a->academic_year_id, 'semester_id' => $a->semester_id, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']);
                $rows = $load->map(fn ($s) => ['enrollment_id' => $e->id, 'subject_id' => $s->id, 'professor_id' => $schedules[$s->id]->professor_id, 'subject_status' => 'enrolled', 'remarks' => 'In Progress', 'created_at' => now(), 'updated_at' => now()])->all();
                EnrollmentSubject::insert($rows);
                $a->enrollment_id = $e->id;
                $a->status = 'enrolled';
                $a->finalized_at = now();
                $event = 'finalized';
                $message = 'Your academic enrollment is complete. Your COR and schedule are available.';
            }
            $a->version++;
            $a->save();
            $this->audit->record($actor, $event, 'application', $a->id, ['status' => $a->status, 'version' => $a->version]);
            $owner->notify(new EnrollmentNotice($event, $message, $a->id));

            return $a;
        }, 3);
    }

    private function validSection(EnrollmentApplication $a, Section $s): void
    {
        abort_unless($s->status === 'open' && $s->course_id === $a->course_id && $s->year_level === $a->year_level && $s->academic_year_id === $a->academic_year_id && $s->semester_id === $a->semester_id, 422, 'The section must match the Course, year level and academic term.');
    }
}
