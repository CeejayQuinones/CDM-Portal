<?php

namespace App\Services\Enrollment;

use App\Enums\Enrollment\Classification;
use App\Models\AcademicYear;
use App\Models\Admission\AdmissionDecision;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Admission\AdmissionHandoffService;

class EnrollmentEligibilityService
{
    public function __construct(private EnrollmentPeriodResolver $periods, private AdmissionHandoffService $handoff) {}

    public function status(User $user, bool $lock = false): array
    {
        $actor = User::with('role')->whereKey($user->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($actor && $actor->status === 'active', 403, 'Enrollment status is unavailable for this account.');
        if ($actor->role?->role_name === Role::GUEST) {
            $admission = $this->handoff->context($actor, $lock);
            abort_unless($admission['admitted_guest'], 403, 'Enrollment access requires completed Admission.');
            $profile = $actor->profile()->when($lock, fn ($query) => $query->lockForUpdate())->first();
            $periods = EnrollmentPeriod::where('academic_year_id', $admission['applicant']->cycle?->academic_year_id)
                ->where('enabled', true)->where('closes_at', '>', now())
                ->whereHas('academicYear', fn ($query) => $query->where('status', 'active'))
                ->whereHas('semester', fn ($query) => $query->where('status', 'active'))
                ->orderBy('opens_at')->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $open = $periods->filter(fn ($period) => $period->state === 'open');
            $period = $open->count() === 1 ? $open->first() : ($open->isEmpty() ? $periods->first() : null);
            $periodState = $period?->state;
            $reason = ! $period ? 'enrollment_period_unavailable' : ($periodState === 'open' ? null : 'enrollment_period_closed');
            $current = $period ? EnrollmentApplication::ownedBy($actor)->where('academic_year_id', $period->academic_year_id)
                ->where('semester_id', $period->semester_id)->first(['id', 'period_id', 'status']) : null;

            return [
                'academic_ready' => false, 'eligible' => $reason === null, 'reason' => $reason ?? 'student_identity_pending',
                'applications_enabled' => $reason === null, 'period' => $period ? [
                    'id' => $period->id, 'academic_year_id' => $period->academic_year_id,
                    'semester_id' => $period->semester_id, 'academic_year' => $period->academicYear?->school_year,
                    'semester' => $period->semester?->semester_name, 'state' => $periodState,
                    'opens_at' => $period->opens_at->toISOString(), 'closes_at' => $period->closes_at->toISOString(),
                ] : null, 'current_application' => $current, 'student' => null, 'enrollments' => [],
                'classifications' => array_column(Classification::cases(), 'value'), 'admitted_guest' => true,
                'admission' => [
                    'applicant_id' => $admission['applicant']->id,
                    'applicant_number' => $admission['applicant']->applicant_number,
                    'name' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')),
                    'course' => $admission['course']->only(['id', 'course_code', 'course_name']),
                    'curriculum' => $admission['curriculum']->only(['id', 'curriculum_code', 'curriculum_name']),
                    'completed_at' => $admission['completed_at']?->toIso8601String(),
                ],
            ];
        }
        abort_unless($actor->role?->role_name === Role::STUDENT, 403, 'Enrollment status is available only to admitted Guests and active Students.');
        $student = $actor->student()->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($student) {
            foreach (['userProfile', 'course', 'curriculum'] as $relation) {
                $student->setRelation($relation, $student->{$relation}()->when($lock, fn ($q) => $q->lockForUpdate())->first());
            }
        }
        $reason = $this->academicReason($actor, $student, $lock);
        $state = ['academic_ready' => $reason === null, 'eligible' => false, 'reason' => $reason, 'admitted_guest' => false,
            'applications_enabled' => true, 'period' => null, 'student' => null, 'enrollments' => [],
            'classifications' => array_column(Classification::cases(), 'value')];
        if ($reason !== null) {
            return $state;
        }
        $profile = $student->userProfile;
        $state['student'] = [
            'id' => $student->id, 'user_id' => $actor->id, 'profile_id' => $profile->id,
            'name' => trim($profile->first_name.' '.$profile->last_name),
            'email' => $profile->email, 'contact_number' => $profile->contact_number,
            'student_number' => $student->student_number, 'year_level' => $student->year_level,
            'student_status' => $student->student_status,
            'course' => $student->course->only(['id', 'course_code', 'course_name']),
            'curriculum' => $student->curriculum->only(['id', 'curriculum_code', 'curriculum_name']),
        ];
        $state['enrollments'] = $student->enrollments()->with(['academicYear', 'semester'])->latest('enrollment_date')->latest('id')->limit(10)->get()
            ->map(fn ($e) => ['id' => $e->id, 'status' => $e->status, 'enrollment_date' => $e->enrollment_date,
                'academic_year' => $e->academicYear?->school_year, 'semester' => $e->semester?->semester_name])->all();
        $window = $this->periods->forStudent($student);
        if (! $window) {
            return array_replace($state, ['reason' => 'enrollment_period_unavailable']);
        }
        if ($window->periodId < 1 || $window->opensAt->gte($window->closesAt)
            || ! AcademicYear::whereKey($window->academicYearId)->where('status', 'active')->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])
            || ! Semester::whereKey($window->semesterId)->where('status', 'active')->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) {
            return array_replace($state, ['reason' => 'term_unavailable']);
        }
        $state['period'] = ['id' => $window->periodId, 'academic_year_id' => $window->academicYearId,
            'semester_id' => $window->semesterId, 'academic_year' => AcademicYear::find($window->academicYearId)?->school_year, 'semester' => Semester::find($window->semesterId)?->semester_name, 'state' => ! $window->enabled || now()->gte($window->closesAt) ? 'closed' : (now()->lt($window->opensAt) ? 'upcoming' : 'open'), 'opens_at' => $window->opensAt->toISOString(), 'closes_at' => $window->closesAt->toISOString()];
        $state['current_application'] = EnrollmentApplication::where('student_id', $student->id)->where('academic_year_id', $window->academicYearId)->where('semester_id', $window->semesterId)->first(['id', 'period_id', 'status']);
        if (! $window->enabled || now()->lt($window->opensAt) || now()->gte($window->closesAt)) {
            return array_replace($state, ['reason' => 'enrollment_period_closed']);
        }
        // The database unique key includes every status. Cancelled rows cannot be bypassed.
        if ($student->enrollments()->where('academic_year_id', $window->academicYearId)->where('semester_id', $window->semesterId)->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) {
            return array_replace($state, ['reason' => 'term_enrollment_exists']);
        }

