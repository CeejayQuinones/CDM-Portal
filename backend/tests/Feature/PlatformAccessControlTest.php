<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRoleAssignment;
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
        $plainTextToken = $response->json('data.token');
        $token = PersonalAccessToken::findToken($plainTextToken);

        $this->assertNotNull($token);
        $this->assertSame([ClientPlatform::ability($client)], $token->abilities);
        $this->withToken($plainTextToken)
            ->getJson('/api/me', [ClientPlatform::HEADER => $client])
            ->assertOk();
    }

    public static function allowedLoginMatrix(): array
    {
        return [
            'desktop registrar' => [Role::REGISTRAR_STAFF, ClientPlatform::DESKTOP],
            'desktop admin' => [Role::ADMIN, ClientPlatform::DESKTOP],
            'web guest' => [Role::GUEST, ClientPlatform::WEB],
            'web professor' => [Role::PROFESSOR, ClientPlatform::WEB],
            'mobile professor' => [Role::PROFESSOR, ClientPlatform::MOBILE],
            'web student' => [Role::STUDENT, ClientPlatform::WEB],
            'mobile student' => [Role::STUDENT, ClientPlatform::MOBILE],
            'mobile guest' => [Role::GUEST, ClientPlatform::MOBILE],
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
            ->assertJsonPath('message', $message)
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->last_login);
    }

    public static function deniedLoginMatrix(): array
    {
        return [
            'desktop professor' => [Role::PROFESSOR, ClientPlatform::DESKTOP, 'This account is not permitted to use the Desktop client.'],
            'desktop student' => [Role::STUDENT, ClientPlatform::DESKTOP, 'Student accounts cannot access the Registrar Desktop application.'],
            'desktop guest' => [Role::GUEST, ClientPlatform::DESKTOP, 'This account is not permitted to use the Desktop client.'],
            'web admin' => [Role::ADMIN, ClientPlatform::WEB, 'Admin accounts are available only through the CDM Desktop application.'],
            'web registrar' => [Role::REGISTRAR_STAFF, ClientPlatform::WEB, 'Registrar Staff accounts are available only through the CDM Desktop application.'],
            'mobile registrar' => [Role::REGISTRAR_STAFF, ClientPlatform::MOBILE, 'Registrar Staff accounts are available only through the CDM Desktop application.'],
            'mobile admin' => [Role::ADMIN, ClientPlatform::MOBILE, 'Admin accounts are available only through the CDM Desktop application.'],
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

    public function test_professor_semi_coordinator_follows_the_professor_platform_matrix(): void
    {
        $admin = $this->user(Role::ADMIN);
        $professor = $this->user(Role::PROFESSOR);
        $event = Event::query()->create([
            'title' => 'Semi-Coordinator Platform Event',
            'venue' => 'Main Hall',
            'venue_key' => 'main hall',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'published',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        EventRoleAssignment::query()->create([
            'event_id' => $event->id,
            'user_id' => $professor->id,
            'responsibility' => EventRoleAssignment::SEMI_COORDINATOR,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $this->login($professor, ClientPlatform::DESKTOP)->assertForbidden();
        $this->login($professor, ClientPlatform::MOBILE)->assertOk();
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
            'web token replayed on mobile' => [Role::PROFESSOR, ClientPlatform::WEB, ClientPlatform::MOBILE],
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
        $token = $user->createToken('web', [ClientPlatform::ability(ClientPlatform::WEB)])->plainTextToken;
        $user->update(['status' => 'suspended']);

        $this->withToken($token)
            ->getJson('/api/me', [ClientPlatform::HEADER => ClientPlatform::WEB])
            ->assertForbidden();
    }

    public function test_a_valid_client_does_not_bypass_existing_role_authorization(): void
    {
        $student = $this->user(Role::STUDENT);
        $token = $student->createToken('web', [ClientPlatform::ability(ClientPlatform::WEB)])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/students', [ClientPlatform::HEADER => ClientPlatform::WEB])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');
    }

    public function test_admission_routes_enforce_the_token_client_binding(): void
    {
        $admin = $this->user(Role::ADMIN);
        $token = $this->login($admin, ClientPlatform::DESKTOP)->assertOk()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/admission/admin/programs', [ClientPlatform::HEADER => ClientPlatform::WEB])
            ->assertForbidden()
            ->assertJsonPath('message', 'Admin accounts are available only through the CDM Desktop application.');
    }

    public function test_mobile_guest_keeps_admission_access_but_cannot_use_student_or_staff_apis(): void
    {
        $guest = $this->user(Role::GUEST);
        $token = $this->login($guest, ClientPlatform::MOBILE)->assertOk()->json('data.token');
        $request = fn (string $path) => $this->withToken($token)
            ->getJson($path, [ClientPlatform::HEADER => ClientPlatform::MOBILE]);

        $request('/api/admission/applications/availability')->assertOk();
        $request('/api/student/dashboard')->assertForbidden();
        $request('/api/students')->assertForbidden();
        $request('/api/admin/dashboard')->assertForbidden();
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
