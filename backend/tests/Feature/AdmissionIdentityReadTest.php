<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionIdentityReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_guest_reads_only_safe_own_identity_without_mutations(): void
    {
        $guest = $this->user(Role::GUEST);
        $application = $this->application($guest);
        $application->forceFill(['source_system' => 'private-source', 'source_applicant_id' => 'private-id', 'legacy_applicant_number' => 'old-number', 'profile_snapshot' => ['internal' => 'private-value']])->save();
        $before = $this->snapshot();
        Sanctum::actingAs($guest);
        $response = $this->getJson('/api/admission/me')->assertOk()->assertJsonPath('data.has_application', true)
            ->assertJsonPath('data.application.applicant_number', $application->applicant_number)
            ->assertJsonPath('data.application.cycle.name', 'Current intake')
            ->assertJsonPath('data.application.status', 'draft')
            ->assertJsonPath('data.application.is_converted', false);
        $data = $response->json('data.application');
        $this->assertSame(['applicant_number', 'status', 'cycle', 'created_at', 'submitted_at', 'is_converted', 'converted_at'], array_keys($data));
        $this->assertSame(['code', 'name', 'status', 'opens_at', 'closes_at', 'confirmation_closes_at'], array_keys($data['cycle']));
        $this->assertStringNotContainsString('private-', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_foreign_identifiers_cannot_change_the_current_user_scope(): void
    {
        $guest = $this->user(Role::GUEST);
        $other = $this->user(Role::GUEST);
        $foreign = $this->application($other);
        Sanctum::actingAs($guest);
        $this->getJson('/api/admission/me?user_id='.$other->id.'&applicant_id='.$foreign->id.'&cycle_id='.$foreign->cycle_id)
            ->assertOk()->assertExactJson(['success' => true, 'data' => ['has_application' => false, 'application' => null]]);
        $own = $this->application($guest);
        $this->getJson('/api/admission/me?user_id='.$other->id)->assertOk()
            ->assertJsonPath('data.application.applicant_number', $own->applicant_number);
        $this->getJson('/api/admission/'.$foreign->id)->assertNotFound();
    }

    public function test_student_can_read_latest_own_history_without_creation(): void
    {
        $user = $this->user(Role::GUEST);
        $older = $this->application($user);
        $older->status = AdmissionApplicant::WITHDRAWN;
        $older->save();
        $latest = $this->application($user);
        $latest->status = AdmissionApplicant::REJECTED;
        $latest->save();
        $user->role_id = Role::query()->firstOrCreate(['role_name' => Role::STUDENT])->id;
        $user->save();
        $before = $this->snapshot();
        Sanctum::actingAs($user);
        $this->getJson('/api/admission/me')->assertOk()
            ->assertJsonPath('data.application.applicant_number', $latest->applicant_number)
            ->assertJsonPath('data.application.status', AdmissionApplicant::REJECTED);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_no_application_is_safe_and_reads_do_not_create_anything(): void
    {
        foreach ([Role::GUEST, Role::STUDENT] as $role) {
            $user = $this->user($role);
            $before = $this->snapshot();
            Sanctum::actingAs($user);
            $this->getJson('/api/admission/me')->assertOk()
                ->assertExactJson(['success' => true, 'data' => ['has_application' => false, 'application' => null]]);
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_non_applicant_roles_and_suspended_users_are_denied(): void
    {
        foreach ([Role::PROFESSOR, Role::REGISTRAR_STAFF, Role::ADMIN] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/admission/me')->assertForbidden();
        }
        $guest = $this->user(Role::GUEST);
        Sanctum::actingAs($guest);
        User::query()->whereKey($guest->id)->update(['status' => 'suspended']);
        $this->getJson('/api/admission/me')->assertForbidden();
    }

    public function test_unauthenticated_read_is_denied_and_no_write_endpoint_exists(): void
    {
        $this->getJson('/api/admission/me')->assertUnauthorized();
        Sanctum::actingAs($this->user(Role::GUEST));
        $this->postJson('/api/admission/me')->assertStatus(405);
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    public function test_missing_step3_tables_return_unavailable_not_empty_or_database_details(): void
    {
        $guest = $this->user(Role::GUEST);
        // Only this isolated in-memory database is changed.
        Schema::drop('admission_applicants');
        Schema::drop('admission_cycles');
        Sanctum::actingAs($guest);
        $this->getJson('/api/admission/me')->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => 'Unable to load admission information.']);
        $this->assertFalse(Schema::hasTable('admission_applicants'));
        Sanctum::actingAs($this->user(Role::PROFESSOR));
        $this->getJson('/api/admission/me')->assertForbidden();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role_id' => Role::query()->firstOrCreate(['role_name' => $role])->id]);
        $user->profile()->create(['first_name' => 'Applicant', 'last_name' => 'Test', 'gender' => 'Prefer not to say']);

        return $user;
    }

    private function application(User $user): AdmissionApplicant
    {
        $admin = $this->user(Role::ADMIN);
        $year = AcademicYear::query()->firstOrCreate(['school_year' => '2026-2027'], ['start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'status' => 'active']);
        $cycle = AdmissionCycle::query()->create(['code' => 'C-'.Str::random(10), 'name' => 'Current intake', 'academic_year_id' => $year->id,
            'status' => AdmissionCycle::OPEN, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(3), 'confirmation_closes_at' => now()->addDays(7),
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);

        return app(AdmissionIdentityService::class)->create($user, $cycle);
    }

    private function snapshot(): array
    {
        return collect(['users', 'user_profiles', 'students', 'admission_applicants', 'admission_cycles'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
