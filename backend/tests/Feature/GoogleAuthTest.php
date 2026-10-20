<?php

namespace Tests\Feature;

use App\Exceptions\ClientPlatformException;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleIdentityService;
use App\Support\ClientPlatform;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_requests_account_selection_and_preserves_the_stateful_session(): void
    {
        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://localhost:8000/auth/google/callback',
        ]);

        $response = $this->get('/auth/google/redirect?client=web')->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('select_account', $query['prompt'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
        $this->assertSame('web', session('google_auth_client'));
    }

    public function test_matching_verified_professor_links_subject_without_creating_a_user(): void
    {
        $user = $this->user(Role::PROFESSOR, ' Professor@School.edu ');
        $count = User::count();

        $authenticated = app(GoogleIdentityService::class)->authenticate('google-professor-1', 'professor@school.edu', true, ClientPlatform::WEB);

        $this->assertSame($user->id, $authenticated->id);
        $this->assertSame('google-professor-1', $user->fresh()->google_subject_id);
        $this->assertSame('professor@school.edu', $user->fresh()->google_email);
        $this->assertNotNull($user->fresh()->google_linked_at);
        $this->assertSame($count, User::count());
    }

    public function test_matching_verified_student_reuses_the_existing_guest_to_student_user(): void
    {
        $user = $this->user(Role::GUEST, 'student@example.com');
        $studentRole = Role::firstOrCreate(['role_name' => Role::STUDENT]);
        $user->update(['role_id' => $studentRole->id]);

        $authenticated = app(GoogleIdentityService::class)->authenticate('google-student-1', 'STUDENT@example.com', true, ClientPlatform::WEB);

        $this->assertSame($user->id, $authenticated->id);
        $this->assertSame(1, User::count());
    }

    public function test_unknown_unverified_and_ineligible_accounts_are_denied_without_provisioning(): void
    {
        $service = app(GoogleIdentityService::class);
        $before = User::count();
        foreach ([
            fn () => $service->authenticate('unknown', 'unknown@example.com', true, ClientPlatform::WEB),
            fn () => $service->authenticate('unverified', 'unknown@example.com', false, ClientPlatform::WEB),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Google identity should have been denied.');
            } catch (AuthenticationException) {
                $this->assertSame($before, User::count());
            }
        }
        $registrar = $this->user(Role::REGISTRAR_STAFF, 'registrar@example.com');
        $this->expectException(AuthenticationException::class);
        $service->authenticate('registrar', $registrar->profile->email, true, ClientPlatform::DESKTOP);
    }

    public function test_unverified_email_has_the_safe_specific_message(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Your Google email must be verified before you can sign in.');
        app(GoogleIdentityService::class)->authenticate('subject', 'student@example.com', false, ClientPlatform::WEB);
    }

    public function test_a_different_google_subject_cannot_take_over_a_linked_account(): void
    {
        $user = $this->user(Role::STUDENT, 'student@example.com');
        $user->forceFill(['google_subject_id' => 'original-subject', 'google_email' => 'student@example.com', 'google_linked_at' => now()])->save();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No eligible CDM Portal account is associated with this Google account.');
        app(GoogleIdentityService::class)->authenticate('attacker-subject', 'student@example.com', true, ClientPlatform::WEB);
    }

    public function test_google_identity_cannot_bypass_platform_policy(): void
    {
        $this->user(Role::STUDENT, 'student@example.com');
        $this->expectException(ClientPlatformException::class);
        app(GoogleIdentityService::class)->authenticate('subject', 'student@example.com', true, ClientPlatform::DESKTOP);
    }

    public function test_one_time_handoff_exchanges_for_the_existing_sanctum_format(): void
    {
        $user = $this->user(Role::STUDENT, 'student@example.com');
        $code = str_repeat('a', 64);
        Cache::put('google-auth-handoff:'.hash('sha256', $code), ['user_id' => $user->id, 'client' => ClientPlatform::WEB], now()->addMinute());

        $response = $this->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer');
        $this->assertNotEmpty($response->json('data.token'));
        $this->postJson('/api/auth/google/exchange', ['code' => $code])->assertUnauthorized();
    }

    public function test_callback_without_the_original_session_is_reported_as_a_state_mismatch(): void
    {
        Log::spy();

        $this->get('/auth/google/callback')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'google_login_failed'
            && $context['reason'] === 'state_session_mismatch')->once();
    }

    public function test_professor_supports_password_login_and_rejects_an_incorrect_password(): void
    {
        $professor = $this->user(Role::PROFESSOR, 'professor-password@example.com');
        $this->postJson('/api/login', ['username' => $professor->username, 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $professor->id);
        $this->postJson('/api/login', ['username' => $professor->username, 'password' => 'WrongPassword123!'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid username or password.');
    }

    public function test_student_supports_password_login_and_rejects_an_incorrect_password(): void
    {
        $student = $this->user(Role::STUDENT, 'student-password@example.com');
        $this->postJson('/api/login', ['username' => $student->username, 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $student->id);
        $this->postJson('/api/login', ['username' => $student->username, 'password' => 'WrongPassword123!'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid username or password.');
    }

    public function test_staff_and_guest_password_policies_remain_unchanged(): void
    {
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF, Role::GUEST] as $role) {
            $user = $this->user($role, strtolower(str_replace(' ', '-', $role)).'@example.com');
            $client = in_array($role, [Role::ADMIN, Role::REGISTRAR_STAFF], true) ? ClientPlatform::DESKTOP : ClientPlatform::WEB;
            $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Password123!'], ['X-CDM-Client' => $client])->assertOk();
        }
    }

    private function user(string $roleName, string $email): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName]);
        $user = User::factory()->create(['role_id' => $role->id, 'password' => Hash::make('Password123!'), 'status' => 'active']);
        $user->profile()->create(['first_name' => 'Google', 'last_name' => 'User', 'gender' => 'Prefer not to say', 'email' => $email]);

        return $user->fresh('profile');
    }
}
