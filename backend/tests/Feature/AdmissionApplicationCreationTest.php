<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionAuditWriter;
use App\Services\Admission\AdmissionIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionApplicationCreationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_creation_reuses_identity_is_server_owned_audited_and_duplicate_safe(): void
    {
        $guest = $this->user(Role::GUEST);
        $cycle = $this->cycle();
        Sanctum::actingAs($guest);
        $users = DB::table('users')->count();
        $profiles = DB::table('user_profiles')->count();
        $this->getJson('/api/admission/applications/availability')->assertOk()->assertJsonPath('data.allowed', true);
        $response = $this->postJson('/api/admission/applications', ['user_id' => 999, 'cycle_id' => 999, 'applicant_number' => 'injected'])
            ->assertCreated()->assertJsonPath('data.application.status', 'draft');
        $applicant = AdmissionApplicant::sole();
        $this->assertSame($guest->id, $applicant->user_id);
        $this->assertSame($cycle->id, $applicant->cycle_id);
        $this->assertStringStartsWith('APP-', $applicant->applicant_number);
        $this->assertSame(['applicant_number', 'status', 'cycle', 'created_at', 'submitted_at', 'is_converted', 'converted_at'], array_keys($response->json('data.application')));
        $this->assertDatabaseHas('admission_audit_events', ['applicant_id' => $applicant->id, 'actor_user_id' => $guest->id, 'action' => 'admission.identity_created']);
        $this->postJson('/api/admission/applications')->assertStatus(409)->assertJsonPath('code', 'application_exists');
        $this->assertDatabaseCount('admission_applicants', 1);
        $this->assertDatabaseCount('admission_audit_events', 1);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('users', $users);
        $this->assertDatabaseCount('user_profiles', $profiles);
    }

    public function test_roles_and_stale_suspended_account_are_denied(): void
    {
        $this->postJson('/api/admission/applications')->assertUnauthorized();
        foreach ([Role::STUDENT, Role::PROFESSOR, Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/admission/applications')->assertForbidden();
        }
        $guest = $this->user(Role::GUEST);
        Sanctum::actingAs($guest);
        User::whereKey($guest->id)->update(['status' => 'suspended']);
        $this->postJson('/api/admission/applications')->assertForbidden();
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    public function test_no_closed_future_expired_and_ambiguous_cycles_fail_safely(): void
    {
        Sanctum::actingAs($this->user(Role::GUEST));
        $this->postJson('/api/admission/applications')->assertStatus(422)->assertJsonPath('code', 'no_open_cycle');
        $cycle = $this->cycle();
        foreach ([
            ['status' => 'closed'],
            ['status' => 'open', 'opens_at' => now()->addDay()],
            ['opens_at' => now()->subDays(2), 'closes_at' => now()],
        ] as $attributes) {
            $cycle->update($attributes);
            $this->postJson('/api/admission/applications')->assertStatus(422)->assertJsonPath('code', 'no_open_cycle');
        }
        $cycle->update(['closes_at' => now()->addDays(3)]);
        $this->cycle();
        $this->postJson('/api/admission/applications')->assertStatus(422)->assertJsonPath('code', 'cycle_unavailable');
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    public function test_audit_failure_rolls_back_and_hides_error_even_with_debug_enabled(): void
    {
        $this->cycle();
        Sanctum::actingAs($this->user(Role::GUEST));
        config(['app.debug' => true]);
        $this->mock(AdmissionAuditWriter::class, function ($mock) {
            $mock->shouldReceive('identityCreated')->once()->andThrow(new \RuntimeException('SQLSTATE private failure'));
        });
        $response = $this->postJson('/api/admission/applications')->assertStatus(503)->assertJsonPath('code', 'unavailable');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertDatabaseCount('admission_applicants', 0);
        $this->assertDatabaseCount('admission_audit_events', 0);
    }

    public function test_creation_is_throttled_and_missing_profile_is_safe(): void
    {
        $guest = $this->user(Role::GUEST);
        $guest->profile()->delete();
        Sanctum::actingAs($guest);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admission/applications')->assertStatus(422)->assertJsonPath('code', 'profile_required');
        }
        $this->postJson('/api/admission/applications')->assertStatus(429);
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    public function test_existing_active_case_in_another_cycle_blocks_creation(): void
    {
        $guest = $this->user(Role::GUEST);
        $old = $this->cycle();
        app(AdmissionIdentityService::class)->create($guest, $old);
        $old->update(['status' => AdmissionCycle::CLOSED]);
        $this->cycle();
        Sanctum::actingAs($guest);
        $this->postJson('/api/admission/applications')->assertStatus(409)->assertJsonPath('code', 'application_exists');
        $this->assertDatabaseCount('admission_applicants', 1);
        $this->assertDatabaseCount('admission_audit_events', 1);
    }

    public function test_missing_audit_storage_fails_closed_without_database_details(): void
    {
        $this->cycle();
        Sanctum::actingAs($this->user(Role::GUEST));
        Schema::drop('admission_audit_events');
        config(['app.debug' => true]);
        $response = $this->postJson('/api/admission/applications')->assertStatus(503);
        $this->assertSame(['success', 'code', 'message'], array_keys($response->json()));
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role_id' => Role::query()->firstOrCreate(['role_name' => $role])->id]);
        $user->profile()->create(['first_name' => 'Applicant', 'last_name' => 'Test', 'gender' => 'Prefer not to say']);

        return $user;
    }

    private function cycle(): AdmissionCycle
    {
        $admin = $this->user(Role::ADMIN);
        $year = AcademicYear::query()->firstOrCreate(['school_year' => '2026-2027'], ['start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'status' => 'active']);
        $cycle = AdmissionCycle::query()->create(['code' => 'C-'.Str::random(10), 'name' => 'Current intake', 'academic_year_id' => $year->id,
            'status' => AdmissionCycle::OPEN, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(3), 'confirmation_closes_at' => now()->addDays(7),
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);

        return $cycle;
    }
}
