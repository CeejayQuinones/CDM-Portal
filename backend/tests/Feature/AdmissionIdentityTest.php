<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionIdentityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdmissionIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Only migrate this test's disposable in-memory database; never use migrate:fresh.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();
        parent::tearDown();
    }

    public function test_guest_identity_reuses_user_profile_and_leaves_academic_student_empty(): void
    {
        $user = $this->user();
        $cycle = $this->cycle();
        $before = $this->portalSnapshot();
        $service = app(AdmissionIdentityService::class);
        $this->assertNull($service->findForCycle($user, $cycle));
        $application = $service->create($user, $cycle)->fresh();
        $this->assertSame($before, $this->portalSnapshot());
        $this->assertTrue($application->user->is($user));
        $this->assertTrue($application->user->profile->is($user->profile));
        $this->assertTrue($application->cycle->is($cycle));
        $this->assertTrue($user->admissionApplications->sole()->is($application));
        $this->assertTrue($cycle->applicants->sole()->is($application));
        $this->assertTrue($cycle->createdBy->is($cycle->updatedBy));
        $this->assertNotNull($cycle->academicYear);
        $this->assertSame(AdmissionApplicant::DRAFT, $application->status);
        $this->assertNull($application->converted_student_id);
        $this->assertNull($application->converted_at);
        $this->assertNull($application->profile_snapshot);
        $this->assertNull($application->contact_verified_at);
        $this->assertTrue($service->findForCycle($user, $cycle)->is($application));
        $this->assertTrue(Gate::forUser($user)->allows('view', $application));
        $this->assertMatchesRegularExpression('/^APP-[0-9a-f-]{36}$/', $application->applicant_number);
        $this->assertDatabaseCount('students', 0);
        $this->assertSame(Role::GUEST, $user->fresh()->role->role_name);
    }

    public function test_duplicate_application_for_same_cycle_is_rejected(): void
    {
        $user = $this->user();
        $cycle = $this->cycle();
        $service = app(AdmissionIdentityService::class);
        $service->create($user, $cycle);
        try {
            $service->create($user, $cycle);
            $this->fail('A duplicate application was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle', $exception->errors());
        }
        $this->assertDatabaseCount('admission_applicants', 1);
    }

    public function test_active_case_blocks_another_cycle_and_closed_history_is_preserved(): void
    {
        $user = $this->user();
        $cycle = $this->cycle();
        $otherCycle = $this->cycle();
        $service = app(AdmissionIdentityService::class);
        $first = $service->create($user, $cycle);
        try {
            $service->create($user, $otherCycle);
            $this->fail('A second active case was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle', $exception->errors());
        }
        // Fixture history, not a production withdrawal workflow.
        $first->status = AdmissionApplicant::WITHDRAWN;
        $first->save();
        $second = $service->create($user, $otherCycle);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('admission_applicants', 2);
        $this->expectException(ValidationException::class);
        $service->create($user, $cycle);
    }

    public function test_different_users_get_distinct_identities_and_cannot_read_each_other(): void
    {
        $one = $this->user();
        $two = $this->user();
        $cycle = $this->cycle();
        $service = app(AdmissionIdentityService::class);
        $first = $service->create($one, $cycle);
        $second = $service->create($two, $cycle);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->applicant_number, $second->applicant_number);
        $this->assertFalse(Gate::forUser($two)->allows('view', $first));
        $this->assertTrue($service->findForCycle($two, $cycle)->is($second));
    }

    public function test_number_collision_is_retried_without_partial_identity(): void
    {
        $cycle = $this->cycle();
        $one = $this->user();
        $two = $this->user();
        $uuid = Str::uuid();
        $next = Str::uuid();
        Str::createUuidsUsingSequence([$uuid, $uuid, $next]);
        $service = app(AdmissionIdentityService::class);
        $this->assertSame('APP-'.$uuid, $service->create($one, $cycle)->applicant_number);
        $this->assertSame('APP-'.$next, $service->create($two, $cycle)->applicant_number);
        $this->assertDatabaseCount('admission_applicants', 2);
    }

    public function test_number_cannot_be_supplied_or_changed_by_callers(): void
    {
        $user = $this->user();
        $cycle = $this->cycle();
        $applicant = new AdmissionApplicant;
        $applicant->user()->associate($user);
        $applicant->cycle()->associate($cycle);
        $applicant->applicant_number = 'CLIENT-CHOSEN';
        $applicant->save();
        $this->assertStringStartsWith('APP-', $applicant->applicant_number);
        $applicant->applicant_number = 'REPLACEMENT';
        $this->expectException(ValidationException::class);
        $applicant->save();
    }

    public function test_database_rejects_duplicate_owner_cycle_and_duplicate_numbers(): void
    {
        $user = $this->user();
        $cycle = $this->cycle();
        $application = app(AdmissionIdentityService::class)->create($user, $cycle);
        $other = $this->user();
        foreach ([['user_id' => $user->id, 'applicant_number' => 'APP-'.Str::uuid()],
            ['user_id' => $other->id, 'applicant_number' => $application->applicant_number]] as $collision) {
            try {
                DB::table('admission_applicants')->insert([...$collision, 'cycle_id' => $cycle->id]);
                $this->fail('Database unique constraint did not reject a collision.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('admission_applicants', 1);
    }

    public function test_only_active_guests_can_create_and_roles_have_separate_boundaries(): void
    {
        $cycle = $this->cycle();
        $guest = $this->user();
        $application = app(AdmissionIdentityService::class)->create($guest, $cycle);
        foreach ([Role::STUDENT, Role::PROFESSOR, Role::REGISTRAR_STAFF, Role::ADMIN] as $role) {
            $user = $this->user($role);
            $this->assertFalse(Gate::forUser($user)->allows('create', AdmissionApplicant::class));
            $this->assertSame($role === Role::REGISTRAR_STAFF, Gate::forUser($user)->allows('view', $application));
            $this->assertSame($role === Role::ADMIN, Gate::forUser($user)->allows('configure', AdmissionCycle::class));
            try {
                app(AdmissionIdentityService::class)->create($user, $cycle);
                $this->fail('Unauthorized creation succeeded.');
            } catch (AuthorizationException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        // Future role change retains access to one's own application history only.
        $guest->role_id = Role::query()->where('role_name', Role::STUDENT)->value('id');
        $guest->save();
        $this->assertTrue(Gate::forUser($guest->fresh())->allows('view', $application));
        $this->assertTrue(app(AdmissionIdentityService::class)->findForCycle($guest, $cycle)->is($application));
        $this->assertDatabaseCount('students', 0);
    }

    public function test_inactive_or_stale_guest_credentials_cannot_create_or_find_cases(): void
    {
        $user = $this->user();
        $cycle = $this->cycle();
        $user->load('role');
        User::query()->whereKey($user->id)->update(['status' => 'suspended']);
        foreach (['create', 'findForCycle'] as $method) {
            try {
                app(AdmissionIdentityService::class)->{$method}($user, $cycle);
                $this->fail('Stale account authorization was trusted.');
            } catch (AuthorizationException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    public function test_professor_cannot_find_applicant_history(): void
    {
        $professor = $this->user(Role::PROFESSOR);
        $cycle = $this->cycle();
        $this->expectException(AuthorizationException::class);
        app(AdmissionIdentityService::class)->findForCycle($professor, $cycle);
    }

    public function test_profile_is_required_and_cycle_must_be_open_in_its_date_window(): void
    {
        $user = $this->user();
        $cycle = $this->cycle();
        foreach ([['status' => AdmissionCycle::CLOSED], ['status' => AdmissionCycle::OPEN, 'opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]] as $changes) {
            $cycle->update($changes);
            try {
                app(AdmissionIdentityService::class)->create($user, $cycle);
                $this->fail('Closed intake accepted an application.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cycle', $exception->errors());
            }
        }
        $openCycle = $this->cycle();
        $user->profile()->delete();
        $this->expectException(ValidationException::class);
        app(AdmissionIdentityService::class)->create($user, $openCycle);
    }

    public function test_cycle_dates_and_in_use_code_are_validated(): void
    {
        $cycle = $this->cycle();
        $cycle->closes_at = $cycle->opens_at;
        try {
            $cycle->save();
            $this->fail('Unordered dates were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('closes_at', $exception->errors());
        }
        $cycle->refresh();
        app(AdmissionIdentityService::class)->create($this->user(), $cycle);
        $cycle->code = 'CHANGED';
        $this->expectException(ValidationException::class);
        $cycle->save();
    }

    public function test_ownership_and_history_cannot_be_reassigned_or_deleted_with_user(): void
    {
        $user = $this->user();
        $application = app(AdmissionIdentityService::class)->create($user, $this->cycle());
        $application->user_id = $this->user()->id;
        try {
            $application->save();
            $this->fail('Ownership changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('applicant_number', $exception->errors());
        }
        $this->expectException(QueryException::class);
        $user->delete();
    }

    public function test_new_migrations_are_additive_and_reversible_around_existing_data(): void
    {
        $student = $this->academicStudent();
        $student->createToken('Existing portal token');
        $this->cycle();
        $before = $this->portalSnapshot();
        $protected = ['users', 'user_profiles', 'students', 'personal_access_tokens'];
        $columns = array_map(fn ($table) => Schema::getColumnListing($table), $protected);
        $applicants = require database_path('migrations/2026_09_19_000002_create_admission_applicants_table.php');
        $cycles = require database_path('migrations/2026_09_19_000001_create_admission_cycles_table.php');
        $applicants->down();
        $cycles->down();
        $tables = Schema::getTableListing();
        $cycles->up();
        $applicants->up();
        $added = array_map(fn ($name) => Str::afterLast($name, '.'), array_values(array_diff(Schema::getTableListing(), $tables)));
        sort($added);
        $this->assertSame(['admission_applicants', 'admission_cycles'], $added);
        $this->assertSame($before, $this->portalSnapshot());
        $this->assertSame($columns, array_map(fn ($table) => Schema::getColumnListing($table), $protected));
        $this->assertCount(6, Schema::getForeignKeys('admission_applicants'));
        $this->assertCount(3, Schema::getForeignKeys('admission_cycles'));
    }

    public function test_existing_academic_record_blocks_even_a_guest_role_from_creating(): void
    {
        $user = $this->academicStudent();
        $user->role_id = Role::query()->firstOrCreate(['role_name' => Role::GUEST])->id;
        $user->save();
        $cycle = $this->cycle();
        $this->expectException(AuthorizationException::class);
        app(AdmissionIdentityService::class)->create($user, $cycle);
    }

    public function test_academic_status_and_incomplete_conversion_link_are_rejected(): void
    {
        $application = app(AdmissionIdentityService::class)->create($this->user(), $this->cycle());
        foreach (['regular', AdmissionApplicant::CONVERTED] as $status) {
            $application->status = $status;
            try {
                $application->save();
                $this->fail('An invalid identity state was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(AdmissionApplicant::DRAFT, $application->fresh()->status);
        $this->assertDatabaseCount('students', 0);
    }

    private function academicStudent(): User
    {
        $user = $this->user(Role::STUDENT);
        $department = DB::table('departments')->insertGetId(['department_code' => 'TEST', 'department_name' => 'Existing department']);
        $course = DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => 'TEST', 'course_name' => 'Existing course']);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'TEST-2026', 'curriculum_name' => 'Existing curriculum', 'effective_year' => 2026]);
        DB::table('students')->insert(['user_id' => $user->id, 'user_profile_id' => $user->profile->id, 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => '26-TEST', 'admission_date' => '2026-06-01']);

        return $user;
    }

    private function user(string $roleName = Role::GUEST): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->profile()->create(['first_name' => 'Applicant', 'last_name' => 'Test', 'gender' => 'Prefer not to say', 'email' => $user->username.'@example.test']);

        return $user;
    }

    private function cycle(): AdmissionCycle
    {
        $year = AcademicYear::query()->firstOrCreate(['school_year' => '2026-2027'], ['start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'status' => 'active']);
        $admin = $this->user(Role::ADMIN);

        return AdmissionCycle::query()->create(['code' => 'C-'.Str::random(10), 'name' => 'Test intake', 'academic_year_id' => $year->id, 'status' => AdmissionCycle::OPEN,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(3), 'confirmation_closes_at' => now()->addDays(7),
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);
    }

    private function portalSnapshot(): array
    {
        return collect(['users', 'user_profiles', 'students', 'personal_access_tokens'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
