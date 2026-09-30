<?php

namespace Tests\Feature;

use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrarSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_and_admin_can_open_their_own_settings(): void
    {
        $registrar = $this->staff(Role::REGISTRAR_STAFF);
        $this->actingAs($registrar)->getJson('/api/registrar/settings')->assertOk()
            ->assertJsonPath('data.profile.first_name', 'Regina')
            ->assertJsonPath('data.official.employee_number', 'REG-'.$registrar->id)
            ->assertJsonPath('data.account.role', Role::REGISTRAR_STAFF);

        $admin = $this->staff(Role::ADMIN);
        $this->actingAs($admin)->getJson('/api/registrar/settings')->assertOk()
            ->assertJsonPath('data.account.role', Role::ADMIN);
    }

    public function test_registrar_can_update_only_safe_profile_fields_for_self(): void
    {
        $registrar = $this->staff(Role::REGISTRAR_STAFF);
        $other = $this->staff(Role::REGISTRAR_STAFF);

        $this->actingAs($registrar)->patchJson('/api/registrar/settings/profile', [
            'first_name' => 'Revised', 'middle_name' => 'M', 'last_name' => 'Registrar', 'suffix' => 'III',
            'user_id' => $other->id, 'role_id' => $other->role_id, 'employee_number' => 'HACKED', 'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.profile.first_name', 'Revised');
        $this->actingAs($registrar)->patchJson('/api/registrar/settings/contact', [
            'email' => 'registrar@example.test', 'contact_number' => '09170000000', 'address' => 'Montalban',
            'user_id' => $other->id, 'employee_number' => 'HACKED',
        ])->assertOk();

        $this->assertDatabaseHas('user_profiles', ['user_id' => $registrar->id, 'first_name' => 'Revised', 'email' => 'registrar@example.test']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $other->id, 'first_name' => 'Regina']);
        $this->assertDatabaseHas('registrar_staff', ['user_id' => $registrar->id, 'employee_number' => 'REG-'.$registrar->id]);
        $this->assertSame(Role::REGISTRAR_STAFF, $registrar->fresh()->role->role_name);
        $this->assertSame('active', $registrar->fresh()->status);
    }

    public function test_registrar_avatar_uses_own_user_profile(): void
    {
        Storage::fake('public');
        $registrar = $this->staff(Role::REGISTRAR_STAFF);

        $response = $this->actingAs($registrar)->post('/api/registrar/settings/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.webp'),
        ])->assertOk();
        $path = $registrar->profile()->firstOrFail()->profile_photo;
        Storage::disk('public')->assertExists($path);
        $response->assertJsonPath('data.profile.avatar_url', '/storage/'.$path);

        $this->actingAs($registrar)->deleteJson('/api/registrar/settings/avatar')->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_existing_password_flow_requires_current_password_for_registrar(): void
    {
        $registrar = $this->staff(Role::REGISTRAR_STAFF);

        $this->actingAs($registrar)->postJson('/api/change-password', [
            'current_password' => 'Password123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();
        $this->assertTrue(Hash::check('NewPassword123!', $registrar->fresh()->password));

        $this->actingAs($registrar)->postJson('/api/change-password', [
            'current_password' => 'wrong',
            'password' => 'OtherPassword123!',
            'password_confirmation' => 'OtherPassword123!',
        ])->assertUnprocessable();
    }

    public function test_other_roles_cannot_access_registrar_settings(): void
    {
        foreach ([Role::STUDENT, Role::PROFESSOR, Role::GUEST] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/registrar/settings')->assertForbidden();
        }
    }

    public function test_registrar_settings_preserve_platform_rules(): void
    {
        $registrar = $this->staff(Role::REGISTRAR_STAFF);

        $this->withoutAutomaticClientPlatform()->actingAs($registrar)
            ->withHeader('X-CDM-Client', ClientPlatform::DESKTOP)
            ->getJson('/api/registrar/settings')->assertOk();
        $this->withHeader('X-CDM-Client', ClientPlatform::WEB)
            ->getJson('/api/registrar/settings')->assertForbidden();
        $this->withHeader('X-CDM-Client', ClientPlatform::MOBILE)
            ->getJson('/api/registrar/settings')->assertForbidden();
    }

    public function test_registrar_settings_routes_do_not_accept_a_target_user_identifier(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $this->assertFalse($routes->contains(fn ($route) => str_contains($route->uri(), 'registrar/settings/{')));
    }

    private function staff(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);
        $profile = UserProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Regina',
            'last_name' => 'Registrar',
            'gender' => 'Prefer not to say',
        ]);

        if ($roleName === Role::REGISTRAR_STAFF) {
            RegistrarStaff::query()->create([
                'user_id' => $user->id,
                'user_profile_id' => $profile->id,
                'employee_number' => 'REG-'.$user->id,
                'position' => 'Registrar Staff',
                'employment_status' => 'regular',
                'status' => 'active',
            ]);
        }

        return $user;
    }
}
