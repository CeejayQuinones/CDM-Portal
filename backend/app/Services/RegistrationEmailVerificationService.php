<?php

namespace App\Services;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\RegistrationEmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrationEmailVerificationService
{
    public const CODE_TTL_MINUTES = 10;

    public const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function deliveryIsConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['array', 'log'], true);
    }

    public function send(string $email): void
    {
        $email = mb_strtolower($email);
        $existing = RegistrationEmailVerification::query()->where('email', $email)->first();

        if ($existing?->last_sent_at?->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            throw ValidationException::withMessages([
                'email' => ['Please wait before requesting another verification code.'],
            ]);
        }

        $code = (string) random_int(100000, 999999);

        $verification = RegistrationEmailVerification::query()->updateOrCreate(
            ['email' => $email],
            [
                'code_hash' => Hash::make($code),
                'token_hash' => null,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'verified_at' => null,
                'consumed_at' => null,
                'last_sent_at' => now(),
                'attempts' => 0,
            ],
        );

        try {
            Mail::to($email)->send(new RegistrationVerificationCodeMail($code));
        } catch (\Throwable $exception) {
            $verification->delete();
            report($exception);

            throw ValidationException::withMessages([
                'email' => ['The verification email could not be sent. Please try again later.'],
            ]);
        }
    }

    public function verify(string $email, string $code): string
    {
        $result = DB::transaction(function () use ($email, $code): array {
            $verification = RegistrationEmailVerification::query()
                ->where('email', mb_strtolower($email))
                ->lockForUpdate()
                ->first();

            if ($verification === null || $verification->expires_at->isPast() || $verification->consumed_at !== null) {
                return ['error' => 'The verification code is invalid or has expired. Request a new code.'];
            }

            if ($verification->verified_at !== null) {
                return ['error' => 'This verification code has already been used.'];
            }

            $verification->increment('attempts');
            $verification->refresh();

            if ($verification->attempts > self::MAX_ATTEMPTS || ! Hash::check($code, $verification->code_hash)) {
                if ($verification->attempts >= self::MAX_ATTEMPTS) {
                    $verification->forceFill(['expires_at' => now()])->save();
                }

                return ['error' => 'The verification code is invalid or has expired.'];
            }

            $token = Str::random(64);
            $verification->forceFill([
                'code_hash' => null,
                'token_hash' => Hash::make($token),
                'verified_at' => now(),
            ])->save();

            return ['token' => $token];
        });

        if (isset($result['error'])) {
            throw ValidationException::withMessages(['code' => [$result['error']]]);
        }

        return $result['token'];
    }

    public function consume(string $email, string $token): void
    {
        $verification = RegistrationEmailVerification::query()
            ->where('email', mb_strtolower($email))
            ->lockForUpdate()
            ->first();

        if ($verification === null
            || $verification->verified_at === null
            || $verification->consumed_at !== null
            || $verification->expires_at->isPast()
            || $verification->token_hash === null
            || ! Hash::check($token, $verification->token_hash)) {
            throw ValidationException::withMessages([
                'email' => ['Verify this email address before creating an account.'],
            ]);
        }

        $verification->forceFill([
            'token_hash' => null,
            'consumed_at' => now(),
        ])->save();
    }
}
