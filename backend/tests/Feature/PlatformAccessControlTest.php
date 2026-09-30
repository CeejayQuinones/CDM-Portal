<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformAccessControlTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('allowedLoginMatrix')]
    public function test_allowed_role_client_logins_issue_client_bound_tokens(string $role, string $client): void
    {
        $user = $this->user($role);

        $response = $this->login($user, $client)->assertOk();
        $token = PersonalAccessToken::findToken($response->json('data.token'));

        $this->assertNotNull($token);
        $this->assertSame([ClientPlatform::ability($client)], $token->abilities);
    }

    public static function allowedLoginMatrix(): array
    {
        return [
            'desktop registrar' => [Role::REGISTRAR_STAFF, ClientPlatform::DESKTOP],
            'desktop admin' => [Role::ADMIN, ClientPlatform::DESKTOP],
            'web student' => [Role::STUDENT, ClientPlatform::WEB],
            'web professor' => [Role::PROFESSOR, ClientPlatform::WEB],
            'web admin' => [Role::ADMIN, ClientPlatform::WEB],
            'web guest' => [Role::GUEST, ClientPlatform::WEB],
            'mobile student' => [Role::STUDENT, ClientPlatform::MOBILE],
        ];
    }

    #[DataProvider('deniedLoginMatrix')]
    public function test_forbidden_role_client_logins_are_denied_without_a_token(
        string $role,
        string $client,
        string $message,
    ): void {
        $user = $this->user($role);

        $this->login($user, $client)
            ->assertForbidden()
            ->assertJsonPath('message', $message);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->last_login);
    }

    public static function deniedLoginMatrix(): array
    {
        return [
            'desktop student' => [Role::STUDENT, ClientPlatform::DESKTOP, 'Student accounts cannot access the Registrar Desktop application.'],
            'desktop professor' => [Role::PROFESSOR, ClientPlatform::DESKTOP, 'This account is not permitted to use the Desktop client.'],
            'desktop guest' => [Role::GUEST, ClientPlatform::DESKTOP, 'This account is not permitted to use the Desktop client.'],
            'web registrar' => [Role::REGISTRAR_STAFF, ClientPlatform::WEB, 'Registrar Staff accounts are available only through the CDM Desktop application.'],
            'mobile registrar' => [Role::REGISTRAR_STAFF, ClientPlatform::MOBILE, 'Registrar Staff accounts are available only through the CDM Desktop application.'],
            'mobile admin' => [Role::ADMIN, ClientPlatform::MOBILE, 'This account is not permitted to use the Student mobile application.'],
            'mobile professor' => [Role::PROFESSOR, ClientPlatform::MOBILE, 'This account is not permitted to use the Student mobile application.'],
            'mobile guest' => [Role::GUEST, ClientPlatform::MOBILE, 'This account is not permitted to use the Student mobile application.'],
        ];
    }

    public function test_login_rejects_missing_and_invalid_client_identifiers(): void
    {
        $user = $this->user(Role::STUDENT);
        $payload = ['username' => $user->username, 'password' => 'Password123!'];

        $this->withoutAutomaticClientPlatform()->postJson('/api/login', $payload)
            ->assertBadRequest()
            ->assertJsonPath('message', 'The X-CDM-Client header is required.');

        $this->postJson('/api/login', $payload, [ClientPlatform::HEADER => 'tablet'])
            ->assertBadRequest()
            ->assertJsonPath('message', 'The X-CDM-Client header must be web, desktop, or mobile.');

        $this->postJson('/api/login', $payload, [ClientPlatform::HEADER => 'WEB'])
            ->assertBadRequest();

        $this->assertSame(0, $user->tokens()->count());
    }

    #[DataProvider('boundTokenMatrix')]
    public function test_tokens_work_only_with_the_client_that_issued_them(
        string $role,
        string $issuedClient,
        string $replayedClient,
    ): void {
        $user = $this->user($role);
        $token = $this->login($user, $issuedClient)->assertOk()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/me', [ClientPlatform::HEADER => $issuedClient])
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/me', [ClientPlatform::HEADER => $replayedClient])
            ->assertForbidden()
            ->assertJsonPath('message', 'This session is not valid for the selected CDM client.');
    }

    public static function boundTokenMatrix(): array
    {
        return [
            'web token replayed on desktop' => [Role::ADMIN, ClientPlatform::WEB, ClientPlatform::DESKTOP],
            'desktop token replayed on web' => [Role::ADMIN, ClientPlatform::DESKTOP, ClientPlatform::WEB],
            'mobile token replayed on web' => [Role::STUDENT, ClientPlatform::MOBILE, ClientPlatform::WEB],
        ];
    }

    public function test_authenticated_requests_reject_missing_or_invalid_clients(): void
    {
        $user = $this->user(Role::STUDENT);
        $token = $user->createToken('web', ['client:web'])->plainTextToken;

        $this->withoutAutomaticClientPlatform()->withToken($token)->getJson('/api/me')
            ->assertBadRequest();

        $this->withToken($token)->getJson('/api/me', [ClientPlatform::HEADER => 'tablet'])
            ->assertBadRequest();
    }

    #[DataProvider('blockedAccountStatuses')]
    public function test_inactive_and_suspended_accounts_cannot_log_in(string $status): void
    {
        $user = $this->user(Role::STUDENT, $status);

        $this->login($user, ClientPlatform::WEB)->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public static function blockedAccountStatuses(): array
    {
        return [['inactive'], ['suspended']];
    }

    public function test_an_existing_token_is_blocked_after_the_account_is_suspended(): void
    {
        $user = $this->user(Role::STUDENT);
        $token = $this->login($user, ClientPlatform::WEB)->assertOk()->json('data.token');
        $user->update(['status' => 'suspended']);

        $this->withToken($token)
            ->getJson('/api/me', [ClientPlatform::HEADER => ClientPlatform::WEB])
            ->assertForbidden();
    }

    public function test_a_valid_client_does_not_bypass_existing_role_authorization(): void
    {
        $student = $this->user(Role::STUDENT);
        $token = $this->login($student, ClientPlatform::WEB)->assertOk()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/students', [ClientPlatform::HEADER => ClientPlatform::WEB])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');
    }

    private function user(string $roleName, string $status = 'active'): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName]);

        return User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('Password123!'),
            'status' => $status,
        ]);
    }

    private function login(User $user, string $client)
    {
        return $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ], [ClientPlatform::HEADER => $client]);
    }
}
