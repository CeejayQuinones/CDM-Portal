<?php

namespace App\Services;

use App\Mail\LoginVerificationCodeMail;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginOtpService
{
    public const CODE_TTL_MINUTES = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    private const ACCOUNT_SEND_LIMIT = 5;

    private const IP_SEND_LIMIT = 15;

    private const SEND_DECAY_SECONDS = 600;

    public function __construct(private readonly AuthenticationService $authentication) {}

    /** @return array<string, mixed> */
    public function create(User $user, string $client, string $ipAddress): array
    {
        $this->ensureOtpClient($client);
        $email = $this->emailFor($user);
        $this->ensureSafeMailer();
        $this->enforceSendRateLimits($user, $ipAddress);

        $challenge = DB::transaction(function () use ($user, $client, $email): LoginOtpChallenge {
            LoginOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('client', $client)
                ->whereNull('consumed_at')
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->update(['superseded_at' => now()]);

            $code = $this->generateCode();
            $challenge = LoginOtpChallenge::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'client' => $client,
                'code_hash' => Hash::make($code),
                'failed_attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'resend_available_at' => now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
            ]);

            $this->sendCode($email, $code);

            return $challenge;
        }, 3);

        return $this->responseData($challenge, $email);
    }

    /** @return array<string, mixed> */
    public function resend(string $publicId, string $client, string $ipAddress): array
    {
        $this->ensureOtpClient($client);
        $this->ensureSafeMailer();

        return DB::transaction(function () use ($publicId, $client, $ipAddress): array {
            $challenge = LoginOtpChallenge::query()
                ->with('user.profile')
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();
            $this->ensureUsable($challenge, $client);
            $user = $challenge->user;
            $email = $this->emailFor($user);
            $this->enforceSendRateLimits($user, $ipAddress);

            if ($challenge->resend_available_at->isFuture()) {
                throw ValidationException::withMessages([
                    'challenge_id' => ['Please wait before requesting another verification code.'],
                ]);
            }

            $code = $this->generateCode();
            $challenge->forceFill([
                'code_hash' => Hash::make($code),
                'failed_attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'resend_available_at' => now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
            ])->save();

            $this->sendCode($email, $code);

            return $this->responseData($challenge, $email);
        }, 3);
    }

    /** @return array{user: User, token: string} */
    public function verify(string $publicId, string $code, string $client, string $ipAddress): array
    {
        $this->ensureOtpClient($client);

        $result = DB::transaction(function () use ($publicId, $code, $client, $ipAddress): array {
            $challenge = LoginOtpChallenge::query()
                ->with('user.role', 'user.profile')
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();
            $this->ensureUsable($challenge, $client);
            $this->enforceAttemptRateLimit($challenge, $ipAddress);

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('failed_attempts');
                $challenge->refresh();
                if ($challenge->failed_attempts >= self::MAX_ATTEMPTS) {
                    $challenge->forceFill(['superseded_at' => now()])->save();
                }

                return ['error' => 'The verification code is invalid or has expired.'];
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            return $this->authentication->issueToken($challenge->user, $client);
        }, 3);

        if (isset($result['error'])) {
            throw ValidationException::withMessages(['code' => [$result['error']]]);
        }

        return $result;
    }

    public function deliveryIsConfigured(): bool
    {
        return $this->mailerIsSafe((string) config('mail.default'), []);
    }

    private function ensureSafeMailer(): void
    {
        abort_unless($this->deliveryIsConfigured(), 503, 'Login verification email delivery is not configured. Please contact the portal administrator.');
    }

    private function mailerIsSafe(string $mailer, array $visited): bool
    {
        if ($mailer === '' || in_array($mailer, $visited, true)) {
            return false;
        }
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }
        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return true;
        }

        $children = config("mail.mailers.{$mailer}.mailers", []);

        return is_array($children)
            && $children !== []
            && collect($children)->every(fn ($child): bool => is_string($child)
                && $this->mailerIsSafe($child, [...$visited, $mailer]));
    }

    private function ensureOtpClient(string $client): void
    {
        if (! in_array($client, [ClientPlatform::WEB, ClientPlatform::MOBILE], true)) {
            throw new AuthenticationException('This login verification challenge is not valid for the selected CDM client.');
        }
    }

    private function ensureUsable(?LoginOtpChallenge $challenge, string $client): void
    {
        if ($challenge === null
            || ! hash_equals($challenge->client, $client)
            || $challenge->expires_at->isPast()
            || $challenge->consumed_at !== null
            || $challenge->superseded_at !== null
            || $challenge->failed_attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'challenge_id' => ['This login verification challenge is invalid or has expired. Sign in again.'],
            ]);
        }
    }

    private function emailFor(User $user): string
    {
        $email = mb_strtolower(trim((string) $user->loadMissing('profile')->profile?->email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'This account does not have a valid registered email address. Contact the portal administrator.');
        }

        return $email;
    }

    private function enforceSendRateLimits(User $user, string $ipAddress): void
    {
        $keys = [
            ['login-otp:send:account:'.$user->id, self::ACCOUNT_SEND_LIMIT],
            ['login-otp:send:ip:'.$this->ipHash($ipAddress), self::IP_SEND_LIMIT],
        ];
        foreach ($keys as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                abort(429, 'Too many login verification requests. Please try again later.');
            }
        }
        foreach ($keys as [$key]) {
            RateLimiter::hit($key, self::SEND_DECAY_SECONDS);
        }
    }

    private function enforceAttemptRateLimit(LoginOtpChallenge $challenge, string $ipAddress): void
    {
        $keys = [
            'login-otp:verify:challenge:'.hash('sha256', $challenge->public_id),
            'login-otp:verify:ip:'.$this->ipHash($ipAddress),
        ];
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, 20)) {
                abort(429, 'Too many verification attempts. Please sign in again later.');
            }
            RateLimiter::hit($key, 600);
        }
    }

    /** @return array<string, mixed> */
    private function responseData(LoginOtpChallenge $challenge, string $email): array
    {
        return [
            'requires_otp' => true,
            'challenge_id' => $challenge->public_id,
            'masked_email' => $this->maskEmail($email),
            'expires_at' => $challenge->expires_at->toISOString(),
            'resend_available_at' => $challenge->resend_available_at->toISOString(),
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendCode(string $email, string $code): void
    {
        try {
            Mail::to($email)->send(new LoginVerificationCodeMail($code));
        } catch (\Throwable $exception) {
            Log::warning('login_otp_delivery_failed', [
                'exception' => $exception::class,
            ]);
            abort(503, 'The login verification email could not be sent. Please try again later.');
        }
    }

    private function ipHash(string $ipAddress): string
    {
        return hash('sha256', trim($ipAddress));
    }
}
