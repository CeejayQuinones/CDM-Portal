<?php

namespace App\Services\Enrollment;

use App\Enums\Enrollment\Classification;
use App\Models\AcademicYear;
use App\Models\Admission\AdmissionDecision;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;

class EnrollmentEligibilityService
{
    public function __construct(private EnrollmentPeriodResolver $periods) {}

    public function status(User $user): array
    {
        $actor = $user->fresh(['role', 'student.userProfile', 'student.course', 'student.curriculum']);
        abort_unless($actor && $actor->status === 'active' && $actor->role?->role_name === Role::STUDENT, 403, 'Enrollment status is available only to active Students.');
        $student = $actor->student;
        $reason = $this->academicReason($actor, $student);
        $state = ['academic_ready' => $reason === null, 'eligible' => false, 'reason' => $reason,
            'applications_enabled' => false, 'period' => null, 'student' => null, 'enrollments' => [],
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
            || ! AcademicYear::whereKey($window->academicYearId)->where('status', 'active')->exists()
            || ! Semester::whereKey($window->semesterId)->where('status', 'active')->exists()) {
            return array_replace($state, ['reason' => 'term_unavailable']);
        }
        $state['period'] = ['id' => $window->periodId, 'academic_year_id' => $window->academicYearId,
            'semester_id' => $window->semesterId, 'opens_at' => $window->opensAt->toISOString(), 'closes_at' => $window->closesAt->toISOString()];
        if (now()->lt($window->opensAt) || now()->gte($window->closesAt)) {
            return array_replace($state, ['reason' => 'enrollment_period_closed']);
        }
        // The database unique key includes every status. Cancelled rows cannot be bypassed.
        if ($student->enrollments()->where('academic_year_id', $window->academicYearId)->where('semester_id', $window->semesterId)->exists()) {
            return array_replace($state, ['reason' => 'term_enrollment_exists']);
        }

        // Eligibility is advisory; application writes remain unavailable in foundation.
        return array_replace($state, ['eligible' => true, 'reason' => null]);
    }

    private function academicReason(User $actor, ?Student $student): ?string
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
        if ($actor->admissionApplications()->exists()) {
            $converted = $actor->admissionApplications()->where('status', 'converted')
                ->where('converted_student_id', $student->id)->whereNotNull('converted_at')->latest('id')->first();
            $decision = $converted ? AdmissionDecision::where('applicant_id', $converted->id)->where('action', 'student_converted')->latest('id')->first() : null;
            if (! $decision || ($decision->after['student_id'] ?? null) !== $student->id) {
                return 'admission_conversion_incomplete';
            }
        }

        return null;
    }
}
