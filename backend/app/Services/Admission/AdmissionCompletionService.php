<?php

namespace App\Services\Admission;

use App\Exceptions\AdmissionConflictException;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionDecision;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdmissionCompletionService
{
    public function __construct(private AdmissionAuditWriter $audit) {}

    public function inspect(User $actor, int $id): array
    {
        return $this->locked($actor, $id, function ($actor, $user, $applicant) {
            $state = $this->state($user, $applicant);
            $student = $applicant->converted_student_id ? Student::find($applicant->converted_student_id) : null;
            $completed = $this->acceptanceMatches($applicant, $state['result']);

            return [
                'id' => $applicant->id, 'version' => $applicant->version, 'status' => $applicant->status,
                'applicant_number' => $applicant->applicant_number,
                'name' => trim($state['profile']?->first_name.' '.$state['profile']?->last_name),
                'can_accept' => $state['reason'] === null && ! $completed,
                'can_complete' => $state['reason'] === null && ! $completed,
                'completed' => $completed || $applicant->status === AdmissionApplicant::CONVERTED,
                'reason' => $state['reason'], 'reason_code' => $state['reason_code'],
                'result_id' => $state['result']?->id, 'result_version' => $state['result']?->version,
                'course_id' => $applicant->accepted_course_id, 'curriculum_id' => $applicant->accepted_curriculum_id,
                'student' => $student ? $this->studentPayload($student) : null,
                'courses' => Course::where('status', 'active')->orderBy('course_code')->get(['id', 'course_code', 'course_name']),
                'curriculums' => Curriculum::where('status', 'active')->orderBy('effective_year')->get(['id', 'course_id', 'curriculum_code', 'curriculum_name', 'effective_year']),
            ];
        });
    }

    public function accept(User $actor, int $id, array $input): array
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:1', 'result_id' => 'required|integer', 'result_version' => 'required|integer|min:1',
            'course_id' => 'required|integer', 'curriculum_id' => 'required|integer', 'confirmed' => 'required|accepted',
        ])->validate();

        return $this->locked($actor, $id, function ($actor, $user, $applicant) use ($data) {
            $state = $this->state($user, $applicant);
            if ($this->acceptanceMatches($applicant, $state['result'])) {
                return ['accepted' => true, 'already_completed' => true, 'version' => $applicant->version];
            }
            if ($state['reason'] !== null) {
                $this->conflict($state['reason_code'], $state['reason'], $applicant, $state['result']);
            }
            $this->checkVersions($applicant, $state['result'], $data);
            $this->academics($data['course_id'], $data['curriculum_id']);
            $before = $applicant->only(['status', 'version', 'accepted_course_id', 'accepted_curriculum_id']);
            $applicant->forceFill(['status' => AdmissionApplicant::ACCEPTED, 'accepted_at' => now(),
                'accepted_course_id' => $data['course_id'], 'accepted_curriculum_id' => $data['curriculum_id'], 'version' => $applicant->version + 1])->save();
            $this->decision($actor, $applicant, $state['result'], 'application_accepted', $before,
                $applicant->only(['status', 'version', 'accepted_course_id', 'accepted_curriculum_id']));

            return ['accepted' => true, 'version' => $applicant->version];
        });
    }

    private function locked(User $actor, int $id, callable $action): array
    {
        AdmissionAccess::require($actor, 'review');
        $ownerId = AdmissionApplicant::findOrFail($id)->user_id;

        return DB::transaction(function () use ($actor, $id, $ownerId, $action) {
            $user = User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $applicant = AdmissionApplicant::whereKey($id)->lockForUpdate()->firstOrFail();

            return $action(AdmissionAccess::require($actor, 'review'), $user, $applicant);
        }, 3);
    }

    private function state(User $user, AdmissionApplicant $applicant): array
    {
        $cycle = AdmissionCycle::whereKey($applicant->cycle_id)->lockForUpdate()->first();
        $profile = UserProfile::where('user_id', $user->id)->lockForUpdate()->first();
        $applications = AdmissionApplicant::where('user_id', $user->id)->orderByDesc('id')->lockForUpdate()->get();
        $sessions = AdmissionExamSession::where('applicant_id', $applicant->id)->orderByDesc('attempt_number')->lockForUpdate()->get();
        $latest = $sessions->first();
        $result = $latest ? AdmissionExamResult::where('session_id', $latest->id)->lockForUpdate()->first() : null;
        [$reason_code, $reason] = match (true) {
            $applicant->status === AdmissionApplicant::CONVERTED => ['LEGACY_STUDENT_EXISTS', 'This applicant already has a legacy Student identity.'],
            $applications->first()?->id !== $applicant->id => ['NOT_CURRENT_APPLICATION', 'Only the current Admission application can be completed.'],
            ! in_array($applicant->status, AdmissionApplicant::ACTIVE_STATUSES, true) => ['APPLICATION_NOT_OPEN', 'This application is not open for acceptance.'],
            $user->status !== 'active' || $user->role->role_name !== Role::GUEST => ['GUEST_ACCOUNT_REQUIRED', 'An active Guest account is required.'],
            ! $profile || ! trim($profile->first_name) || ! trim($profile->last_name) => ['PROFILE_INCOMPLETE', 'Complete the applicant profile first.'],
            Student::where('user_id', $user->id)->orWhere('user_profile_id', $profile?->id)->lockForUpdate()->exists() => ['ACADEMIC_IDENTITY_CONFLICT', 'An existing academic identity needs Registrar reconciliation.'],
            ! $cycle || ! in_array($cycle->status, ['open', 'closed'], true) || now()->lt($cycle->opens_at) || now()->gt($cycle->confirmation_closes_at) => ['CONFIRMATION_WINDOW_CLOSED', 'The cycle is outside its final confirmation window.'],
            $sessions->contains('status', 'active') => ['RETAKE_IN_PROGRESS', 'An exam or retake is still in progress.'],
            ! $latest || $latest->applicant_id !== $applicant->id || $latest->status !== 'finalized' => ['EXAM_NOT_FINALIZED', 'A completed exam for this application is required.'],
            ! $result || $result->official_status !== 'published' || ! $result->published_at => ['RESULT_NOT_PUBLISHED', 'The latest result must be published.'],
            $result->outcome() !== 'PASSED' => ['RESULT_NOT_PASSED', 'The latest published result must be Passed; a pending retake cannot complete Admission.'],
            default => [null, null],
        };

        return compact('reason', 'reason_code', 'profile', 'result');
    }

    private function acceptanceMatches(AdmissionApplicant $applicant, ?AdmissionExamResult $result): bool
    {
        if ($applicant->status !== AdmissionApplicant::ACCEPTED || ! $result) {
            return false;
        }
        $decision = AdmissionDecision::where('applicant_id', $applicant->id)->where('action', 'application_accepted')->latest('id')->lockForUpdate()->first();
        $course = Course::whereKey($applicant->accepted_course_id)->where('status', 'active')->lockForUpdate()->first();
        $curriculum = Curriculum::whereKey($applicant->accepted_curriculum_id)->where('course_id', $applicant->accepted_course_id)->where('status', 'active')->lockForUpdate()->first();

        return $decision && $course && $curriculum && $curriculum->effective_year <= now()->year
            && $decision->result_id === $result->id && $decision->result_version === $result->version
            && $decision->application_version === $applicant->version
            && ($decision->after['accepted_course_id'] ?? null) === $applicant->accepted_course_id
            && ($decision->after['accepted_curriculum_id'] ?? null) === $applicant->accepted_curriculum_id;
    }

    private function academics(int $courseId, int $curriculumId, ?string $date = null): void
    {
        $course = Course::whereKey($courseId)->where('status', 'active')->lockForUpdate()->first();
        $curriculum = Curriculum::whereKey($curriculumId)->where('course_id', $courseId)->where('status', 'active')->lockForUpdate()->first();
        abort_unless($course && $curriculum && $curriculum->effective_year <= (int) substr($date ?? now()->toDateString(), 0, 4), 422, 'Select an active course and matching curriculum effective on the admission date.');
    }

    private function checkVersions(AdmissionApplicant $applicant, AdmissionExamResult $result, array $data): void
    {
        if ($applicant->version !== (int) $data['version'] || $result->id !== (int) $data['result_id'] || $result->version !== (int) $data['result_version']) {
            $this->conflict('ADMISSION_STATE_CHANGED', 'The application or published result changed. Eligibility must be refreshed.', $applicant, $result);
        }
    }

    private function conflict(string $code, string $message, AdmissionApplicant $applicant, ?AdmissionExamResult $result): never
    {
        throw new AdmissionConflictException($code, $message, [
            'current_version' => $applicant->version,
            'current_result_id' => $result?->id,
            'current_result_version' => $result?->version,
        ]);
    }

    private function decision(User $actor, AdmissionApplicant $applicant, AdmissionExamResult $result, string $action, array $before, array $after): void
    {
        AdmissionDecision::create(['applicant_id' => $applicant->id, 'result_id' => $result->id, 'actor_user_id' => $actor->id,
            'action' => $action, 'operation_key' => (string) Str::uuid(), 'application_version' => $applicant->version,
            'result_version' => $result->version, 'before' => $before, 'after' => $after, 'created_at' => now()]);
        $this->audit->workflow($actor, 'admission.'.$action, 'applicant', $applicant->id, $applicant->id, ['version' => $applicant->version, 'status' => $applicant->status]);
    }

    private function studentPayload(Student $student): array
    {
        return ['student_id' => $student->id, 'user_id' => $student->user_id, 'student_number' => $student->student_number,
            'course_id' => $student->course_id, 'curriculum_id' => $student->curriculum_id,
            'admission_date' => $student->admission_date->toDateString(), 'year_level' => $student->year_level, 'student_status' => $student->student_status];
    }
}
