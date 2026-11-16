<?php

namespace App\Services\Enrollment;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\EnrollmentSubject;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\EnrollmentNotice;
use App\Services\Admission\AdmissionHandoffService;
use App\Services\StudentRecords\StudentRecordAuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EnrollmentAcademicService
{
    public function __construct(
        private EnrollmentSubjectPolicy $subjects,
        private EnrollmentEligibilityService $eligibility,
        private EnrollmentAuditWriter $audit,
        private AdmissionHandoffService $handoff,
        private StudentNumberAllocator $numbers,
        private EnrollmentDocumentHandoffService $documentHandoff,
        private EnrollmentYearLevelReview $yearLevels,
        private StudentRecordAuditWriter $studentAudit,
        private EnrollmentTeachingPlacementService $placements,
    ) {}

    public function application(User $user, int $id): EnrollmentApplication
    {
        $staff = in_array($user->fresh('role')->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
        $actor = EnrollmentAccess::require($user, $staff);

        return EnrollmentApplication::when(! $staff, fn ($q) => $q->ownedBy($actor))->findOrFail($id);
    }

    public function occupied(Section $section, bool $lock = true, ?int $except = null): int
    {
        $enrolled = Enrollment::where('section_id', $section->id)->whereIn('status', ['pending', 'enrolled'])->when($lock, fn ($q) => $q->lockForUpdate())->get(['id'])->count();
        $reserved = EnrollmentApplication::where('section_id', $section->id)->where('status', 'approved')->whereNull('enrollment_id')->when($except, fn ($q) => $q->where('id', '!=', $except))->when($lock, fn ($q) => $q->lockForUpdate())->get(['id'])->count();
        $visiting = DB::table('enrollment_subjects as es')
            ->join('section_subjects as assignment', 'assignment.id', '=', 'es.section_subject_id')
            ->join('enrollments as enrollment', 'enrollment.id', '=', 'es.enrollment_id')
            ->where('assignment.section_id', $section->id)
            ->where('enrollment.section_id', '!=', $section->id)
            ->whereIn('enrollment.status', ['pending', 'enrolled'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->distinct()->count('enrollment.student_id');
        $visitingReserved = DB::table('enrollment_application_subjects as selected')
            ->join('section_subjects as assignment', 'assignment.id', '=', 'selected.section_subject_id')
            ->join('enrollment_applications as application', 'application.id', '=', 'selected.application_id')
            ->where('assignment.section_id', $section->id)
            ->where(fn ($query) => $query->whereNull('application.section_id')->orWhere('application.section_id', '!=', $section->id))
            ->where('application.status', 'approved')->whereNull('application.enrollment_id')
            ->when($except, fn ($query) => $query->where('application.id', '!=', $except))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->distinct()->count('application.id');

        return $enrolled + $reserved + $visiting + $visitingReserved;
    }

    public function change(User $user, int $id, string $action, array $input): EnrollmentApplication
    {
        return DB::transaction(function () use ($user, $id, $action, $input) {
            $staff = in_array($user->fresh('role')->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
            $actor = EnrollmentAccess::require($user, $staff, true);
            $ref = $this->application($actor, $id);
            $owner = User::with('role')->whereKey($ref->owner_user_id)->lockForUpdate()->firstOrFail();
            $profile = UserProfile::where('user_id', $owner->id)->lockForUpdate()->first();
            $owner->setRelation('profile', $profile);
            $year = AcademicYear::lockForUpdate()->findOrFail($ref->academic_year_id);
            $semester = Semester::lockForUpdate()->findOrFail($ref->semester_id);
            $student = $ref->student_id ? Student::lockForUpdate()->findOrFail($ref->student_id) : null;
            abort_unless((! $student || $student->user_id === $owner->id) && ($staff || $owner->id === $actor->id), 403, 'Enrollment ownership changed.');
            $a = EnrollmentApplication::lockForUpdate()->findOrFail($id);
            if ($action === 'finalize' && $staff && $a->status === 'enrolled' && $a->enrollment_id) {
                $completedStudent = Student::lockForUpdate()->findOrFail($a->student_id);
                $this->documentHandoff->link($actor, $a, $completedStudent);

                return $a;
            }
            abort_unless($a->status === 'approved', 409, 'Only an approved application can proceed to academic enrollment.');
            abort_unless($a->version === $input['version'], 409, 'This application changed. Reload before continuing.');
            abort_unless($year->status === 'active' && $semester->status === 'active', 422, 'The academic term is not active.');
            if ($student) {
                $state = $this->eligibility->status($owner, true);
                abort_unless($state['academic_ready'] && $student->course_id === $a->course_id && $student->curriculum_id === $a->curriculum_id, 422, 'The Student academic identity needs review before finalization.');
                $this->yearLevels->assertEligible($a, true);
            } else {
                $admission = $this->handoff->context($owner, true);
                abort_unless($a->admission_applicant_id && $admission['admitted_guest']
                    && $admission['applicant']->id === $a->admission_applicant_id
                    && $admission['course']->id === $a->course_id
                    && $admission['curriculum']->id === $a->curriculum_id,
                    409, 'The Admission handoff is no longer valid for finalization.');
            }
            if ($action === 'subjects') {
                abort_unless($staff || $a->classification === 'irregular', 403, 'Only staff can prepare this subject load.');
                $ids = $a->classification === 'regular' ? $this->subjects->candidates($a, true)->where('year_level', $a->year_level)->pluck('id')->all() : $input['subject_ids'];
                $load = $this->subjects->validate($a, $ids);
                $requested = $staff ? ($input['teaching_assignments'] ?? []) : [];
                $crossYear = $load->filter(fn ($subject) => (int) $subject->year_level !== (int) $a->year_level);
                $assignmentIds = $crossYear->map(fn ($subject) => (int) ($requested[$subject->id] ?? 0))->filter()->values()->all();
                $assignments = SectionSubject::with('section')->whereIn('id', $assignmentIds)->lockForUpdate()->get()->keyBy('id');
                $pivot = [];
                foreach ($load as $subject) {
                    $assignmentId = (int) ($requested[$subject->id] ?? 0);
                    if ((int) $subject->year_level !== (int) $a->year_level && $staff) {
                        $assignment = $assignments->get($assignmentId);
                        abort_unless($assignment && $this->placements->valid($a, $subject, $assignment), 422,
                            "Choose an active teaching section for {$subject->subject_code} before reviewing this cross-year load.");
                    } else {
                        abort_if($assignmentId, 422, 'Only cross-year subjects may use a separate teaching section.');
                    }
                    $pivot[$subject->id] = ['section_subject_id' => $assignmentId ?: null];
                }
                $a->subjects()->sync($pivot);
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
                if ($student) {
                    abort_if(Enrollment::where('student_id', $student->id)->where('academic_year_id', $a->academic_year_id)->where('semester_id', $a->semester_id)->lockForUpdate()->first(), 409, 'An academic enrollment already exists for this Student and term.');
                }
                $ids = DB::table('enrollment_application_subjects')->where('application_id', $a->id)->lockForUpdate()->pluck('subject_id')->all();
                $load = $this->subjects->validate($a, $ids);
                $placements = DB::table('enrollment_application_subjects')
                    ->where('application_id', $a->id)->lockForUpdate()->pluck('section_subject_id', 'subject_id');
                $crossYearIds = $load->filter(fn ($subject) => (int) $subject->year_level !== (int) $section->year_level)
                    ->map(fn ($subject) => (int) ($placements[$subject->id] ?? 0))->filter()->values()->all();
                $crossYearAssignments = SectionSubject::with(['section', 'meetings'])->whereIn('id', $crossYearIds)
                    ->lockForUpdate()->get()->keyBy('id');
                $externalSections = collect();
                foreach ($load as $subject) {
                    if ((int) $subject->year_level === (int) $section->year_level) {
                        continue;
                    }
                    $assignment = $crossYearAssignments->get((int) ($placements[$subject->id] ?? 0));
                    abort_unless($assignment && $this->placements->valid($a, $subject, $assignment), 422,
                        "The teaching section for {$subject->subject_code} is unavailable. Review the subject load before finalizing.");
                    $externalSections->put($assignment->section_id, $assignment->section);
                }
                foreach ($externalSections as $teachingSection) {
                    abort_unless($this->occupied($teachingSection, true, $a->id) < $teachingSection->capacity, 409,
                        "The teaching section {$teachingSection->section_name} is full.");
                }
                // Enrollment and timetable preparation are independent workflows. An
                // existing teaching assignment may contribute its Professor, but neither
                // that assignment nor its meetings are prerequisites for finalization.
                $assignments = SectionSubject::with('meetings')->where('section_id', $section->id)
                    ->whereIn('subject_id', $ids)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('subject_id');
                $this->placements->assertNoTimetableConflict($load->map(fn ($subject) =>
                    (int) $subject->year_level === (int) $section->year_level
                        ? $assignments->get($subject->id)
                        : $crossYearAssignments->get((int) ($placements[$subject->id] ?? 0))));
                if (! $student) {
                    abort_unless($owner->role?->role_name === Role::GUEST && $owner->profile, 409, 'The admitted Guest identity needs review.');
                    abort_if(Student::where('user_id', $owner->id)->orWhere('user_profile_id', $owner->profile->id)->lockForUpdate()->exists(), 409, 'A Student identity already exists for this account.');
                    $studentRole = Role::where('role_name', Role::STUDENT)->lockForUpdate()->firstOrFail();
                    $student = Student::create([
                        'user_id' => $owner->id, 'user_profile_id' => $owner->profile->id,
                        'course_id' => $a->course_id, 'curriculum_id' => $a->curriculum_id,
                        'student_number' => $this->numbers->next(CarbonImmutable::parse($year->start_date)->year),
                        'admission_date' => now()->toDateString(), 'year_level' => $section->year_level,
                        'student_status' => $a->classification === 'irregular' ? 'irregular' : 'regular',
                    ]);
                    $a->student_id = $student->id;
                    $owner->role_id = $studentRole->id;
                    $owner->save();
                    $owner->tokens()->delete();
                    $owner->loginOtpChallenges()
                        ->whereNull('consumed_at')
                        ->whereNull('superseded_at')
                        ->update(['superseded_at' => now()]);
                }
                $this->documentHandoff->link($actor, $a, $student);
                $e = Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id, 'academic_year_id' => $a->academic_year_id, 'semester_id' => $a->semester_id, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']);
                $rows = $load->map(function ($subject) use ($e, $section, $assignments, $crossYearAssignments, $placements): array {
                    $assignment = (int) $subject->year_level === (int) $section->year_level
                        ? $assignments->get($subject->id)
                        : $crossYearAssignments->get((int) ($placements[$subject->id] ?? 0));

                    return ['enrollment_id' => $e->id, 'subject_id' => $subject->id,
                        'section_subject_id' => $assignment?->id, 'professor_id' => $assignment?->professor_id,
                        'subject_status' => 'enrolled', 'remarks' => 'In Progress', 'created_at' => now(), 'updated_at' => now()];
                })->all();
                EnrollmentSubject::insert($rows);
                if ($student->year_level < $a->year_level) {
                    $previousYear = $student->year_level;
                    $student->year_level = $a->year_level;
                    $student->save();
                    $this->studentAudit->record($actor, $student->id, 'student_year_level_promoted', [
                        'previous_year_level' => $previousYear,
                        'year_level' => $student->year_level,
                        'application_id' => $a->id,
                        'enrollment_id' => $e->id,
                    ]);
                }
                $a->enrollment_id = $e->id;
                $a->status = 'enrolled';
                $a->finalized_at = now();
                $event = 'finalized';
                $message = 'Your academic enrollment is complete. Your COR is available; class times will appear when Scheduling finalizes them.';
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
