<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Enrollment\EnrollmentWorkflowEvent;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\Enrollment\EnrollmentAuditWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdmissionConversionFixture;
use Tests\TestCase;

class EnrollmentWorkflowTest extends TestCase
{
    private array $fixture;

    private User $user;

    private Student $student;

    private EnrollmentPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = AdmissionConversionFixture::create('workflow');
        $case = $this->fixture['cases'][0];
        AdmissionConversionFixture::legacyStudent($this->fixture, $case, 'LEGACY-WORKFLOW');
        $this->user = $case['user']->fresh();
        $this->user->profile()->update(['birth_date' => '2005-04-03', 'contact_number' => '09171234567', 'email' => 'workflow@example.test', 'address' => 'Rodriguez, Rizal']);
        $this->student = $this->user->student;
        $semester = Semester::firstOrCreate(['semester_name' => 'First'], ['semester_order' => 1, 'status' => 'active']);
        $this->period = EnrollmentPeriod::create(['academic_year_id' => $this->fixture['cycle']->academic_year_id, 'semester_id' => $semester->id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'enabled' => true, 'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []], 'created_by' => $this->fixture['registrar']->id, 'updated_by' => $this->fixture['registrar']->id]);
        Sanctum::actingAs($this->user);
    }

    private function create(string $classification = 'regular'): array
    {
        return $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => $classification])->assertCreated()->json('data');
    }

    private function action(array $a, string $action, array $extra = [])
    {
        if ($action === 'save' && ! array_key_exists('personal_details', $extra)) {
            $extra['personal_details'] = EnrollmentApplication::findOrFail($a['id'])->personal_details;
        }

        return $this->postJson('/api/enrollment/applications/'.$a['id'].'/'.$action, ['version' => $a['version'], 'confirmed' => true] + $extra);
    }

    private function staff(string $role = Role::REGISTRAR_STAFF): User
    {
        $u = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id, 'status' => 'active']);
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_converted_identity_reused_through_draft_submit_approval(): void
    {
        $counts = [];
        foreach (['users', 'user_profiles', 'students', 'courses', 'curriculums', 'enrollments', 'enrollment_subjects', 'admission_applicants', 'admission_decisions'] as $t) {
            $counts[$t] = DB::table($t)->count();
        }
        $this->getJson('/api/enrollment/status')->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.applications_enabled', true)->assertJsonPath('data.student.id', $this->student->id);
        $a = $this->create();
        $a = $this->action($a, 'save', ['classification' => 'irregular'])->assertOk()->assertJsonPath('data.classification', 'irregular')->json('data');
        $before = $a;
        $a = $this->action($a, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
        $this->action($before, 'submit')->assertOk()->assertJsonPath('data.version', $a['version']);
        $this->assertDatabaseCount('enrollment_applications', 1);
        $this->assertSame(1, EnrollmentWorkflowEvent::where('action', 'enrollment.submitted')->count());
        Sanctum::actingAs($this->fixture['registrar']);
        $a = $this->action($a, 'review', ['notes' => 'Reviewed.'])->assertOk()->json('data');
        $a = $this->action($a, 'approve', ['notes' => 'Ready for finalization.'])->assertOk()->assertJsonPath('data.status', 'approved')->json('data');
        $this->assertSame($this->student->id, $a['student_id']);
        $this->assertSame($this->student->course_id, $a['course_id']);
        $this->assertSame($this->student->curriculum_id, $a['curriculum_id']);
        foreach ($counts as $t => $count) {
            $this->assertDatabaseCount($t, $count);
        }
    }

    public function test_roles_account_status_and_ownership_are_enforced(): void
    {
        $a = $this->create();
        foreach ([Role::GUEST, Role::PROFESSOR, Role::STUDENT] as $role) {
            $this->staff($role);
            foreach (['/api/enrollment/applications', '/api/enrollment/periods'] as $url) {
                $this->getJson($url)->assertForbidden();
            }$this->action($a, 'review')->assertForbidden();
            if ($role !== Role::STUDENT) {
                $this->postJson('/api/enrollment/applications', [])->assertForbidden();
            } else {
                $this->getJson('/api/enrollment/applications/'.$a['id'])->assertNotFound();
            }
        }
        foreach ([Role::STUDENT, Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            foreach (['inactive', 'suspended'] as $status) {
                $u = $this->staff($role);
                $u->update(['status' => $status]);
                $this->getJson($role === Role::STUDENT ? '/api/enrollment/status' : '/api/enrollment/periods')->assertForbidden();
                $this->postJson('/api/enrollment/applications', [])->assertForbidden();
            }
        }
        Sanctum::actingAs($this->user);
        $this->action($a, 'review')->assertForbidden();
    }

    public function test_period_closure_and_ineligibility_rechecked_on_submit(): void
    {
        $a = $this->create();
        $this->period->update(['enabled' => false]);
        $this->action($a, 'submit')->assertConflict();
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.period.state', 'closed')->assertJsonPath('data.eligible', false);
        $this->period->update(['enabled' => true, 'opens_at' => now()->addHour()]);
        $this->action($a, 'submit')->assertConflict();
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.period.state', 'upcoming');
        $this->period->update(['opens_at' => now()->subDay(), 'closes_at' => now()]);
        $this->action($a, 'submit')->assertConflict();
        $this->period->update(['closes_at' => now()->addDay()]);
        $this->getJson('/api/enrollment/status')->assertOk()
            ->assertJsonPath('data.period.state', 'open')
            ->assertJsonPath('data.eligible', true);
        $this->student->update(['student_status' => 'leave_of_absence']);
        $this->action($a, 'submit')->assertConflict();
        $this->assertDatabaseHas('enrollment_applications', ['id' => $a['id'], 'status' => 'draft', 'version' => 1]);
    }

    public function test_cancelled_unsubmitted_draft_can_be_replaced_without_losing_history_or_duplicate_protection(): void
    {
        $this->period->update(['enabled' => false]);
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->period->update(['enabled' => true]);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.current_application', null);
        $a = $this->create();
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->action($a, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.current_application', null)->assertJsonPath('data.eligible', true);
        $this->getJson('/api/enrollment/applications/mine')->assertOk()->assertJsonPath('data.data.0.id', $a['id'])->assertJsonPath('data.data.0.status', 'cancelled');
        $this->action($a, 'save', ['classification' => 'irregular'])->assertConflict();
        $replacement = $this->create('irregular');
        $this->assertNotSame($a['id'], $replacement['id']);
        $this->assertSame($this->user->id, $replacement['owner_user_id']);
        $this->assertSame($this->student->id, $replacement['student_id']);
        $this->assertSame($this->period->academic_year_id, $replacement['academic_year_id']);
        $this->assertSame($this->period->semester_id, $replacement['semester_id']);
        $this->assertDatabaseHas('enrollment_applications', ['id' => $a['id'], 'status' => 'cancelled', 'blocking_owner_user_id' => null, 'blocking_student_id' => null]);
        $this->assertDatabaseHas('enrollment_applications', ['id' => $replacement['id'], 'status' => 'draft', 'blocking_owner_user_id' => $this->user->id, 'blocking_student_id' => $this->student->id]);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.current_application.id', $replacement['id']);
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->expectException(UniqueConstraintViolationException::class);
        EnrollmentApplication::find($replacement['id'])->replicate()->save();
    }

    public function test_cancellation_does_not_bypass_closed_term_or_ineligible_student(): void
    {
        $a = $this->create();
        $this->action($a, 'cancel')->assertOk();
        $this->period->update(['enabled' => false]);
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->period->update(['enabled' => true]);
        $this->student->update(['student_status' => 'leave_of_absence']);
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->assertDatabaseCount('enrollment_applications', 1);
    }

    public function test_submitted_application_cannot_be_cancelled_or_replaced(): void
    {
        $a = $this->create();
        $submitted = $this->action($a, 'submit')->assertOk()->json('data');
        $this->action($submitted, 'cancel')->assertConflict();
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->assertDatabaseCount('enrollment_applications', 1);
    }

    public function test_migration_rollback_refuses_to_remove_reapplication_history(): void
    {
        $first = $this->create();
        $this->action($first, 'cancel')->assertOk();
        $second = $this->create();
        $migration = require database_path('migrations/2026_10_29_000001_allow_reapplication_after_cancelled_enrollment_draft.php');

        try {
            $migration->down();
            $this->fail('Rollback should refuse duplicate historical identities.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot restore Enrollment uniqueness', $exception->getMessage());
        }
        $this->assertDatabaseHas('enrollment_applications', ['id' => $first['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('enrollment_applications', ['id' => $second['id'], 'status' => 'draft']);
    }

    public function test_both_staff_roles_filter_inspect_manage_period_and_review(): void
    {
        $a = $this->create('transferee');
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            $this->staff($role);
            $this->getJson('/api/enrollment/applications?search='.urlencode($this->student->student_number).'&classification=transferee&status=draft&course_id='.$this->student->course_id.'&period_id='.$this->period->id.'&sort=oldest')->assertOk()->assertJsonPath('data.applications.total', 1);
            $this->getJson('/api/enrollment/applications/'.$a['id'])->assertOk();
            $this->getJson('/api/enrollment/applications?search='.urlencode($this->student->userProfile->first_name.' '.$this->student->userProfile->last_name))->assertOk()->assertJsonPath('data.applications.total', 1);
            $this->getJson('/api/enrollment/periods')->assertOk();
            $p = $this->period->fresh();
            $payload = $p->only(['academic_year_id', 'semester_id', 'opens_at', 'closes_at', 'enabled', 'document_requirements', 'version']);
            $this->putJson('/api/enrollment/periods/'.$p->id, $payload)->assertOk();
            $this->putJson('/api/enrollment/periods/'.$p->id, $payload)->assertConflict();
        }
        Sanctum::actingAs($this->user);
        $a = $this->action($a, 'submit')->assertOk()->json('data');
        $this->staff(Role::ADMIN);
        $this->action($a, 'reject')->assertUnprocessable();
        $this->action($a, 'reject', ['notes' => 'Credit evidence requires review.'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_stale_staff_decisions_and_invalid_transitions_fail(): void
    {
        $a = $this->create();
        $this->staff(Role::ADMIN);
        $this->action($a, 'approve')->assertConflict();
        Sanctum::actingAs($this->user);
        $a = $this->action($a, 'submit')->assertOk()->json('data');
        $this->action($a, 'cancel')->assertConflict();
        $this->action($a, 'save', ['classification' => 'returnee'])->assertConflict();
        $this->staff(Role::ADMIN);
        $reviewed = $this->action($a, 'review')->assertOk()->json('data');
        $this->staff(Role::REGISTRAR_STAFF);
        $this->action($a, 'review')->assertConflict();
        $this->action($a, 'reject', ['notes' => 'Stale'])->assertConflict();
        $this->action($reviewed, 'approve')->assertOk();
        $this->action($reviewed, 'reject', ['notes' => 'Stale'])->assertConflict();
    }

    public function test_audit_failure_rolls_back_submission(): void
    {
        $a = $this->create();
        $this->mock(EnrollmentAuditWriter::class)->shouldReceive('record')->andThrow(new \RuntimeException('private failure'));
        $this->action($a, 'submit')->assertStatus(503)->assertDontSee('private failure');
        $this->assertDatabaseHas('enrollment_applications', ['id' => $a['id'], 'status' => 'draft', 'version' => 1, 'submitted_at' => null]);
    }

    public function test_verified_existing_documents_are_reused_and_private(): void
    {
        Storage::fake('local');
        $type = DocumentType::create(['document_name' => 'Transcript', 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
        $this->period->update(['document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [$type->id], 'returnee' => []]]);
        $a = $this->create('transferee');
        $this->action($a, 'submit')->assertUnprocessable();
        Storage::disk('local')->put('student-documents/transcript.pdf', 'verified evidence');
        StudentDocument::create(['student_id' => $this->student->id, 'document_type_id' => $type->id, 'file_path' => 'student-documents/transcript.pdf', 'verification_status' => 'verified', 'submitted_date' => now()]);
        $this->getJson('/api/enrollment/applications/'.$a['id'])->assertJsonPath('data.requirements.0.reusable', true)->assertDontSee('student-documents/transcript.pdf');
        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', [
            'document_type_id' => $type->id, 'version' => $a['version'],
            'file' => UploadedFile::fake()->create('duplicate.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertConflict();
        $this->action($a, 'submit')->assertOk();
        $this->assertDatabaseCount('enrollment_documents', 1);
        $this->assertDatabaseCount('student_documents', 1);
        $doc = DB::table('enrollment_documents')->first();
        $this->get('/api/enrollment/applications/'.$a['id'].'/documents/'.$doc->id)->assertOk();
        $documentUrl = '/api/enrollment/applications/'.$a['id'].'/documents/'.$doc->id;
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            $this->staff($role);
            $this->get($documentUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        foreach ([Role::GUEST, Role::PROFESSOR] as $role) {
            $this->staff($role);
            $this->getJson($documentUrl)->assertForbidden();
        }
        $this->staff(Role::STUDENT);
        $this->getJson($documentUrl)->assertNotFound();
        $this->staff();
        $submitted = EnrollmentApplication::findOrFail($a['id'])->toArray();
        $this->action($submitted, 'reject', ['notes' => 'Academic review required.'])->assertOk();
        Storage::disk('local')->assertExists('student-documents/transcript.pdf');
        $this->assertDatabaseCount('student_documents', 1);
        Sanctum::actingAs($this->user);
        $this->get($documentUrl)->assertOk();
    }

    public function test_required_upload_is_private_and_does_not_duplicate_student_documents(): void
    {
        Storage::fake('local');
        $type = DocumentType::create(['document_name' => 'Clearance', 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
        $this->period->update(['document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => [$type->id]]]);
        $a = $this->create('returnee');
        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', ['document_type_id' => $type->id, 'version' => 1, 'file' => UploadedFile::fake()->create('clearance.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->assertDontSee('file_path');
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('student_documents', 0);
        $this->action(EnrollmentApplication::find($a['id'])->toArray(), 'submit')->assertOk();
    }

    public function test_personal_details_are_draft_scoped_and_survive_reload_without_changing_profile(): void
    {
        $a = $this->create();
        $profileFields = ['first_name', 'middle_name', 'last_name', 'birth_date', 'gender', 'contact_number', 'email', 'address'];
        $profileBefore = collect($this->user->profile()->firstOrFail()->toArray())->only($profileFields)->all();
        $details = ['first_name' => 'Enrollment', 'middle_name' => null, 'last_name' => 'Applicant', 'birth_date' => '2005-04-03',
            'gender' => 'Prefer not to say', 'contact_number' => '09171234567', 'email' => 'draft@example.test', 'address' => 'Draft address only'];

        $saved = $this->action($a, 'save', ['classification' => 'regular', 'personal_details' => $details])
            ->assertOk()->assertJsonPath('data.personal_details.first_name', 'Enrollment')->json('data');
        $this->getJson('/api/enrollment/applications/'.$a['id'])->assertOk()
            ->assertJsonPath('data.application.personal_details.email', 'draft@example.test');
        $this->assertSame($profileBefore, collect($this->user->profile()->firstOrFail()->toArray())->only($profileFields)->all());
        $this->action($saved, 'save', ['classification' => 'regular', 'personal_details' => array_replace($details, ['email' => 'invalid'])])
            ->assertUnprocessable()->assertJsonValidationErrors('personal_details.email');
    }

    public function test_image_preview_and_transactional_replacement_keep_private_metadata(): void
    {
        Storage::fake('local');
        $type = DocumentType::create(['document_name' => 'Photo', 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
        $this->period->update(['document_requirements' => ['regular' => [$type->id], 'irregular' => [], 'transferee' => [], 'returnee' => []]]);
        $a = $this->create();
        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', ['document_type_id' => $type->id, 'version' => 1,
            'file' => UploadedFile::fake()->image('first.png')], ['Accept' => 'application/json'])->assertCreated();
        $document = EnrollmentApplication::findOrFail($a['id'])->documents()->firstOrFail();
        $oldPath = $document->file_path;

        $this->getJson('/api/enrollment/applications/'.$a['id'])->assertOk()
            ->assertJsonPath('data.requirements.0.filename', 'first.png')
            ->assertJsonPath('data.requirements.0.preview_kind', 'image')
            ->assertJsonMissingPath('data.requirements.0.file_path');
        $this->get('/api/enrollment/applications/'.$a['id'].'/documents/'.$document->id.'?preview=1')->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename=first.png');

        // Attachments created before metadata columns were populated still use
        // the validated stored file for preview classification and delivery.
        $document->forceFill(['original_name' => null, 'mime_type' => null])->save();
        $this->getJson('/api/enrollment/applications/'.$a['id'])->assertOk()
            ->assertJsonPath('data.requirements.0.preview_kind', 'image');
        $this->get('/api/enrollment/applications/'.$a['id'].'/documents/'.$document->id.'?preview=1')->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', ['document_type_id' => $type->id, 'version' => 2,
            'file' => UploadedFile::fake()->image('replacement.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $document->refresh();
        $this->assertSame('replacement.jpg', $document->original_name);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertDatabaseCount('enrollment_documents', 1);
    }

    public function test_failed_replacement_preserves_the_previous_attachment(): void
    {
        Storage::fake('local');
        $type = DocumentType::create(['document_name' => 'Evidence', 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
        $this->period->update(['document_requirements' => ['regular' => [$type->id], 'irregular' => [], 'transferee' => [], 'returnee' => []]]);
        $a = $this->create();
        $auditCalls = 0;
        $this->mock(EnrollmentAuditWriter::class)->shouldReceive('record')->twice()->andReturnUsing(function () use (&$auditCalls): void {
            $auditCalls++;
            if ($auditCalls === 2) {
                throw new \RuntimeException('private failure');
            }
        });
        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', ['document_type_id' => $type->id, 'version' => 1,
            'file' => UploadedFile::fake()->create('original.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $document = EnrollmentApplication::findOrFail($a['id'])->documents()->firstOrFail();
        $originalPath = $document->file_path;
        $this->getJson('/api/enrollment/applications/'.$a['id'])->assertJsonPath('data.requirements.0.preview_kind', 'pdf');

        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', ['document_type_id' => $type->id, 'version' => 2,
            'file' => UploadedFile::fake()->image('replacement.png')], ['Accept' => 'application/json'])->assertStatus(503);

        $document->refresh();
        $this->assertSame('original.pdf', $document->original_name);
        $this->assertSame($originalPath, $document->file_path);
        Storage::disk('local')->assertExists($originalPath);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_missing_saved_document_returns_not_found_without_exposing_storage_path(): void
    {
        Storage::fake('local');
        $type = DocumentType::create(['document_name' => 'Photo', 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
        $this->period->update(['document_requirements' => ['regular' => [$type->id], 'irregular' => [], 'transferee' => [], 'returnee' => []]]);
        $a = $this->create();
        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', ['document_type_id' => $type->id, 'version' => 1,
            'file' => UploadedFile::fake()->image('missing.png')], ['Accept' => 'application/json'])->assertCreated();
        $document = EnrollmentApplication::findOrFail($a['id'])->documents()->firstOrFail();
        Storage::disk('local')->delete($document->file_path);

        $this->get('/api/enrollment/applications/'.$a['id'].'/documents/'.$document->id.'?preview=1')
            ->assertNotFound()->assertDontSee($document->file_path);
    }

    public function test_period_creation_validation_and_requirement_freeze_for_both_staff_roles(): void
    {
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $i => $role) {
            $this->staff($role);
            $semester = Semester::create(['semester_name' => 'Term '.($i + 2), 'semester_order' => $i + 2, 'status' => 'active']);
            $payload = ['academic_year_id' => $this->period->academic_year_id, 'semester_id' => $semester->id, 'opens_at' => now()->addMonths($i + 1)->toISOString(), 'closes_at' => now()->addMonths($i + 2)->toISOString(), 'enabled' => true, 'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []]];
            $this->postJson('/api/enrollment/periods', $payload)->assertCreated()->assertJsonPath('data.state', 'upcoming');
            $this->postJson('/api/enrollment/periods', $payload)->assertConflict();
            $this->postJson('/api/enrollment/periods', array_replace($payload, ['closes_at' => now()->subDay()->toISOString()]))->assertUnprocessable();
        }
        Sanctum::actingAs($this->user);
        $this->create();
        $this->staff();
        $type = DocumentType::create(['document_name' => 'Evidence', 'workflow_category' => 'supporting_record', 'student_uploadable' => true, 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
        $payload = $this->period->only(['academic_year_id', 'semester_id', 'opens_at', 'closes_at', 'enabled', 'document_requirements', 'version']);
        $payload['document_requirements']['regular'] = [$type->id];
        $this->putJson('/api/enrollment/periods/'.$this->period->id, $payload)->assertConflict();
    }

    public function test_upload_audit_failure_removes_file_and_rolls_back_version(): void
    {
        Storage::fake('local');
        $type = DocumentType::create(['document_name' => 'Evidence', 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
        $this->period->update(['document_requirements' => ['regular' => [$type->id], 'irregular' => [], 'transferee' => [], 'returnee' => []]]);
        $a = $this->create();
        $this->mock(EnrollmentAuditWriter::class)->shouldReceive('record')->andThrow(new \RuntimeException('private failure'));
        $this->post('/api/enrollment/applications/'.$a['id'].'/documents', ['document_type_id' => $type->id, 'version' => 1, 'file' => UploadedFile::fake()->create('evidence.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('enrollment_documents', 0);
        $this->assertDatabaseHas('enrollment_applications', ['id' => $a['id'], 'version' => 1]);
    }
}
