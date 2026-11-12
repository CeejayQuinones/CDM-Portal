<?php

namespace Tests;

use App\Mail\LoginVerificationCodeMail;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

abstract class TestCase extends BaseTestCase
{
    /**
     * Existing feature tests predate the required client header. Supply the
     * role's normal client unless a platform test explicitly disables it.
     */
    protected bool $injectClientPlatformHeader = true;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->injectClientPlatformHeader
            && str_starts_with(parse_url((string) $uri, PHP_URL_PATH) ?: '', '/api/')
            && ! array_key_exists('HTTP_X_CDM_CLIENT', $server)) {
            $server['HTTP_X_CDM_CLIENT'] = $this->defaultClientPlatform((string) $uri, $content);
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function withoutAutomaticClientPlatform(): static
    {
        $this->injectClientPlatformHeader = false;

        return $this;
    }

    protected function loginAndCompleteOtp(User $user, string $client, string $password = 'Password123!'): TestResponse
    {
        if ($client === ClientPlatform::DESKTOP) {
            return $this->postJson('/api/login', [
                'username' => $user->username,
                'password' => $password,
            ], [ClientPlatform::HEADER => $client]);
        }

        config(['mail.default' => 'smtp']);
        Mail::fake();
        $challenge = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => $password,
        ], [ClientPlatform::HEADER => $client])->assertAccepted();
        $code = null;
        Mail::assertSent(LoginVerificationCodeMail::class, function (LoginVerificationCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return $this->postJson('/api/auth/login/otp/verify', [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'code' => $code,
        ], [ClientPlatform::HEADER => $client]);
    }

    private function defaultClientPlatform(string $uri, ?string $content): string
    {
        $authorization = $this->defaultHeaders['Authorization'] ?? null;
        $user = null;

        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $token = PersonalAccessToken::findToken(substr($authorization, 7));
            $user = $token?->tokenable;
        }

        $user ??= app('auth')->guard('sanctum')->user();

        if (! $user instanceof User && is_string($content)) {
            $payload = json_decode($content, true);
            $username = is_array($payload) ? ($payload['username'] ?? null) : null;
            $user = is_string($username) ? User::query()->where('username', $username)->with('role')->first() : null;
        }

        $role = $user instanceof User ? $user->loadMissing('role')->role?->role_name : null;

        return in_array($role, [Role::ADMIN, Role::REGISTRAR_STAFF], true)
            ? ClientPlatform::DESKTOP
            : ClientPlatform::WEB;
    }
}
