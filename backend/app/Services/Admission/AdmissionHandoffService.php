<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionDecision;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AdmissionHandoffService
{
    public function context(User $user, bool $lock = false): array
    {
        $empty = ['completed' => false, 'admitted_guest' => false, 'applicant' => null,
            'result' => null, 'course' => null, 'curriculum' => null, 'completed_at' => null];
        if (! Schema::hasTable('admission_applicants') || ! Schema::hasTable('admission_exam_sessions')
            || ! Schema::hasTable('admission_exam_results') || ! Schema::hasTable('admission_decisions')) {
            return $empty;
        }

        $actor = User::with('role')->whereKey($user->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $actor || $actor->status !== 'active') {
            return $empty;
        }
        $applicant = AdmissionApplicant::where('user_id', $actor->id)->latest('id')->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $applicant) {
            return $empty;
        }

        if ($applicant->status === AdmissionApplicant::CONVERTED) {
            $legacyStudent = $actor->student()->when($lock, fn ($query) => $query->lockForUpdate())->first();
            $completed = $legacyStudent && $applicant->converted_student_id === $legacyStudent->id && $applicant->converted_at;

            return $completed ? array_replace($empty, ['completed' => true, 'applicant' => $applicant,
                'completed_at' => $applicant->converted_at]) : $empty;
        }
        if ($applicant->status !== AdmissionApplicant::ACCEPTED || ! $applicant->accepted_at) {
            return $empty;
        }

        $session = AdmissionExamSession::where('applicant_id', $applicant->id)->orderByDesc('attempt_number')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $result = $session ? AdmissionExamResult::where('session_id', $session->id)->when($lock, fn ($query) => $query->lockForUpdate())->first() : null;
        $decision = AdmissionDecision::where('applicant_id', $applicant->id)->where('action', 'application_accepted')->latest('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $course = Course::whereKey($applicant->accepted_course_id)->where('status', 'active')->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $curriculum = Curriculum::whereKey($applicant->accepted_curriculum_id)->where('status', 'active')
            ->where('course_id', $applicant->accepted_course_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $completed = $session?->status === 'finalized' && $result?->official_status === 'published' && $result?->published_at
            && $result->outcome() === 'PASSED' && $decision
            && $decision->result_id === $result->id && $decision->result_version === $result->version
            && $decision->application_version === $applicant->version
            && (int) ($decision->after['accepted_course_id'] ?? 0) === (int) $applicant->accepted_course_id
            && (int) ($decision->after['accepted_curriculum_id'] ?? 0) === (int) $applicant->accepted_curriculum_id
            && $course && $curriculum;
        if (! $completed) {
            return $empty;
        }

        return ['completed' => true,
            'admitted_guest' => $actor->role?->role_name === Role::GUEST && ! $actor->student()->exists(),
            'applicant' => $applicant, 'result' => $result, 'course' => $course, 'curriculum' => $curriculum,
            'completed_at' => $applicant->accepted_at];
    }

    public function isAdmittedGuest(User $user, bool $lock = false): bool
    {
        return $this->context($user, $lock)['admitted_guest'];
    }
}
