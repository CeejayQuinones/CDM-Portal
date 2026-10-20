<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\DocumentTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentRecordRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypesSeeder::class);
    }

    public function test_student_sees_only_own_supported_requirements_and_submits_private_digital_copy(): void
    {
        Storage::fake('local');
        $student = $this->student('26-10001', 'Own');
        $other = $this->student('26-10002', 'Other');
        $form137 = DocumentType::query()->where('document_name', 'Form 137')->firstOrFail();
        $otherDocument = StudentDocument::query()->create(['student_id' => $other->id, 'document_type_id' => $form137->id]);

        Sanctum::actingAs($student->user);
        $this->getJson('/api/student/settings')->assertOk()
            ->assertJsonCount(3, 'data.record_requirements.items')
            ->assertJsonMissing(['name' => 'Birth Certificate'])
            ->assertJsonPath('data.record_requirements.items.0.digital_status', 'missing');

        $response = $this->post('/api/student/settings/record-requirements/'.$form137->id.'/upload', [
            'file' => UploadedFile::fake()->create('form-137.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.digital_status', 'under_review')
            ->assertJsonMissingPath('data.file_path');
        $document = StudentDocument::query()->where('student_id', $student->id)->firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertDatabaseHas('student_document_submissions', ['student_document_id' => $document->id, 'version' => 1]);
        $this->assertDatabaseHas('student_record_audit_events', ['student_id' => $student->id, 'action' => 'student_document_submitted']);
        $this->getJson('/api/student/settings/student-documents/'.$otherDocument->id.'/view')->assertNotFound();
        $this->post('/api/student/settings/record-requirements/'.$form137->id.'/upload', [
            'file' => UploadedFile::fake()->create('duplicate.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertConflict();
    }

    public function test_registrar_review_is_version_safe_and_resubmission_preserves_history(): void
    {
        Storage::fake('local');
        $student = $this->student('26-10003', 'Review');
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $type = DocumentType::query()->where('document_name', 'Good Moral Certificate')->firstOrFail();
        Sanctum::actingAs($student->user);
        $this->post('/api/student/settings/record-requirements/'.$type->id.'/upload', [
            'file' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $document = StudentDocument::query()->where('student_id', $student->id)->firstOrFail();

        Sanctum::actingAs($registrar);
        $this->patchJson('/api/student-documents/'.$document->id.'/needs-resubmission', ['version' => 1])
            ->assertUnprocessable();
        $this->patchJson('/api/student-documents/'.$document->id.'/needs-resubmission', ['version' => 1, 'reason' => 'The scan is unreadable.'])
            ->assertOk()->assertJsonPath('data.digital_status', 'needs_resubmission');

        Sanctum::actingAs($student->user);
        $this->post('/api/student/settings/record-requirements/'.$type->id.'/upload', [
            'file' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('student_document_submissions', 2);

        Sanctum::actingAs($registrar);
        $this->patchJson('/api/student-documents/'.$document->id.'/verify', ['version' => 1])->assertConflict();
        $this->patchJson('/api/student-documents/'.$document->id.'/verify', ['version' => 2])
            ->assertOk()->assertJsonPath('data.digital_status', 'verified');
        $this->assertDatabaseHas('student_record_audit_events', ['student_id' => $student->id, 'action' => 'student_document_resubmitted']);
        $this->assertDatabaseHas('student_record_audit_events', ['student_id' => $student->id, 'action' => 'student_document_verified']);
    }

    public function test_hard_copy_workflow_is_independent_audited_and_updates_attention(): void
    {
        $student = $this->student('26-10004', 'Physical');
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $type = DocumentType::query()->where('document_name', 'Form 137')->firstOrFail();
        $document = StudentDocument::query()->create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'file_path' => 'student-documents/'.$student->id.'/verified.pdf',
            'availability_status' => 'available', 'verification_status' => 'verified',
        ]);
        Sanctum::actingAs($registrar);
        $url = '/api/students/'.$student->id.'/record-requirements/'.$type->id;
        $this->patchJson($url.'/require', ['instruction' => 'Submit the original at Window 3.'])
            ->assertOk()->assertJsonPath('data.physical_copy_status', 'required');
        $this->getJson('/api/student-records/productivity')->assertOk()
            ->assertJsonFragment(['key' => $student->id.':'.$type->id, 'attention_reason' => 'Hard Copy Awaiting Submission']);
        $this->patchJson($url.'/receive')->assertOk()->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.physical_copy_status', 'submitted');
        $this->getJson('/api/student-records/productivity')->assertOk()
            ->assertJsonMissing(['key' => $student->id.':'.$type->id]);
        $this->patchJson($url.'/reopen')->assertUnprocessable();
        $this->patchJson($url.'/reopen', ['reason' => 'The original was incomplete.'])->assertOk();
        $this->assertSame('verified', $document->fresh()->verification_status);
        foreach (['student_document_hard_copy_required', 'student_document_hard_copy_received', 'student_document_hard_copy_reopened'] as $action) {
            $this->assertDatabaseHas('student_record_audit_events', ['student_id' => $student->id, 'action' => $action]);
        }
    }

    public function test_admin_is_allowed_and_unrelated_roles_cannot_change_requirements(): void
    {
        $student = $this->student('26-10005', 'Access');
        $type = DocumentType::query()->where('document_name', 'Form 137')->firstOrFail();
        $url = '/api/students/'.$student->id.'/record-requirements/'.$type->id.'/require';
        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->patchJson($url)->assertOk();

        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            Sanctum::actingAs($role === Role::STUDENT ? $student->user : $this->user($role));
            $this->patchJson($url)->assertForbidden();
        }
    }

    public function test_document_type_workflows_keep_official_documents_out_of_student_uploads(): void
    {
        $student = $this->student('26-10006', 'Permissions');
        $official = DocumentType::query()->where('document_name', 'Statement of Grades')->firstOrFail();
        $supporting = DocumentType::query()->where('document_name', 'Form 138')->firstOrFail();
        Sanctum::actingAs($student->user);

        $this->getJson('/api/document-types')->assertOk()
            ->assertJsonFragment(['document_name' => 'Statement of Grades'])
            ->assertJsonMissing(['document_name' => 'Form 138']);
        $this->getJson('/api/student/settings')->assertOk()
            ->assertJsonFragment(['name' => 'Form 138'])
            ->assertJsonMissing(['name' => 'Statement of Grades']);
        $this->post('/api/student/settings/record-requirements/'.$official->id.'/upload', [
            'file' => UploadedFile::fake()->create('forbidden.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->post('/api/student/settings/record-requirements/'.$supporting->id.'/upload', [
            'file' => UploadedFile::fake()->create('form-138.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_registrar_configures_document_workflow_permissions_safely(): void
    {
        Sanctum::actingAs($this->user(Role::REGISTRAR_STAFF));
        $official = $this->postJson('/api/registrar/document-types', [
            'document_name' => 'Official Credential',
            'description' => 'Issued by the Registrar.',
            'workflow_category' => 'official_issuance',
            'student_requestable' => true,
            'student_uploadable' => false,
            'requires_appointment' => false,
            'processing_fee' => 0,
            'processing_days' => 1,
        ])->assertCreated()->assertJsonPath('data.student_uploadable', false)->json('data');

        $this->patchJson('/api/registrar/document-types/'.$official['id'], ['student_uploadable' => true])
            ->assertUnprocessable();
        $this->patchJson('/api/registrar/document-types/'.$official['id'], [
            'workflow_category' => 'supporting_record',
            'student_requestable' => false,
            'student_uploadable' => true,
        ])->assertOk()->assertJsonPath('data.student_uploadable', true);
        $this->patchJson('/api/registrar/document-types/'.$official['id'], [
            'workflow_category' => 'official_issuance',
        ])->assertOk()->assertJsonPath('data.student_uploadable', false);
    }

    private function student(string $number, string $first): Student
    {
        $user = $this->user(Role::STUDENT);
        $profile = UserProfile::query()->create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        DB::table('departments')->insertOrIgnore(['id' => 1, 'department_code' => 'IT', 'department_name' => 'IT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('courses')->insertGetId(['department_id' => 1, 'course_code' => 'BSIT-'.$number, 'course_name' => 'BSIT', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'CUR-'.$number, 'curriculum_name' => 'Curriculum', 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return Student::query()->create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => $number, 'admission_date' => today(), 'year_level' => 1, 'student_status' => 'regular']);
    }

    private function user(string $role): User
    {
        $roleModel = Role::query()->firstOrCreate(['role_name' => $role]);

        return User::factory()->create(['role_id' => $roleModel->id, 'status' => 'active']);
    }
}
