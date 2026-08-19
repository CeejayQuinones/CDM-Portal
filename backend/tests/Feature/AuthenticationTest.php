<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_user_can_log_in_view_their_profile_and_log_out(): void
    {
        $user = $this->createActiveUser();

        $loginResponse = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.username', $user->username)
            ->assertJsonPath('data.user.is_first_login', true);

        $token = $loginResponse->json('data.token');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.role.role_name', Role::STUDENT);

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_a_first_login_password_change_updates_the_password_and_flag(): void
    {
        $user = $this->createActiveUser();

        $token = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/change-password', [
                'current_password' => 'Password123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_first_login', false);

        $user->refresh();

        $this->assertFalse($user->is_first_login);
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
    }

    public function test_invalid_credentials_use_the_authentication_error_envelope(): void
    {
        $response = $this->postJson('/api/login', [
            'username' => 'unknown-user',
            'password' => 'Password123!',
        ]);

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid username or password.',
            ]);
    }

    public function test_a_guest_can_register_and_log_in(): void
    {
        $registration = $this->postJson('/api/register', [
            'username' => 'guest-user',
            'email' => 'guest@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'first_name' => 'Guest',
            'last_name' => 'User',
            'gender' => 'Prefer not to say',
        ]);

        $registration
            ->assertCreated()
            ->assertJsonPath('data.user.username', 'guest-user')
            ->assertJsonPath('data.user.role.role_name', Role::GUEST)
            ->assertJsonPath('data.user.profile.email', 'guest@example.com');

        $this->postJson('/api/login', [
            'username' => 'guest-user',
            'password' => 'Password123!',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role.role_name', Role::GUEST);
    }

    public function test_guest_registration_returns_validation_errors_for_duplicate_identity_fields(): void
    {
        $user = $this->createActiveUser();
        $user->profile()->create([
            'first_name' => 'Existing',
            'last_name' => 'User',
            'gender' => 'Prefer not to say',
            'email' => 'existing@example.com',
        ]);

        $this->postJson('/api/register', [
            'username' => $user->username,
            'email' => 'existing@example.com',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
            'first_name' => '',
            'last_name' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email', 'password', 'first_name', 'last_name', 'gender']);
    }

    private function createActiveUser(): User
    {
        $role = Role::query()->create([
            'role_name' => Role::STUDENT,
            'description' => 'Student role for authentication tests.',
        ]);

        return User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'is_first_login' => true,
        ]);
    }
}
