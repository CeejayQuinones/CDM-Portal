<?php

namespace App\Services\Admission;

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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdmissionConversionService
{
    public function __construct(private AdmissionAuditWriter $audit) {}

    public function inspect(User $actor, int $id): array
    {
        return $this->locked($actor, $id, function ($actor, $user, $applicant) {
            $state = $this->state($user, $applicant);
            $student = $applicant->converted_student_id ? Student::find($applicant->converted_student_id) : null;

            return [
                'id' => $applicant->id, 'version' => $applicant->version, 'status' => $applicant->status,
                'applicant_number' => $applicant->applicant_number,
                'name' => trim($state['profile']?->first_name.' '.$state['profile']?->last_name),
                'can_accept' => $state['reason'] === null,
                'can_convert' => $state['reason'] === null && $this->acceptanceMatches($applicant, $state['result']),
                'reason' => $state['reason'] ?? ($this->acceptanceMatches($applicant, $state['result']) ? null : 'Registrar acceptance of the current result and academic program is required.'),
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
            abort_if($state['reason'] !== null, 409, $state['reason']);
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

    public function convert(User $actor, int $id, array $input): array
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:1', 'result_id' => 'required|integer', 'result_version' => 'required|integer|min:1',
            'course_id' => 'required|integer', 'curriculum_id' => 'required|integer',
            'student_number' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
                if (trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    $fail('Use the official student number without surrounding whitespace or control characters.');
                }
            }],
            'admission_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'confirmed' => 'required|accepted',
        ])->validate();

        try {
            return $this->locked($actor, $id, function ($actor, $user, $applicant) use ($data) {
                if ($applicant->status === AdmissionApplicant::CONVERTED) {
                    $student = Student::whereKey($applicant->converted_student_id)->lockForUpdate()->first();
                    $evidence = AdmissionDecision::where('applicant_id', $applicant->id)->where('action', 'student_converted')->lockForUpdate()->first();
                    abort_unless($student && $evidence && $student->user_id === $user->id
                        && UserProfile::whereKey($student->user_profile_id)->where('user_id', $user->id)->exists()
                        && ($evidence->after['student_id'] ?? null) === $student->id, 409, 'Conversion identity needs Registrar reconciliation.');

                    // A retry never edits existing academics or writes another audit event.
                    return ['converted' => true, 'already_converted' => true, 'student' => $this->studentPayload($student)];
                }
                $state = $this->state($user, $applicant);
                abort_if($state['reason'] !== null, 409, $state['reason']);
                $this->checkVersions($applicant, $state['result'], $data);
                abort_unless($this->acceptanceMatches($applicant, $state['result']), 409, 'Renew Registrar acceptance before conversion.');
                abort_unless((int) $applicant->accepted_course_id === (int) $data['course_id'] && (int) $applicant->accepted_curriculum_id === (int) $data['curriculum_id'], 409, 'The confirmed academic program changed.');
                $this->academics($data['course_id'], $data['curriculum_id'], $data['admission_date']);
                // Stable allocator lock serializes confirmations across different users; unique indexes remain authoritative.
                $studentRole = Role::where('role_name', Role::STUDENT)->lockForUpdate()->first();
                abort_unless($studentRole, 409, 'The Student role is not configured.');
                abort_if(Student::where('student_number', $data['student_number'])->lockForUpdate()->exists(), 409, 'That official student number is already assigned.');
                $student = Student::create([
                    'user_id' => $user->id, 'user_profile_id' => $state['profile']->id,
                    'course_id' => $applicant->accepted_course_id, 'curriculum_id' => $applicant->accepted_curriculum_id,
                    'student_number' => $data['student_number'], 'admission_date' => $data['admission_date'],
                    'year_level' => 1, 'student_status' => 'regular',
                ]);
                $user->role_id = $studentRole->id;
                $user->save();
                $before = ['status' => $applicant->status, 'version' => $applicant->version];
                $applicant->forceFill(['status' => AdmissionApplicant::CONVERTED, 'converted_student_id' => $student->id,
                    'converted_at' => now(), 'version' => $applicant->version + 1])->save();
                $this->decision($actor, $applicant, $state['result'], 'student_converted', $before,
                    ['status' => $applicant->status, 'version' => $applicant->version] + $this->studentPayload($student));
                // Existing credentials remain valid; a normal sign-in refreshes navigation and permissions.
                $user->tokens()->delete();

                return ['converted' => true, 'already_converted' => false, 'student' => $this->studentPayload($student)];
            });
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'An academic identity or student number is already assigned. Refresh before trying again.');
        }
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
        $sessions = AdmissionExamSession::whereIn('applicant_id', $applications->pluck('id'))->orderByDesc('attempt_number')->lockForUpdate()->get();
        $latest = $sessions->first();
        $result = $latest ? AdmissionExamResult::where('session_id', $latest->id)->lockForUpdate()->first() : null;
        $reason = match (true) {
            $applicant->status === AdmissionApplicant::CONVERTED => 'Already converted to Student.',
            $applications->first()?->id !== $applicant->id => 'Only the current Admission application can be converted.',
            ! in_array($applicant->status, AdmissionApplicant::ACTIVE_STATUSES, true) => 'This application is not open for acceptance.',
            $user->status !== 'active' || $user->role->role_name !== Role::GUEST => 'An active Guest account is required.',
            ! $profile || ! trim($profile->first_name) || ! trim($profile->last_name) => 'Complete the applicant profile first.',
            Student::where('user_id', $user->id)->orWhere('user_profile_id', $profile?->id)->lockForUpdate()->exists() => 'An existing academic identity needs Registrar reconciliation.',
            ! $cycle || ! in_array($cycle->status, ['open', 'closed'], true) || now()->lt($cycle->opens_at) || now()->gt($cycle->confirmation_closes_at) => 'The cycle is outside its final confirmation window.',
            $sessions->contains('status', 'active') => 'An exam or retake is still in progress.',
            ! $latest || $latest->applicant_id !== $applicant->id || $latest->status !== 'finalized' => 'A completed exam for this application is required.',
            ! $result || $result->official_status !== 'published' || ! $result->published_at => 'The latest result must be published.',
            $result->outcome() !== 'PASSED' => 'The latest published result must be Passed; a pending retake cannot convert.',
            default => null,
        };

        return compact('reason', 'profile', 'result');
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
        abort_unless($applicant->version === (int) $data['version'] && $result->id === (int) $data['result_id'] && $result->version === (int) $data['result_version'], 409, 'The application or result changed. Refresh and review again.');
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