        // Eligibility is advisory; writers revalidate within a transaction.
        return array_replace($state, ['eligible' => true, 'reason' => null]);
    }

    private function academicReason(User $actor, ?Student $student, bool $lock = false): ?string
    {
        if (! $student) {
            return 'student_record_required';
        }
        if (! $student->userProfile || $student->userProfile->user_id !== $actor->id) {
            return 'profile_reconciliation_required';
        }
        if (! trim($student->student_number ?? '') || ! $student->admission_date
            || $student->admission_date->isFuture() || $student->year_level < 1) {
            return 'academic_identity_incomplete';
        }
        if (! in_array($student->student_status, ['regular', 'irregular'], true)) {
            return 'academic_review_required';
        }
        if ($student->course?->status !== 'active') {
            return 'course_inactive';
        }
        if ($student->curriculum?->status !== 'active'
            || $student->curriculum->course_id !== $student->course_id
            || $student->curriculum->effective_year > now()->year) {
            return 'curriculum_invalid';
        }
        if ($actor->admissionApplications()->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) {
            $enrollmentHandoff = EnrollmentApplication::where('owner_user_id', $actor->id)
                ->where('student_id', $student->id)
                ->whereNotNull('admission_applicant_id')
                ->whereNotNull('enrollment_id')
                ->whereNotNull('finalized_at')
                ->where('status', 'enrolled')
                ->when($lock, fn ($q) => $q->lockForUpdate())
                ->exists();
            if ($enrollmentHandoff) {
                return null;
            }
            $converted = $actor->admissionApplications()->where('status', 'converted')
                ->where('converted_student_id', $student->id)->whereNotNull('converted_at')->latest('id')->when($lock, fn ($q) => $q->lockForUpdate())->first();
            $decision = $converted ? AdmissionDecision::where('applicant_id', $converted->id)->where('action', 'student_converted')->latest('id')->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
            if (! $decision || ($decision->after['student_id'] ?? null) !== $student->id) {
                return 'admission_conversion_incomplete';
            }
        }

        return null;
    }
}
