<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionAuditEvent;
use App\Models\Admission\AdmissionCycle;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionAuditWriter;
use App\Services\Admission\AdmissionIdentityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AdmissionAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();
        parent::tearDown();
    }

    public function test_creation_records_linked_minimal_event_without_changing_other_logs(): void
    {
        [$user, $cycle] = $this->fixture();
        $before = DB::table('document_request_status_changes')->get()->toJson();
        Log::spy();
        $applicant = app(AdmissionIdentityService::class)->create($user, $cycle);
        $event = AdmissionAuditEvent::query()->sole();
        $this->assertTrue($event->actor->is($user));
        $this->assertTrue($event->applicant->is($applicant));
        $this->assertSame('admission.identity_created', $event->action);
        $this->assertSame(['cycle_id' => $cycle->id, 'status' => 'draft', 'version' => 1], $event->metadata);
        $this->assertNotNull($event->created_at);
        $this->assertSame(['id', 'actor_user_id', 'applicant_id', 'action', 'metadata', 'created_at'], array_keys($event->getAttributes()));
        $this->assertStringNotContainsString('private-profile', $event->toJson());
        $this->assertStringNotContainsString($user->password, $event->toJson());
        $this->assertSame($before, DB::table('document_request_status_changes')->get()->toJson());
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_writer_ignores_dirty_fields_and_only_reads_allowlisted_stored_metadata(): void
    {
        [$user, $cycle] = $this->fixture();
        DB::transaction(function () use ($user, $cycle): void {
            $applicant = new AdmissionApplicant;
            $applicant->user()->associate($user);
            $applicant->cycle()->associate($cycle);
            $applicant->profile_snapshot = ['password' => 'private-password', 'token' => 'private-token', 'answers' => 'private-answers'];
            $applicant->save();
            $applicant->version = 999;
            $applicant->status = 'caller-supplied-description';
            $event = app(AdmissionAuditWriter::class)->identityCreated($user, $applicant);
            $this->assertSame(['cycle_id' => $cycle->id, 'status' => 'draft', 'version' => 1], $event->metadata);
            $this->assertStringNotContainsString('private-', $event->toJson());
            $this->assertStringNotContainsString('caller-supplied', $event->toJson());
        });
    }

    public function test_writer_rejects_mismatched_actor_and_writing_outside_transaction(): void
    {
        [$user, $cycle] = $this->fixture();
        $applicant = app(AdmissionIdentityService::class)->create($user, $cycle);
        try {
            app(AdmissionAuditWriter::class)->identityCreated($user, $applicant);
            $this->fail('Audit outside transaction accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('transaction', $exception->getMessage());
        }
        DB::transaction(function () use ($applicant, $cycle): void {
            try {
                app(AdmissionAuditWriter::class)->identityCreated($cycle->createdBy, $applicant);
                $this->fail('Mismatched actor accepted.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('actor', $exception->getMessage());
            }
        });
        $this->assertDatabaseCount('admission_audit_events', 1);
    }

    public function test_audit_failure_rolls_back_both_applicant_and_event(): void
    {
        [$user, $cycle] = $this->fixture();
        $this->app->bind(AdmissionAuditWriter::class, fn () => new class extends AdmissionAuditWriter
        {
            public function identityCreated(User $actor, AdmissionApplicant $applicant): AdmissionAuditEvent
            {
                parent::identityCreated($actor, $applicant);
                throw new RuntimeException('Injected audit failure');
            }
        });
        try {
            app(AdmissionIdentityService::class)->create($user, $cycle);
            $this->fail('Audit failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('admission_applicants', 0);
        $this->assertDatabaseCount('admission_audit_events', 0);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_outer_transaction_rollback_removes_successful_identity_and_audit(): void
    {
        [$user, $cycle] = $this->fixture();
        DB::beginTransaction();
        app(AdmissionIdentityService::class)->create($user, $cycle);
        $this->assertDatabaseCount('admission_audit_events', 1);
        DB::rollBack();
        $this->assertDatabaseCount('admission_applicants', 0);
        $this->assertDatabaseCount('admission_audit_events', 0);
    }

    public function test_duplicate_and_read_requests_do_not_append_events(): void
    {
        [$user, $cycle] = $this->fixture();
        $service = app(AdmissionIdentityService::class);
        $service->create($user, $cycle);
        $before = DB::table('admission_audit_events')->get()->toJson();
        try {
            $service->create($user, $cycle);
            $this->fail('Duplicate was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle', $exception->errors());
        }
        $service->findForCycle($user, $cycle);
        Sanctum::actingAs($user);
        $this->getJson('/api/admission/me')->assertOk();
        $this->assertSame($before, DB::table('admission_audit_events')->get()->toJson());
    }

    public function test_number_collisions_have_bounded_retries_and_no_extra_audit_events(): void
    {
        [$user, $cycle] = $this->fixture();
        $other = $this->user();
        $service = app(AdmissionIdentityService::class);
        $uuid = Str::uuid();
        $next = Str::uuid();
        Str::createUuidsUsingSequence([$uuid, $uuid, $next]);
        $service->create($user, $cycle);
        $this->assertSame('APP-'.$next, $service->create($other, $cycle)->applicant_number);
        $third = $this->user();
        $attempts = 0;
        Str::createUuidsUsing(function () use ($uuid, &$attempts) {
            $attempts++;

            return $uuid;
        });
        try {
            $service->create($third, $cycle);
            $this->fail('Exhausted collisions were accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(3, $attempts);
        }
        $this->assertDatabaseCount('admission_applicants', 2);
        $this->assertDatabaseCount('admission_audit_events', 2);
    }

    public function test_events_are_append_only_through_models_and_migration_is_independent(): void
    {
        [$user, $cycle] = $this->fixture();
        app(AdmissionIdentityService::class)->create($user, $cycle);
        $event = AdmissionAuditEvent::query()->sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $event->action = 'changed';
                    $event->save();
                } else {
                    $event->delete();
                }
                $this->fail('Audit mutation succeeded.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
        $before = DB::table('admission_applicants')->get()->toJson();
        $columns = Schema::getColumnListing('admission_applicants');
        $migration = require database_path('migrations/2026_09_23_000001_create_admission_audit_events_table.php');
        $this->assertCount(2, Schema::getForeignKeys('admission_audit_events'));
        // Reversibility is checked only in this disposable SQLite database.
        $migration->down();
        $this->assertSame($before, DB::table('admission_applicants')->get()->toJson());
        $this->assertSame($columns, Schema::getColumnListing('admission_applicants'));
        $migration->up();
        $this->assertDatabaseCount('admission_audit_events', 0);
    }

    private function user(): User
    {
        $user = User::factory()->create(['role_id' => Role::query()->firstOrCreate(['role_name' => Role::GUEST])->id]);
        $user->profile()->create(['first_name' => 'private-profile', 'last_name' => 'Test', 'gender' => 'Prefer not to say']);

        return $user;
    }

    private function fixture(): array
    {
        $user = $this->user();
        $year = AcademicYear::query()->create(['school_year' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'status' => 'active']);
        $cycle = AdmissionCycle::query()->create(['code' => 'AUDIT-TEST', 'name' => 'Audit test intake', 'academic_year_id' => $year->id,
            'status' => AdmissionCycle::OPEN, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(3), 'confirmation_closes_at' => now()->addDays(7),
            'created_by_user_id' => $this->user()->id, 'updated_by_user_id' => $user->id]);

        return [$user, $cycle];
    }
}
