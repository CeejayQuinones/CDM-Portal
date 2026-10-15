<?php

namespace Tests\Feature;

use App\Mail\DocumentRequestApprovedMail;
use App\Models\Appointment;
use App\Models\AppointmentBlockedDate;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentRequestAppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_student_creates_pending_request_and_cannot_assign_or_verify(): void
    {
        $student = $this->createStudent('26-10001');
        Sanctum::actingAs($student->user);
        $response = $this->postJson('/api/document-requests', ['document_type_id' => $this->type()->id, 'quantity' => 1, 'purpose' => 'Employment'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $id = $response->json('data.id');
        $this->postJson("/api/registrar/document-requests/{$id}/appointment", ['appointment_date' => '2026-09-08'])->assertForbidden();
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '123456'])->assertForbidden();
        $this->postJson("/api/document-requests/{$id}/appointments", ['appointment_date' => '2026-09-08'])->assertNotFound();
    }

    public function test_registrar_assigns_date_without_approving_and_invalid_dates_are_rejected(): void
    {
        $request = $this->requestFor($this->createStudent('26-10002'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-06'])->assertUnprocessable();
        AppointmentBlockedDate::create(['blocked_date' => '2026-09-08', 'type' => 'office_closure', 'reason' => 'Closed', 'is_active' => true]);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-08'])->assertUnprocessable();
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-09'])
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $appointment = Appointment::query()->where('document_request_id', $request->id)->firstOrFail();
        $this->assertSame('2026-09-09', $appointment->appointment_date->toDateString());
        $this->assertSame('pending', $appointment->status);
    }

    public function test_capacity_is_enforced_and_a_date_override_works(): void
    {
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        foreach (range(1, 5) as $index) {
            $request = $this->requestFor($this->createStudent("26-1010{$index}"));
            $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-09'])->assertOk();
        }
        $sixth = $this->requestFor($this->createStudent('26-10106'));
        $this->postJson("/api/registrar/document-requests/{$sixth->id}/appointment", ['appointment_date' => '2026-09-09'])->assertUnprocessable();
        $this->putJson('/api/registrar/appointment-availability/capacity/2026-09-09', ['capacity' => 6])->assertOk();
        $this->postJson("/api/registrar/document-requests/{$sixth->id}/appointment", ['appointment_date' => '2026-09-09'])->assertOk();
    }

    public function test_approval_requires_appointment_hashes_code_and_does_not_expose_it(): void
    {
        Mail::fake();
        $request = $this->requestFor($this->createStudent('26-10003'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertUnprocessable();
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-07'])->assertOk();
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.verification_code_hash')->assertJsonMissingPath('data.verification_code_lookup');
        $this->assertDatabaseHas('appointments', ['document_request_id' => $request->id, 'status' => 'confirmed']);
        $this->assertNotNull($request->fresh()->verification_code_hash);
        Mail::assertSent(DocumentRequestApprovedMail::class);
    }

    public function test_claim_code_is_single_use_and_completion_is_atomic(): void
    {
        Mail::fake();
        $request = $this->requestFor($this->createStudent('26-10004'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-07'])->assertOk();
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertOk();
        $code = null;
        Mail::assertSent(DocumentRequestApprovedMail::class, function ($mail) use (&$code): bool {
            $code = $mail->claimCode;

            return true;
        });
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'complete'])->assertUnprocessable();
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertOk();
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'complete'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('appointments', ['document_request_id' => $request->id, 'status' => 'completed']);
    }

    public function test_rejection_releases_capacity_and_no_show_command_is_idempotent(): void
    {
        $request = $this->requestFor($this->createStudent('26-10005'));
        $staff = $this->createRegistrar();
        Appointment::create(['student_id' => $request->student_id, 'document_request_id' => $request->id, 'registrar_staff_id' => $staff->id, 'appointment_date' => '2026-09-04', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'pending']);
        Artisan::call('document-requests:cancel-no-shows');
        Artisan::call('document-requests:cancel-no-shows');
        $this->assertDatabaseHas('document_requests', ['id' => $request->id, 'status' => 'cancelled']);
        $this->assertSame(1, $request->statusChanges()->where('action', 'no_show_cancelled')->count());

        $second = $this->requestFor($this->createStudent('26-10006'));
        Appointment::create(['student_id' => $second->student_id, 'document_request_id' => $second->id, 'appointment_date' => '2026-09-09', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'pending']);
        Sanctum::actingAs($staff->user);
        $this->patchJson("/api/registrar/document-requests/{$second->id}", ['action' => 'reject', 'reason' => 'Incomplete supporting record'])->assertOk();
        $this->assertDatabaseHas('appointments', ['document_request_id' => $second->id, 'status' => 'cancelled']);
    }

    public function test_admin_is_not_granted_registrar_workflow_permissions(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '123456'])->assertForbidden();
    }

    public function test_wrong_code_and_non_registrar_claim_actions_are_rejected(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10007'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($student->user);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertForbidden();
        $this->getJson("/api/document-requests/{$request->id}")->assertOk();

        Sanctum::actingAs($staff->user);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The verification code is invalid.');
    }

    public function test_holidays_are_present_in_test_schema_and_are_enforced(): void
    {
        $this->assertTrue(Schema::hasTable('holidays'));
        DB::table('holidays')->insert(['name' => 'College Foundation Day', 'holiday_date' => '2026-09-09', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $request = $this->requestFor($this->createStudent('26-10008'));
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-09'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This date is unavailable due to College Foundation Day.');
    }

    public function test_no_show_leaves_today_verified_and_terminal_requests_unchanged(): void
    {
        $student = $this->createStudent('26-10009');
        $todayRequest = $this->requestFor($student);
        $verifiedRequest = $this->requestFor($student);
        $terminalRequest = $this->requestFor($student);
        $verifiedRequest->update(['status' => 'approved', 'code_verified_at' => now()]);
        $terminalRequest->update(['status' => 'completed', 'completed_at' => now()]);

        Appointment::create(['student_id' => $student->id, 'document_request_id' => $todayRequest->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'pending']);
        Appointment::create(['student_id' => $student->id, 'document_request_id' => $verifiedRequest->id, 'appointment_date' => '2026-09-04', 'appointment_time' => '10:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Appointment::create(['student_id' => $student->id, 'document_request_id' => $terminalRequest->id, 'appointment_date' => '2026-09-04', 'appointment_time' => '11:00', 'purpose' => 'Claim', 'status' => 'completed']);

        Artisan::call('document-requests:cancel-no-shows');
        $this->assertSame('pending', $todayRequest->fresh()->status);
        $this->assertSame('approved', $verifiedRequest->fresh()->status);
        $this->assertSame('completed', $terminalRequest->fresh()->status);
    }

    public function test_history_contains_terminal_states_and_student_results_are_scoped(): void
    {
        $student = $this->createStudent('26-10010');
        $other = $this->createStudent('26-10011');
        $completed = $this->requestFor($student);
        $rejected = $this->requestFor($student);
        $cancelled = $this->requestFor($student);
        $otherRequest = $this->requestFor($other);
        $completed->update(['status' => 'completed', 'completed_at' => now()]);
        $rejected->update(['status' => 'rejected', 'rejected_at' => now()]);
        $cancelled->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson('/api/registrar/document-requests/history?section=requests')
            ->assertOk()
            ->assertJsonFragment(['id' => $completed->id, 'status' => 'completed', 'request_date' => '2026-09-07T00:00:00.000000Z'])
            ->assertJsonFragment(['id' => $rejected->id, 'status' => 'rejected'])
            ->assertJsonFragment(['id' => $cancelled->id, 'status' => 'cancelled']);

        Sanctum::actingAs($student->user);
        $this->getJson('/api/document-requests')->assertOk()->assertJsonMissing(['id' => $otherRequest->id]);
        $this->getJson("/api/document-requests/{$otherRequest->id}")->assertForbidden();
    }

    public function test_verification_endpoint_throttles_brute_force_attempts(): void
    {
        Sanctum::actingAs($this->createRegistrar()->user);
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => str_pad((string) $attempt, 6, '0', STR_PAD_LEFT)])->assertUnprocessable();
        }
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '999999'])->assertTooManyRequests();
    }

    public function test_verification_rejects_every_ineligible_request_and_appointment_state(): void
    {
        $code = '654321';
        $request = $this->requestFor($student = $this->createStudent('26-10012'));
        $request->update(['verification_code_lookup' => hash_hmac('sha256', $code, (string) config('app.key')), 'verification_code_hash' => Hash::make($code)]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($this->createRegistrar()->user);

        foreach (['pending', 'rejected', 'cancelled', 'completed'] as $status) {
            $request->update(['status' => $status, 'code_verified_at' => null]);
            $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        }

        $request->update(['status' => 'approved']);
        $appointment->update(['appointment_date' => '2026-09-08']);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $appointment->update(['appointment_date' => '2026-09-04']);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $appointment->update(['appointment_date' => '2026-09-07', 'status' => 'cancelled']);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $appointment->update(['status' => 'confirmed']);
        $request->update(['code_verified_at' => now()]);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
    }

    public function test_claim_secrets_are_absent_from_all_relevant_api_responses(): void
    {
        $code = '735291';
        $request = $this->requestFor($student = $this->createStudent('26-10013'));
        $request->update(['status' => 'approved', 'approved_at' => now(), 'verification_code_lookup' => hash_hmac('sha256', $code, (string) config('app.key')), 'verification_code_hash' => Hash::make($code)]);
        Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);

        Sanctum::actingAs($student->user);
        $responses = [
            $this->getJson('/api/document-requests')->assertOk(),
            $this->getJson("/api/document-requests/{$request->id}")->assertOk(),
        ];
        Sanctum::actingAs($this->createRegistrar()->user);
        $responses = [...$responses,
            $this->getJson('/api/registrar/document-requests')->assertOk(),
            $this->getJson("/api/registrar/document-requests/{$request->id}")->assertOk(),
            $this->getJson('/api/registrar/appointments')->assertOk(),
            $this->getJson('/api/registrar/document-requests/history')->assertOk(),
            $this->getJson('/api/registrar/dashboard')->assertOk(),
            $this->getJson('/api/registrar/document-request-activity')->assertOk(),
        ];
        foreach ($responses as $response) {
            $payload = $response->getContent();
            $this->assertStringNotContainsString('verification_code_hash', $payload);
            $this->assertStringNotContainsString('verification_code_lookup', $payload);
            $this->assertStringNotContainsString($code, $payload);
        }
    }

    public function test_failed_approval_email_can_be_recovered_with_a_new_registrar_only_code(): void
    {
        $request = $this->requestFor($this->createStudent('26-10014'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-07'])->assertOk();
        Mail::shouldReceive('to')->twice()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        $oldLookup = $request->fresh()->verification_code_lookup;

        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")
            ->assertStatus(503)
            ->assertJsonMissingPath('verification_code_hash')
            ->assertJsonMissingPath('verification_code_lookup');
        $failedDeliveryLookup = $request->fresh()->verification_code_lookup;
        $this->assertNotSame($oldLookup, $failedDeliveryLookup);
        $this->assertSame('approved', $request->fresh()->status);

        Mail::swap(new MailManager($this->app));
        Mail::fake();
        $response = $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.verification_code_hash')->assertJsonMissingPath('data.verification_code_lookup');
        $this->assertNotSame($failedDeliveryLookup, $request->fresh()->verification_code_lookup);
        $this->assertNull($request->fresh()->code_verified_at);
        $this->assertDatabaseHas('document_request_status_changes', ['document_request_id' => $request->id, 'action' => 'claim_code_regenerated']);
        $this->assertStringNotContainsString('claimCode', $response->getContent());
        Mail::assertSent(DocumentRequestApprovedMail::class);

        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertOk();
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertTooManyRequests();
    }

    public function test_resend_is_denied_to_student_admin_verified_and_expired_requests(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10015'));
        $staff = $this->createRegistrar();
        $request->update(['status' => 'approved', 'approved_at' => now(), 'verification_code_lookup' => hash('sha256', 'old'), 'verification_code_hash' => Hash::make('123456')]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($student->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertForbidden();
        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertForbidden();
        Sanctum::actingAs($staff->user);
        $request->update(['code_verified_at' => now()]);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertUnprocessable();
        $request->update(['code_verified_at' => null]);
        $appointment->update(['appointment_date' => '2026-09-04']);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertUnprocessable();
    }

    public function test_verified_cancellation_is_atomic_audited_and_clears_claim_credentials(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10016'));
        $staff = $this->createRegistrar();
        $request->update(['status' => 'approved', 'approved_at' => now(), 'code_verified_at' => now(), 'verification_code_lookup' => hash('sha256', 'old'), 'verification_code_hash' => Hash::make('123456')]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($staff->user);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'cancel', 'reason' => 'Identity could not be confirmed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $request->refresh();
        $this->assertNull($request->verification_code_lookup);
        $this->assertNull($request->verification_code_hash);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertDatabaseHas('document_request_status_changes', ['document_request_id' => $request->id, 'action' => 'cancelled', 'reason' => 'Identity could not be confirmed']);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertUnprocessable();
    }

    public function test_linked_appointment_patch_cannot_bypass_request_verification(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10017'));
        $request->update(['status' => 'approved', 'approved_at' => now()]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->patchJson("/api/registrar/appointments/{$appointment->id}", ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson("/api/registrar/appointments/{$appointment->id}", ['status' => 'cancelled', 'remarks' => 'Bypass attempt'])->assertUnprocessable();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    public function test_completion_rolls_back_request_when_appointment_update_fails(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10018'));
        $request->update(['status' => 'approved', 'approved_at' => now(), 'code_verified_at' => now(), 'verification_code_lookup' => hash('sha256', 'old'), 'verification_code_hash' => Hash::make('123456')]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        DB::statement("CREATE TRIGGER fail_document_appointment_completion BEFORE UPDATE ON appointments WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'forced completion failure'); END");
        Sanctum::actingAs($this->createRegistrar()->user);

        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'complete'])->assertServerError();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertNotNull($request->fresh()->verification_code_hash);
        $this->assertDatabaseMissing('document_request_status_changes', ['document_request_id' => $request->id, 'action' => 'completed']);
    }

    private function type(): DocumentType
    {
        return DocumentType::firstOrCreate(['document_name' => 'Transcript of Records'], ['processing_fee' => 150, 'processing_days' => 3, 'requires_appointment' => true, 'status' => 'active']);
    }

    private function requestFor(Student $student): DocumentRequest
    {
        return DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $this->type()->id, 'quantity' => 1, 'total_fee' => 150, 'purpose' => 'Testing', 'status' => 'pending', 'request_date' => '2026-09-07']);
    }

    private function createStudent(string $number): Student
    {
        DB::table('departments')->insertOrIgnore(['id' => 1, 'department_code' => 'ICS', 'department_name' => 'Institute of Computer Studies', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->userWithRole(Role::STUDENT);
        $profile = UserProfile::create(['user_id' => $user->id, 'first_name' => 'Student', 'last_name' => str_replace('-', '', $number), 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'email' => "{$number}@example.test"]);
        $course = Course::firstOrCreate(['course_code' => 'BSIT'], ['department_id' => 1, 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active']);
        $curriculum = Curriculum::firstOrCreate(['curriculum_code' => 'BSIT-2026'], ['course_id' => $course->id, 'curriculum_name' => 'BSIT Curriculum', 'effective_year' => 2026, 'status' => 'active']);

        return Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $course->id, 'curriculum_id' => $curriculum->id, 'student_number' => $number, 'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular']);
    }

    private function createRegistrar(): RegistrarStaff
    {
        $user = $this->userWithRole(Role::REGISTRAR_STAFF);
        $profile = UserProfile::create(['user_id' => $user->id, 'first_name' => 'Registrar', 'last_name' => 'Staff', 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'email' => "registrar{$user->id}@example.test"]);

        return RegistrarStaff::create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'employee_number' => "REG-{$user->id}", 'position' => 'Registrar Staff', 'employment_status' => 'regular', 'status' => 'active']);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}
