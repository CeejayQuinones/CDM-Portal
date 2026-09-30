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
use App\Services\Admission\AdmissionConversionService;
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
        $applicant = $case['applicant'];
        $service = app(AdmissionConversionService::class);
        $input = ['version' => $applicant->version, 'result_id' => $case['result']->id, 'result_version' => $case['result']->version, 'course_id' => $this->fixture['course']->id, 'curriculum_id' => $this->fixture['curriculum']->id, 'confirmed' => true];
        $service->accept($this->fixture['registrar'], $applicant->id, $input);
        $input['version'] = $applicant->fresh()->version;
        $service->convert($this->fixture['registrar'], $applicant->id, $input + ['student_number' => '26-WORKFLOW', 'admission_date' => now()->toDateString()]);
        $this->user = $case['user']->fresh();
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
        $this->student->update(['student_status' => 'leave_of_absence']);
        $this->action($a, 'submit')->assertConflict();
        $this->assertDatabaseHas('enrollment_applications', ['id' => $a['id'], 'status' => 'draft', 'version' => 1]);
    }

    public function test_closed_period_and_duplicate_term_creation_denied_by_service_and_database(): void
    {
        $this->period->update(['enabled' => false]);
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->period->update(['enabled' => true]);
        $a = $this->create();
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->action($a, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/enrollment/applications', ['period_id' => $this->period->id, 'classification' => 'regular'])->assertConflict();
        $this->expectException(UniqueConstraintViolationException::class);
        EnrollmentApplication::find($a['id'])->replicate()->save();
    }

    public function test_both_staff_roles_filter_inspect_manage_period_and_review(): void
    {
        $a = $this->create('transferee');
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            $this->staff($role);
            $this->getJson('/api/enrollment/applications?search=26-WORKFLOW&classification=transferee&status=draft&course_id='.$this->student->course_id.'&period_id='.$this->period->id.'&sort=oldest')->assertOk()->assertJsonPath('data.applications.total', 1);
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
        $type = DocumentType::create(['document_name' => 'Evidence', 'status' => 'active', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false]);
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
