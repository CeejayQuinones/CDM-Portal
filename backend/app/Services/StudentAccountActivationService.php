<?php

namespace App\Services;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentAccountActivation;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\StudentRecords\StudentRecordAuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentAccountActivationService
{
    private const CODE_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly StudentRecordAuditWriter $audit) {}

    /**
     * Only an explicitly prepared, inactive Student account can be claimed.
     * Existing active accounts are deliberately never eligible.
     */
    private function eligible(string $number, string $email): ?StudentAccountActivation
    {
        $student = Student::query()->with(['user.role', 'userProfile'])
            ->where('student_number', $number)->first();
        if ($student === null || $student->user?->role?->role_name !== Role::STUDENT
            || $student->user->status !== 'inactive'
            || $student->user->last_login !== null
            || ! $student->user->is_first_login
            || $student->user->google_subject_id !== null
            || $student->user->tokens()->exists()
            || mb_strtolower(trim((string) $student->userProfile?->email)) !== $email) {
            return null;
        }

        return StudentAccountActivation::query()
            ->where('student_id', $student->id)->whereNull('claimed_at')->first();
    }

    public function sendCode(string $number, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $activation = $this->eligible(trim($number), $email);
        if ($activation === null || $activation->sent_at?->gt(now()->subMinute())) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        $activation->forceFill([
            'code_hash' => Hash::make($code),
            'token_hash' => null,
            'sent_at' => now(),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'verified_at' => null,
            'attempts' => 0,
        ])->save();

        try {
            Mail::to($email)->send(new RegistrationVerificationCodeMail($code));
        } catch (\Throwable $exception) {
            $activation->forceFill(['code_hash' => null, 'expires_at' => now()])->save();
            report($exception);
        }
    }

    public function verifyCode(string $number, string $email, string $code): string
    {
        $result = DB::transaction(function () use ($number, $email, $code): array {
            $activation = $this->eligible(trim($number), mb_strtolower(trim($email)));
            if ($activation === null) {
                return ['invalid' => true];
            }
            $activation = StudentAccountActivation::query()->lockForUpdate()->findOrFail($activation->id);
            if ($activation->code_hash === null || $activation->verified_at !== null
                || $activation->expires_at?->isPast() !== false || $activation->attempts >= self::MAX_ATTEMPTS) {
                return ['invalid' => true];
            }
            $activation->increment('attempts');
            if (! Hash::check($code, $activation->code_hash)) {
                return ['invalid' => true];
            }
            $token = Str::random(64);
            $activation->forceFill([
                'code_hash' => null,
                'token_hash' => Hash::make($token),
                'verified_at' => now(),
            ])->save();

            return ['token' => $token];
        });

        if (isset($result['invalid'])) {
            throw $this->invalidCode();
        }

        return $result['token'];
    }

    public function activate(string $number, string $email, string $token, string $username, string $password): void
    {
        DB::transaction(function () use ($number, $email, $token, $username, $password): void {
            $activation = $this->eligible(trim($number), mb_strtolower(trim($email)));
            if ($activation === null) {
                throw $this->invalidCode();
            }
            $activation = StudentAccountActivation::query()->lockForUpdate()->findOrFail($activation->id);
            if ($activation->verified_at === null || $activation->expires_at?->isPast() !== false
                || $activation->token_hash === null || ! Hash::check($token, $activation->token_hash)) {
                throw $this->invalidCode();
            }
            $student = Student::query()->findOrFail($activation->student_id);
            $user = User::query()->lockForUpdate()->findOrFail($student->user_id);
            if ($user->status !== 'inactive' || $user->last_login !== null || ! $user->is_first_login
                || $user->google_subject_id !== null || $user->tokens()->exists()) {
                throw $this->invalidCode();
            }
            if (User::query()->where('username', $username)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['username' => ['This username is unavailable.']]);
            }
            $user->forceFill([
                'username' => $username,
                'password' => $password,
                'status' => 'active',
                'is_first_login' => false,
            ])->save();
            $activation->forceFill([
                'claimed_at' => now(),
                'code_hash' => null,
                'token_hash' => null,
            ])->save();
            $this->audit->record($user, $student->id, 'student_account_activated', [
                'changed_fields' => ['account.status', 'account.username'],
            ]);
        });
    }

    public function prepare(User $registrar, Student $student, string $reference): void
    {
        DB::transaction(function () use ($registrar, $student, $reference): void {
            $student = Student::query()->with(['user.role', 'userProfile'])->lockForUpdate()->findOrFail($student->id);
            if ($student->user?->role?->role_name !== Role::STUDENT
                || $student->user->status !== 'inactive' || $student->user->last_login !== null
                || ! $student->user->is_first_login || $student->user->tokens()->exists()
                || $student->user->google_subject_id !== null
                || ! filter_var($student->userProfile?->email, FILTER_VALIDATE_EMAIL)
                || StudentAccountActivation::query()->where('student_id', $student->id)->exists()) {
                throw ValidationException::withMessages(['student' => ['Only an unclaimed, inactive Student record with a checked enrollment email can be prepared.']]);
            }
            StudentAccountActivation::query()->create([
                'student_id' => $student->id,
                'prepared_by_user_id' => $registrar->id,
                'record_reference' => $reference,
            ]);
            $this->audit->record($registrar, $student->id, 'student_account_activation_prepared', [
                'record_reference' => $reference,
            ]);
        });
    }

    public function correctEmail(User $registrar, Student $student, string $email, string $reference): void
    {
        DB::transaction(function () use ($registrar, $student, $email, $reference): void {
            $student = Student::query()->with(['user', 'userProfile'])->lockForUpdate()->findOrFail($student->id);
            $activation = StudentAccountActivation::query()->where('student_id', $student->id)->lockForUpdate()->first();
            if ($activation?->claimed_at !== null || $student->user?->role?->role_name !== Role::STUDENT
                || $student->user?->status !== 'inactive'
                || $student->user->last_login !== null || ! $student->user->is_first_login
                || $student->user->google_subject_id !== null
                || $student->user->tokens()->exists()) {
                throw ValidationException::withMessages(['student' => ['This account is not eligible for activation email correction.']]);
            }
            $email = mb_strtolower(trim($email));
            if (UserProfile::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->whereKeyNot($student->user_profile_id)->exists()) {
                throw ValidationException::withMessages(['email' => ['This email is already assigned to an account.']]);
            }
            $student->userProfile->forceFill(['email' => $email])->save();
            if ($activation === null) {
                $activation = StudentAccountActivation::query()->create([
                    'student_id' => $student->id,
                    'prepared_by_user_id' => $registrar->id,
                    'record_reference' => $reference,
                ]);
            }
            $activation->forceFill([
                'code_hash' => null, 'token_hash' => null, 'verified_at' => null,
                'expires_at' => null, 'sent_at' => null, 'attempts' => 0,
            ])->save();
            $this->audit->record($registrar, $student->id, 'student_account_activation_email_corrected', [
                'changed_fields' => ['profile.email'], 'school_id_checked' => true,
                'identity_check_reference' => $reference,
            ]);
        });
    }

    private function invalidCode(): ValidationException
    {
        return ValidationException::withMessages(['code' => ['The activation details or code are invalid or expired.']]);
    }
}
