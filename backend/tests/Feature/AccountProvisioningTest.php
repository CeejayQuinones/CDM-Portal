<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Professor;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountAuditWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF, Role::PROFESSOR, Role::STUDENT, Role::GUEST] as $role) {
            Role::query()->firstOrCreate(['role_name' => $role]);
        }
    }

    public function test_admin_creates_one_atomic_registrar_identity_with_a_safe_audit_event(): void
    {
        $admin = $this->user(Role::ADMIN);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/registrars', $this->registrarPayload())
            ->assertCreated()
            ->assertJsonPath('data.account.username', 'new_registrar')
            ->assertJsonPath('data.account.email', 'registrar@cdm.edu.ph')
            ->assertJsonMissingPath('data.account.password');

        $userId = $response->json('data.account.user_id');
        $user = User::query()->findOrFail($userId);
        $this->assertSame(Role::REGISTRAR_STAFF, $user->role->role_name);
        $this->assertTrue(Hash::check('InitialPass123!', $user->password));
        $this->assertTrue($user->is_first_login);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $userId, 'email' => 'registrar@cdm.edu.ph']);
        $this->assertDatabaseHas('registrar_staff', ['user_id' => $userId, 'employee_number' => 'REG-NEW-001']);
        $this->assertDatabaseHas('account_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $userId,
            'action' => 'registrar_account_created',
        ]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_roles_cannot_create_registrar_accounts(string $role): void
    {
        Sanctum::actingAs($this->user($role));
        $this->postJson('/api/admin/registrars', $this->registrarPayload())->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'new_registrar']);
    }

    public static function nonAdminRoles(): array
    {
        return [[Role::REGISTRAR_STAFF], [Role::PROFESSOR], [Role::STUDENT], [Role::GUEST]];
    }

    public function test_unauthenticated_user_cannot_create_registrar_or_professor_accounts(): void
    {
        $this->postJson('/api/admin/registrars', $this->registrarPayload())->assertUnauthorized();
        $this->postJson('/api/registrar/professors', $this->professorPayload())->assertUnauthorized();
    }

    public function test_registrar_creates_one_atomic_professor_identity_with_a_safe_audit_event(): void
    {
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        Sanctum::actingAs($registrar);

        $response = $this->postJson('/api/registrar/professors', $this->professorPayload())
            ->assertCreated()
            ->assertJsonPath('data.account.username', 'new_professor')
            ->assertJsonPath('data.account.department_id', $this->department()->id)
            ->assertJsonMissingPath('data.account.password');

        $userId = $response->json('data.account.user_id');
        $this->assertDatabaseHas('users', ['id' => $userId, 'role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $userId, 'email' => 'professor@cdm.edu.ph']);
        $this->assertDatabaseHas('professors', ['user_id' => $userId, 'employee_number' => 'FAC-NEW-001']);
        $this->assertDatabaseHas('account_audit_events', [
            'actor_user_id' => $registrar->id,
            'subject_user_id' => $userId,
            'action' => 'professor_account_created',
        ]);
    }

    #[DataProvider('nonRegistrarRoles')]
    public function test_non_registrar_roles_cannot_create_professor_accounts(string $role): void
    {
        Sanctum::actingAs($this->user($role));
        $this->postJson('/api/registrar/professors', $this->professorPayload())->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'new_professor']);
    }

    public static function nonRegistrarRoles(): array
    {
        return [[Role::ADMIN], [Role::PROFESSOR], [Role::STUDENT], [Role::GUEST]];
    }

    public function test_privileged_roles_and_protected_identity_fields_cannot_be_injected(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $payload = $this->registrarPayload() + ['role' => Role::ADMIN, 'role_id' => 1, 'user_id' => 99];
        $this->postJson('/api/admin/registrars', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role', 'role_id', 'user_id']);
    }

    public function test_duplicate_username_email_and_role_record_identifiers_are_rejected(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->postJson('/api/admin/registrars', $this->registrarPayload())->assertCreated();

        $this->postJson('/api/admin/registrars', $this->registrarPayload(['email' => 'other@cdm.edu.ph', 'employee_number' => 'REG-NEW-002']))
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/api/admin/registrars', $this->registrarPayload(['username' => 'other_registrar', 'employee_number' => 'REG-NEW-003']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/admin/registrars', $this->registrarPayload(['username' => 'third_registrar', 'email' => 'third@cdm.edu.ph']))
            ->assertUnprocessable()->assertJsonValidationErrors('employee_number');

        $this->assertSame(1, RegistrarStaff::query()->count());
    }

    public function test_audit_failure_rolls_back_the_entire_staff_identity(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->mock(AccountAuditWriter::class)
            ->shouldReceive('record')->once()->andThrow(new RuntimeException('private audit failure'));

        $this->postJson('/api/admin/registrars', $this->registrarPayload())->assertStatus(500);

        $this->assertDatabaseMissing('users', ['username' => 'new_registrar']);
        $this->assertDatabaseMissing('user_profiles', ['email' => 'registrar@cdm.edu.ph']);
        $this->assertSame(0, RegistrarStaff::query()->count());
    }

    public function test_professor_audit_failure_rolls_back_the_entire_academic_identity(): void
    {
        Sanctum::actingAs($this->user(Role::REGISTRAR_STAFF));
        $this->mock(AccountAuditWriter::class)
            ->shouldReceive('record')->once()->andThrow(new RuntimeException('private audit failure'));

        $this->postJson('/api/registrar/professors', $this->professorPayload())->assertStatus(500);

        $this->assertDatabaseMissing('users', ['username' => 'new_professor']);
        $this->assertDatabaseMissing('user_profiles', ['email' => 'professor@cdm.edu.ph']);
        $this->assertSame(0, Professor::query()->count());
    }

    public function test_status_changes_update_both_account_layers_and_preserve_the_identity(): void
    {
        $admin = $this->user(Role::ADMIN);
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/admin/registrars', $this->registrarPayload())->assertCreated()->json('data.account');

        $this->patchJson('/api/admin/registrars/'.$created['id'].'/status', ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.account.status', 'inactive');

        $this->assertDatabaseHas('users', ['id' => $created['user_id'], 'status' => 'inactive']);
        $this->assertDatabaseHas('registrar_staff', ['id' => $created['id'], 'status' => 'inactive']);
        $this->assertSame(1, User::query()->whereKey($created['user_id'])->count());
    }

    public function test_registrar_can_list_edit_and_deactivate_a_professor_without_changing_the_role(): void
    {
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        Sanctum::actingAs($registrar);
        $created = $this->postJson('/api/registrar/professors', $this->professorPayload())->assertCreated()->json('data.account');

        $this->getJson('/api/registrar/professors?search=FAC-NEW-001')
            ->assertOk()->assertJsonPath('data.accounts.total', 1);

        $payload = $this->professorPayload([
            'username' => 'updated_professor',
            'email' => 'updated.professor@cdm.edu.ph',
            'position' => 'Assistant Professor',
        ]);
        unset($payload['password'], $payload['password_confirmation'], $payload['status']);
        $this->putJson('/api/registrar/professors/'.$created['id'], $payload)
            ->assertOk()
            ->assertJsonPath('data.account.username', 'updated_professor')
            ->assertJsonPath('data.account.position', 'Assistant Professor');

        $this->patchJson('/api/registrar/professors/'.$created['id'].'/status', ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.account.account_status', 'inactive');

        $user = User::query()->findOrFail($created['user_id']);
        $this->assertSame(Role::PROFESSOR, $user->role->role_name);
        $this->assertDatabaseHas('professors', ['id' => $created['id'], 'user_id' => $created['user_id'], 'status' => 'inactive']);
    }

    public function test_public_signup_rejects_privileged_role_fields(): void
    {
        $this->postJson('/api/register', ['role' => Role::ADMIN, 'role_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role', 'role_id']);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('role_name', $role)->value('id'),
            'status' => 'active',
        ]);
    }

    private function department(): Department
    {
        return Department::query()->firstOrCreate(
            ['department_code' => 'IT'],
            ['department_name' => 'Information Technology', 'status' => 'active'],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function registrarPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'new_registrar',
            'email' => 'Registrar@CDM.edu.ph',
            'password' => 'InitialPass123!',
            'password_confirmation' => 'InitialPass123!',
            'first_name' => 'Rina',
            'last_name' => 'Registrar',
            'gender' => 'Female',
            'employee_number' => 'REG-NEW-001',
            'position' => 'Registrar Staff',
            'employment_status' => 'regular',
            'status' => 'active',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function professorPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'new_professor',
            'email' => 'Professor@CDM.edu.ph',
            'password' => 'InitialPass123!',
            'password_confirmation' => 'InitialPass123!',
            'first_name' => 'Paolo',
            'last_name' => 'Professor',
            'gender' => 'Male',
            'department_id' => $this->department()->id,
            'employee_number' => 'FAC-NEW-001',
            'position' => 'Instructor',
            'specialization' => 'Software Engineering',
            'employment_status' => 'full_time',
            'status' => 'active',
        ], $overrides);
    }
}
