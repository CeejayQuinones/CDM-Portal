<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCodeMail;
use App\Mail\RegistrationVerificationCodeMail;
use App\Models\LoginOtpChallenge;
use App\Models\RegistrationEmailVerification;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginOtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    public function test_web_password_login_creates_a_hashed_challenge_without_a_token(): void
    {
        $user = $this->user(Role::STUDENT);

        $response = $this->start($user)->assertAccepted()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.masked_email', 'st*****@example.com')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user');

        $challenge = LoginOtpChallenge::query()->where('public_id', $response->json('data.challenge_id'))->firstOrFail();
        $code = $this->sentCode();
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->last_login);
    }

    public function test_correct_code_is_single_use_and_issues_a_platform_bound_token(): void
    {
        $user = $this->user(Role::STUDENT);
        $challengeId = $this->start($user)->json('data.challenge_id');
        $code = $this->sentCode();

        $verified = $this->verify($challengeId, $code)->assertOk();
        $token = $verified->json('data.token');
        $this->assertSame([ClientPlatform::ability(ClientPlatform::WEB)], PersonalAccessToken::findToken($token)->abilities);
        $this->assertNotNull($user->fresh()->last_login);
        $this->verify($challengeId, $code)->assertUnprocessable();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_expired_code_and_five_wrong_attempts_cannot_issue_access(): void
    {
        $user = $this->user(Role::STUDENT);
        $challengeId = $this->start($user)->json('data.challenge_id');
        LoginOtpChallenge::query()->where('public_id', $challengeId)->update(['expires_at' => now()->subSecond()]);
        $this->verify($challengeId, $this->sentCode())->assertUnprocessable();
        $this->assertSame(0, $user->tokens()->count());

        $challengeId = $this->start($user)->json('data.challenge_id');
        $correctCode = $this->sentCode();
        $wrongCode = $correctCode === '000000' ? '999999' : '000000';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->verify($challengeId, $wrongCode)->assertUnprocessable();
        }
        $this->verify($challengeId, $correctCode)->assertUnprocessable();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_resend_invalidates_the_previous_code_and_obeys_the_cooldown(): void
    {
        $user = $this->user(Role::STUDENT);
        $start = $this->start($user);
        $challengeId = $start->json('data.challenge_id');
        $oldCode = $this->sentCode();

        $this->postJson('/api/auth/login/otp/resend', ['challenge_id' => $challengeId])
            ->assertUnprocessable();

        $this->travel(61)->seconds();
        Mail::fake();
        $this->postJson('/api/auth/login/otp/resend', ['challenge_id' => $challengeId])
            ->assertOk()
            ->assertJsonPath('data.challenge_id', $challengeId);
        $newCode = $this->sentCode();
        $this->assertNotSame($oldCode, $newCode);
        $this->verify($challengeId, $oldCode)->assertUnprocessable();
        $this->verify($challengeId, $newCode)->assertOk();
    }

    public function test_new_login_supersedes_an_older_challenge(): void
    {
        $user = $this->user(Role::STUDENT);
        $firstId = $this->start($user)->json('data.challenge_id');
        $firstCode = $this->sentCode();
        Mail::fake();
        $secondId = $this->start($user)->json('data.challenge_id');

        $this->assertNotSame($firstId, $secondId);
        $this->verify($firstId, $firstCode)->assertUnprocessable();
        $this->verify($secondId, $this->sentCode())->assertOk();
    }

    public function test_mobile_uses_otp_while_desktop_staff_login_remains_unchanged(): void
    {
        $student = $this->user(Role::STUDENT);
        $mobile = $this->start($student, ClientPlatform::MOBILE)->assertAccepted();
        $this->verify($mobile->json('data.challenge_id'), $this->sentCode(), ClientPlatform::MOBILE)->assertOk();

        $registrar = $this->user(Role::REGISTRAR_STAFF, 'registrar@example.com');
        $this->postJson('/api/login', [
            'username' => $registrar->username,
            'password' => 'Password123!',
        ], [ClientPlatform::HEADER => ClientPlatform::DESKTOP])
            ->assertOk()
            ->assertJsonMissingPath('data.requires_otp')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_missing_email_and_unsafe_mail_transport_fail_without_access(): void
    {
        $missing = $this->user(Role::STUDENT);
        $missing->profile()->update(['email' => null]);
        $this->start($missing)->assertUnprocessable();
        $this->assertSame(0, $missing->tokens()->count());

        $user = $this->user(Role::STUDENT, 'safe@example.com');
        config(['mail.default' => 'log']);
        $this->start($user)->assertStatus(503);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseCount('login_otp_challenges', 0);
    }

    public function test_mail_delivery_failure_rolls_back_the_challenge_and_issues_no_token(): void
    {
        $user = $this->user(Role::STUDENT);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('simulated delivery failure'));

        $this->start($user)
            ->assertStatus(503)
            ->assertJsonPath('message', 'The login verification email could not be sent. Please try again later.');

        $this->assertDatabaseCount('login_otp_challenges', 0);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->last_login);
    }

    public function test_repeated_login_code_requests_are_temporarily_rate_limited(): void
    {
        $user = $this->user(Role::STUDENT);
        for ($request = 1; $request <= 5; $request++) {
            Mail::fake();
            $this->start($user)->assertAccepted();
        }

        $this->start($user)->assertTooManyRequests();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_existing_valid_tokens_are_not_revoked_when_otp_is_enabled(): void
    {
        $user = $this->user(Role::STUDENT);
        $existing = $user->createToken('existing', [ClientPlatform::ability(ClientPlatform::WEB)])->plainTextToken;

        $this->start($user)->assertAccepted();

        $this->withToken($existing)->getJson('/api/me', [ClientPlatform::HEADER => ClientPlatform::WEB])->assertOk();
    }

    public function test_registration_and_login_codes_cannot_be_used_interchangeably(): void
    {
        $registrationEmail = 'registration-only@example.com';
        $user = $this->user(Role::GUEST, 'login-only@example.com');

        $this->postJson('/api/registration/email-verification/send', [
            'email' => $registrationEmail,
        ])->assertOk();
        Mail::assertSent(RegistrationVerificationCodeMail::class);

        Mail::fake();
        $login = $this->start($user)->assertAccepted();
        $this->sentCode();
        $registrationCode = '123456';
        $loginCode = '654321';
        RegistrationEmailVerification::query()
            ->where('email', $registrationEmail)
            ->update(['code_hash' => Hash::make($registrationCode)]);
        LoginOtpChallenge::query()
            ->where('public_id', $login->json('data.challenge_id'))
            ->update(['code_hash' => Hash::make($loginCode)]);

        $this->verify($login->json('data.challenge_id'), $registrationCode)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->postJson('/api/registration/email-verification/verify', [
            'email' => $registrationEmail,
            'code' => $loginCode,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('registration_email_verifications', [
            'email' => $registrationEmail,
            'verified_at' => null,
        ]);
        $this->assertDatabaseHas('login_otp_challenges', [
            'public_id' => $login->json('data.challenge_id'),
            'consumed_at' => null,
        ]);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull(RegistrationEmailVerification::query()->where('email', $registrationEmail)->first());
    }

    public function test_password_change_invalidates_an_unfinished_login_challenge(): void
    {
        $user = $this->user(Role::STUDENT);
        $challengeId = $this->start($user)->json('data.challenge_id');
        $code = $this->sentCode();
        $token = $user->createToken('existing', [ClientPlatform::ability(ClientPlatform::WEB)])->plainTextToken;

        $this->withToken($token)->postJson('/api/change-password', [
            'current_password' => 'Password123!',
            'password' => 'ReplacementPass123!',
            'password_confirmation' => 'ReplacementPass123!',
        ], [ClientPlatform::HEADER => ClientPlatform::WEB])->assertOk();

        $this->verify($challengeId, $code)->assertUnprocessable();
        $this->assertSame(0, $user->tokens()->count());
    }

    private function user(string $roleName, string $email = 'student@example.com'): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);
        $user->profile()->create([
            'first_name' => 'OTP',
            'last_name' => 'User',
            'gender' => 'Prefer not to say',
            'email' => $email,
        ]);

        return $user->fresh('profile');
    }

    private function start(User $user, string $client = ClientPlatform::WEB)
    {
        return $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ], [ClientPlatform::HEADER => $client]);
    }

    private function verify(string $challengeId, string $code, string $client = ClientPlatform::WEB)
    {
        return $this->postJson('/api/auth/login/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ], [ClientPlatform::HEADER => $client]);
    }

    private function sentCode(): string
    {
        $message = Mail::sent(LoginVerificationCodeMail::class)->last();
        $this->assertInstanceOf(LoginVerificationCodeMail::class, $message);

        return $message->code;
    }
}
