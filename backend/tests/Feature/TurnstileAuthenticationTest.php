<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCodeMail;
use App\Mail\RegistrationVerificationCodeMail;
use App\Models\LoginOtpChallenge;
use App\Models\Role;
use App\Models\User;
use App\Services\TurnstileVerificationService;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class TurnstileAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.turnstile.enabled' => true,
            'services.turnstile.site_key' => 'real-site-key-for-isolated-tests',
            'services.turnstile.secret_key' => 'real-secret-key-for-isolated-tests',
            'services.turnstile.expected_hostnames' => ['localhost'],
            'services.turnstile.endpoint' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ]);
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    public function test_valid_turnstile_allows_an_otp_challenge_without_issuing_a_token(): void
    {
        $user = $this->user(Role::STUDENT);
        $this->fakeSuccess('authentication');

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
            'turnstile_token' => 'valid-token',
        ])->assertAccepted()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonMissingPath('data.token');

        $this->assertSame(1, LoginOtpChallenge::query()->count());
        $this->assertSame(0, $user->tokens()->count());
        Mail::assertSent(LoginVerificationCodeMail::class);
        Http::assertSent(fn ($request): bool => $request['response'] === 'valid-token'
            && $request['secret'] === 'real-secret-key-for-isolated-tests');
    }

    public function test_missing_or_invalid_turnstile_creates_no_challenge_or_session(): void
    {
        $user = $this->user(Role::STUDENT);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.turnstile_token.0', 'Please complete the human verification check.');

        Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
            'turnstile_token' => 'invalid-token',
        ])->assertUnprocessable();

        $this->assertSame(0, LoginOtpChallenge::query()->count());
        $this->assertSame(0, $user->tokens()->count());
        Mail::assertNothingSent();
    }

    public function test_expired_or_reused_token_is_rejected_with_a_retryable_message(): void
    {
        $user = $this->user(Role::STUDENT);
        Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
            'turnstile_token' => 'consumed-token',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.turnstile_token.0', 'Human verification expired or was already used. Please try again.');

        $this->assertDatabaseCount('login_otp_challenges', 0);
    }

    public function test_unexpected_hostname_and_action_are_rejected(): void
    {
        $service = app(TurnstileVerificationService::class);

        Http::fake(fn ($request) => Http::response(
            $request['response'] === 'wrong-host'
                ? $this->successPayload('authentication', 'unexpected.example')
                : $this->successPayload('registration'),
        ));
        try {
            $service->verify('wrong-host', 'authentication', '127.0.0.1');
            $this->fail('Unexpected hostname must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('unexpected site', $exception->errors()['turnstile_token'][0]);
        }

        try {
            $service->verify('wrong-action', 'authentication', '127.0.0.1');
            $this->fail('Unexpected action must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('did not match', $exception->errors()['turnstile_token'][0]);
        }
    }

    public function test_service_outage_and_malformed_response_fail_closed(): void
    {
        $user = $this->user(Role::STUDENT);

        foreach ([Http::response([], 503), Http::response(['unexpected' => true])] as $response) {
            Http::fake(['*' => $response]);
            $this->postJson('/api/login', [
                'username' => $user->username,
                'password' => 'Password123!',
                'turnstile_token' => Str::random(24),
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('login_otp_challenges', 0);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_google_handoff_requires_turnstile_before_it_creates_otp(): void
    {
        $user = $this->user(Role::STUDENT);
        $code = Str::random(64);
        $key = 'google-auth-handoff:'.hash('sha256', $code);
        Cache::put($key, ['user_id' => $user->id, 'client' => ClientPlatform::WEB], now()->addMinute());

        $this->postJson('/api/auth/google/exchange', ['code' => $code])->assertUnprocessable();
        $this->assertTrue(Cache::has($key));

        $this->fakeSuccess('authentication');
        $this->postJson('/api/auth/google/exchange', [
            'code' => $code,
            'turnstile_token' => 'valid-google-token',
        ])->assertAccepted()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonMissingPath('data.token');

        $this->assertFalse(Cache::has($key));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_registration_email_initiation_requires_turnstile(): void
    {
        $this->postJson('/api/registration/email-verification/send', [
            'email' => 'turnstile-registration@example.com',
        ])->assertUnprocessable();
        Mail::assertNothingSent();

        $this->fakeSuccess('registration');
        $this->postJson('/api/registration/email-verification/send', [
            'email' => 'turnstile-registration@example.com',
            'turnstile_token' => 'valid-registration-token',
        ])->assertOk();

        Mail::assertSent(RegistrationVerificationCodeMail::class);
    }

    public function test_electron_staff_login_remains_unchanged_and_does_not_call_cloudflare(): void
    {
        $user = $this->user(Role::REGISTRAR_STAFF);
        Http::preventStrayRequests();

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ], [ClientPlatform::HEADER => ClientPlatform::DESKTOP])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_production_rejects_official_cloudflare_testing_credentials(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app['env'] = 'production';
        config([
            'services.turnstile.site_key' => '1x00000000000000000000AA',
            'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
        ]);

        try {
            app(TurnstileVerificationService::class)->verify('token', 'authentication', '127.0.0.1');
            $this->fail('Production must reject official testing credentials.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('testing credentials', $exception->getMessage());
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_paired_official_testing_credentials_are_limited_to_non_production(): void
    {
        config([
            'services.turnstile.site_key' => '1x00000000000000000000BB',
            'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'hostname' => 'example.com'])]);

        app(TurnstileVerificationService::class)->verify('XXXX.DUMMY.TOKEN.XXXX', 'authentication', '127.0.0.1');

        $this->addToAssertionCount(1);
    }

    private function fakeSuccess(string $action): void
    {
        Http::fake(['*' => Http::response($this->successPayload($action))]);
    }

    /** @return array<string, mixed> */
    private function successPayload(string $action, string $hostname = 'localhost'): array
    {
        return [
            'success' => true,
            'challenge_ts' => now()->toIso8601String(),
            'hostname' => $hostname,
            'action' => $action,
        ];
    }

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => bcrypt('Password123!'),
            'status' => 'active',
        ]);
        $user->profile()->create([
            'first_name' => 'Turnstile',
            'last_name' => 'Tester',
            'gender' => 'Prefer not to say',
            'email' => Str::lower($roleName).'-'.Str::random(8).'@example.com',
        ]);

        return $user;
    }
}
