<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\DocumentType;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentDocument;
use App\Models\Enrollment\EnrollmentWorkflowEvent;
use App\Models\EnrollmentSubject;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentRecordAuditEvent;
use App\Models\User;
use App\Services\Admission\AdmissionCompletionService;
use App\Services\EarlyWarningService;
use App\Services\Enrollment\EnrollmentEligibilityService;
use App\Services\Enrollment\EnrollmentWorkflowService;
use App\Services\Enrollment\StudentNumberAllocator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class EnrollmentAcademicTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->f = EnrollmentAcademicFixture::create('academic');
        Sanctum::actingAs($this->f['registrar']);
    }

    private function base(): string
    {
        return '/api/enrollment/academic';
    }

    private function action(string $action, array $extra = [])
    {
        $a = $this->f['application']->fresh();

        return $this->postJson($this->base().'/applications/'.$a->id.'/'.$action, ['version' => $a->version, 'confirmed' => true] + $extra);
    }

    private function schedule(array $extra = [])
    {
        return $this->postJson($this->base().'/schedules', array_replace(['section_id' => $this->f['section']->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id, 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'AC Room'], $extra));
    }

    private function prepare(): void
    {
        $this->schedule()->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $this->action('subjects', ['subject_ids' => []])->assertOk();
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
    }

    private function firstTimeApplication(int $caseIndex = 1)
    {
        $case = $this->f['cases'][$caseIndex];
        app(AdmissionCompletionService::class)->accept($this->f['registrar'], $case['applicant']->id, [
            'version' => $case['applicant']->version, 'result_id' => $case['result']->id,
            'result_version' => $case['result']->version, 'course_id' => $this->f['course']->id,
            'curriculum_id' => $this->f['curriculum']->id, 'confirmed' => true,
        ]);
        $workflow = app(EnrollmentWorkflowService::class);
        $application = $workflow->create($case['user']->fresh(), [
            'period_id' => $this->f['period']->id, 'classification' => 'regular',
        ]);
        foreach (['submit', 'review', 'approve'] as $action) {
            $application = $workflow->mutate(
                $action === 'submit' ? $case['user']->fresh() : $this->f['registrar'],
                $application->id,
                $action,
                ['version' => $application->version, 'notes' => 'First-time review.'],
            );
        }

        return [$case, $application];
    }

    private function prepareApplication(int $applicationId): void
    {
        $this->schedule()->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $application = EnrollmentApplication::findOrFail($applicationId);
        $this->postJson($this->base().'/applications/'.$application->id.'/subjects', [
            'version' => $application->version, 'subject_ids' => [],
        ])->assertOk();
        $application->refresh();
        $this->postJson($this->base().'/applications/'.$application->id.'/assign', [
            'version' => $application->version, 'section_id' => $this->f['section']->id,
        ])->assertOk();
    }

    public function test_first_time_finalization_creates_identity_number_role_and_academic_records_atomically(): void
    {
        [$case, $application] = $this->firstTimeApplication();
        $token = $case['user']->createToken('guest-before-finalization');
        $studentCount = Student::count();

        $this->assertNull($application->student_id);
        $this->assertSame($case['user']->id, $application->owner_user_id);
        $this->assertSame($case['applicant']->id, $application->admission_applicant_id);
        $this->assertSame(Role::GUEST, $case['user']->fresh('role')->role->role_name);
        $this->getJson('/api/enrollment/applications?search='.$case['applicant']->applicant_number)
            ->assertOk()->assertJsonPath('data.applications.data.0.id', $application->id)
            ->assertJsonPath('data.applications.data.0.student', null)
            ->assertJsonPath('data.applications.data.0.owner.id', $case['user']->id);

        $this->prepareApplication($application->id);
        $application->refresh();
        $response = $this->postJson($this->base().'/applications/'.$application->id.'/finalize', [
            'version' => $application->version, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.status', 'enrolled');

        $application->refresh();
        $student = Student::findOrFail($application->student_id);
        $prefix = substr((string) $this->f['year']->start_date, 2, 2);
        $this->assertSame($studentCount + 1, Student::count());
        $this->assertSame($case['user']->id, $student->user_id);
        $this->assertSame($case['profile']->id, $student->user_profile_id);
        $this->assertSame($this->f['course']->id, $student->course_id);
        $this->assertSame($this->f['curriculum']->id, $student->curriculum_id);
        $this->assertMatchesRegularExpression('/^'.preg_quote($prefix, '/').'-\d{5}$/', $student->student_number);
        $this->assertSame(Role::STUDENT, $case['user']->fresh('role')->role->role_name);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $application->enrollment_id, 'student_id' => $student->id]);
        $this->assertDatabaseCount('enrollment_subjects', 2);
        $this->assertDatabaseHas('admission_applicants', ['id' => $case['applicant']->id, 'status' => 'accepted', 'converted_student_id' => null]);
        $eligibility = app(EnrollmentEligibilityService::class)->status($case['user']->fresh());
        $this->assertTrue($eligibility['academic_ready']);
        $this->assertSame('term_enrollment_exists', $eligibility['reason']);

        $this->postJson($this->base().'/applications/'.$application->id.'/finalize', [
            'version' => $application->version - 1, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.enrollment_id', $response->json('data.enrollment_id'));
        $this->assertSame($studentCount + 1, Student::count());
    }

    public function test_first_time_finalization_links_supported_enrollment_documents_without_copying_files(): void
    {
        Storage::fake('local');
        $supported = DocumentType::create([
            'document_name' => 'Form 137', 'status' => 'active',
            'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false,
        ]);
        $unsupported = DocumentType::create([
            'document_name' => 'Birth Certificate', 'status' => 'active',
            'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false,
        ]);
        $requirements = $this->f['period']->document_requirements;
        $requirements['regular'] = [$supported->id, $unsupported->id];
        $this->f['period']->update(['document_requirements' => $requirements]);

        $case = $this->f['cases'][1];
        app(AdmissionCompletionService::class)->accept($this->f['registrar'], $case['applicant']->id, [
            'version' => $case['applicant']->version, 'result_id' => $case['result']->id,
            'result_version' => $case['result']->version, 'course_id' => $this->f['course']->id,
            'curriculum_id' => $this->f['curriculum']->id, 'confirmed' => true,
        ]);
        $workflow = app(EnrollmentWorkflowService::class);
        $application = $workflow->create($case['user']->fresh(), [
            'period_id' => $this->f['period']->id, 'classification' => 'regular',
        ]);

        Sanctum::actingAs($case['user']);
        foreach ([$supported, $unsupported] as $type) {
            $this->post('/api/enrollment/applications/'.$application->id.'/documents', [
                'version' => $application->fresh()->version,
                'document_type_id' => $type->id,
                'file' => UploadedFile::fake()->create(strtolower(str_replace(' ', '-', $type->document_name)).'.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertCreated();
        }
        $application = $workflow->mutate($case['user']->fresh(), $application->id, 'submit', [
            'version' => $application->fresh()->version, 'confirmed' => true,
        ]);
        foreach (['review', 'approve'] as $action) {
            $application = $workflow->mutate($this->f['registrar'], $application->id, $action, [
                'version' => $application->version, 'notes' => 'Document handoff review.',
            ]);
        }

        Sanctum::actingAs($this->f['registrar']);
        $this->prepareApplication($application->id);
        $application->refresh();
        $this->postJson($this->base().'/applications/'.$application->id.'/finalize', [
            'version' => $application->version, 'confirmed' => true,
        ])->assertOk();

        $application->refresh();
        $supportedSource = EnrollmentDocument::where('application_id', $application->id)
            ->where('document_type_id', $supported->id)->firstOrFail();
        $unsupportedSource = EnrollmentDocument::where('application_id', $application->id)
            ->where('document_type_id', $unsupported->id)->firstOrFail();
        $destination = StudentDocument::findOrFail($supportedSource->student_document_id);

        $this->assertSame($application->student_id, $destination->student_id);
        $this->assertSame($supportedSource->file_path, $destination->file_path);
        $this->assertSame('available', $destination->availability_status);
        $this->assertSame('pending', $destination->verification_status);
        $this->assertNull($unsupportedSource->student_document_id);
        $this->assertDatabaseCount('student_documents', 1);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('student_record_audit_events', [
            'student_id' => $destination->student_id,
            'actor_user_id' => $this->f['registrar']->id,
            'action' => 'enrollment_document_linked',
        ]);
        $event = StudentRecordAuditEvent::where('action', 'enrollment_document_linked')->firstOrFail();
        $this->assertSame($supportedSource->id, $event->metadata['enrollment_document_id']);
        $this->assertArrayNotHasKey('file_path', $event->metadata);

        $this->getJson('/api/students/'.$destination->student_id.'/documents')
            ->assertOk()
            ->assertJsonPath('data.documents.0.name', 'Form 137')
            ->assertJsonPath('data.documents.0.has_file', true)
            ->assertJsonPath('data.documents.0.availability_status', 'available')
            ->assertJsonPath('data.documents.0.verification_status', 'pending')
            ->assertJsonMissingPath('data.documents.0.file_path');
        $this->get('/api/registrar/student-documents/'.$destination->id.'/view')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->postJson($this->base().'/applications/'.$application->id.'/finalize', [
            'version' => $application->version - 1, 'confirmed' => true,
        ])->assertOk();
        $this->assertDatabaseCount('student_documents', 1);
        $this->assertSame(1, StudentRecordAuditEvent::where('action', 'enrollment_document_linked')->count());
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_first_time_audit_failure_rolls_back_student_role_number_and_enrollment(): void
    {
        Storage::fake('local');
        [$case, $application] = $this->firstTimeApplication(2);
        $type = DocumentType::create([
            'document_name' => 'Good Moral Certificate', 'status' => 'active',
            'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false,
        ]);
        $path = 'enrollment/'.$application->id.'/good-moral.pdf';
        Storage::disk('local')->put($path, 'accepted evidence');
        $source = EnrollmentDocument::create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'file_path' => $path,
        ]);
        $token = $case['user']->createToken('guest-rollback');
        $studentCount = Student::count();
        $enrollmentCount = Enrollment::count();
        $documentCount = StudentDocument::count();
        $this->prepareApplication($application->id);
        $application->refresh();
        EnrollmentWorkflowEvent::creating(function ($event) {
            if ($event->action === 'enrollment.finalized') {
                throw new \RuntimeException('private first-time rollback test');
            }
        });

        $this->postJson($this->base().'/applications/'.$application->id.'/finalize', [
            'version' => $application->version, 'confirmed' => true,
        ])->assertStatus(503)->assertDontSee('private first-time rollback test');

        $this->assertSame($studentCount, Student::count());
        $this->assertSame($enrollmentCount, Enrollment::count());
        $this->assertSame($documentCount, StudentDocument::count());
        $this->assertNull($source->fresh()->student_document_id);
        $this->assertSame(0, StudentRecordAuditEvent::where('action', 'enrollment_document_linked')->count());
        Storage::disk('local')->assertExists($path);
        $this->assertSame(Role::GUEST, $case['user']->fresh('role')->role->role_name);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertNull($application->fresh()->student_id);
        $this->assertNull($application->fresh()->enrollment_id);
        $this->assertDatabaseHas('admission_applicants', ['id' => $case['applicant']->id, 'status' => 'accepted']);
    }

    public function test_student_number_sequence_uses_valid_maximum_resets_by_year_and_stops_at_limit(): void
    {
        $this->f['student']->update(['student_number' => '26-malformed']);
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $profile = $user->profile()->create(['first_name' => 'Number', 'last_name' => 'Fixture', 'gender' => 'Prefer not to say']);
        Student::create([
            'user_id' => $user->id, 'user_profile_id' => $profile->id,
            'course_id' => $this->f['course']->id, 'curriculum_id' => $this->f['curriculum']->id,
            'student_number' => '26-00127', 'admission_date' => now()->toDateString(),
            'year_level' => 1, 'student_status' => 'regular',
        ]);
        $allocator = app(StudentNumberAllocator::class);

        $this->assertSame('26-00128', DB::transaction(fn () => $allocator->next(2026)));
        $this->assertSame('27-00001', DB::transaction(fn () => $allocator->next(2027)));
        DB::table('student_number_sequences')->insert([
            'year_prefix' => '28', 'last_number' => 99999, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::transaction(fn () => $allocator->next(2028));
            $this->fail('An exhausted sequence must not allocate a six-digit Student number.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_returning_student_handoff_preserves_an_existing_verified_document(): void
    {
        Storage::fake('local');
        $type = DocumentType::create([
            'document_name' => 'Certificate of Enrollment', 'status' => 'active',
            'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false,
        ]);
        $verifiedPath = 'student-documents/'.$this->f['student']->id.'/verified-certificate.pdf';
        $enrollmentPath = 'enrollment/'.$this->f['application']->id.'/new-certificate.pdf';
        Storage::disk('local')->put($verifiedPath, 'verified historical evidence');
        Storage::disk('local')->put($enrollmentPath, 'new enrollment evidence');
        $existing = StudentDocument::create([
            'student_id' => $this->f['student']->id,
            'document_type_id' => $type->id,
            'file_path' => $verifiedPath,
            'availability_status' => 'available',
            'verification_status' => 'verified',
            'submitted_date' => now()->subYear(),
        ]);
        $source = EnrollmentDocument::create([
            'application_id' => $this->f['application']->id,
            'document_type_id' => $type->id,
            'file_path' => $enrollmentPath,
        ]);

        $this->prepare();
        $this->action('finalize')->assertOk();

        $this->assertDatabaseCount('student_documents', 1);
        $this->assertSame($verifiedPath, $existing->fresh()->file_path);
        $this->assertSame('verified', $existing->fresh()->verification_status);
        $this->assertNull($source->fresh()->student_document_id);
        Storage::disk('local')->assertExists($verifiedPath);
        Storage::disk('local')->assertExists($enrollmentPath);
    }

    public function test_first_time_document_is_owned_by_application_without_student_identity(): void
    {
        Storage::fake('local');
        $type = DocumentType::create([
            'document_name' => 'First-time evidence', 'status' => 'active',
            'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false,
        ]);
        $requirements = $this->f['period']->document_requirements;
        $requirements['regular'] = [$type->id];
        $this->f['period']->update(['document_requirements' => $requirements]);
        $case = $this->f['cases'][1];
        app(AdmissionCompletionService::class)->accept($this->f['registrar'], $case['applicant']->id, [
            'version' => $case['applicant']->version, 'result_id' => $case['result']->id,
            'result_version' => $case['result']->version, 'course_id' => $this->f['course']->id,
            'curriculum_id' => $this->f['curriculum']->id, 'confirmed' => true,
        ]);
        Sanctum::actingAs($case['user']);
        $application = app(EnrollmentWorkflowService::class)->create($case['user']->fresh(), [
            'period_id' => $this->f['period']->id, 'classification' => 'regular',
        ]);

        $this->postJson('/api/enrollment/applications/'.$application->id.'/submit', [
            'version' => $application->version, 'confirmed' => true,
        ])->assertUnprocessable();
        $this->post('/api/enrollment/applications/'.$application->id.'/documents', [
            'version' => $application->version, 'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $application->refresh();
        $this->assertNull($application->student_id);
        $this->assertDatabaseHas('enrollment_documents', [
            'application_id' => $application->id, 'document_type_id' => $type->id,
            'student_document_id' => null,
        ]);
        $this->postJson('/api/enrollment/applications/'.$application->id.'/submit', [
            'version' => $application->version, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_scheduling_subjects_match_section_year_and_missing_curriculum_is_empty(): void
    {
        $url = $this->base().'/schedules?section_id='.$this->f['section']->id;
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.subjects');
        $this->schedule(['subject_id' => $this->f['subjects'][2]->id])->assertUnprocessable();
        $this->f['section']->update(['year_level' => 4]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->f['section']->update(['year_level' => 1]);
        $this->f['curriculum']->update(['status' => 'inactive']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->schedule()->assertUnprocessable();
    }

    public function test_regular_proposal_and_atomic_finalization_reuse_identity_and_are_idempotent(): void
    {
        $counts = [];
        foreach (['users', 'user_profiles', 'students', 'courses', 'curriculums', 'subjects', 'professors'] as $t) {
            $counts[$t] = DB::table($t)->count();
        }
        $this->getJson($this->base().'/applications/'.$this->f['application']->id)->assertOk()->assertJsonCount(2, 'data.load.standard_subject_ids');
        $this->prepare();
        $before = $this->f['application']->fresh()->version;
        $a = $this->action('finalize')->assertOk()->assertJsonPath('data.status', 'enrolled')->json('data');
        $this->postJson($this->base().'/applications/'.$a['id'].'/finalize', ['version' => $before, 'confirmed' => true])->assertOk()->assertJsonPath('data.enrollment_id', $a['enrollment_id']);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('enrollment_subjects', 2);
        $this->assertSame(1, EnrollmentWorkflowEvent::where('action', 'enrollment.finalized')->count());
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        Sanctum::actingAs($this->f['user']);
        $this->getJson($this->base().'/records/'.$a['enrollment_id'])->assertOk()->assertJsonPath('data.total_units', 6)->assertJsonCount(2, 'data.subjects')->assertJsonPath('data.student_number', $this->f['student']->student_number);
        $this->getJson($this->base().'/notifications')->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_student_records_use_term_ranked_enrollment_and_reflect_section_changes(): void
    {
        $this->schedule()->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $this->action('subjects', ['subject_ids' => []])->assertOk();
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', $this->f['section']->section_name)
            ->assertJsonPath('data.0.current_enrollment.assignment_state', 'assigned_pending_finalization');

        $replacement = Section::create(['course_id' => $this->f['course']->id,
            'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id,
            'section_name' => 'CURRENT-B', 'year_level' => 1, 'capacity' => 40, 'status' => 'open']);
        $this->action('assign', ['section_id' => $replacement->id])->assertOk();
        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', 'CURRENT-B')
            ->assertJsonPath('data.0.current_enrollment.assignment_state', 'assigned_pending_finalization');
        $this->schedule(['section_id' => $replacement->id, 'start_time' => '12:00', 'end_time' => '13:00'])->assertCreated();
        $this->schedule(['section_id' => $replacement->id, 'subject_id' => $this->f['subjects'][1]->id,
            'start_time' => '13:00', 'end_time' => '14:00'])->assertCreated();
        $currentId = $this->action('finalize')->assertOk()->json('data.enrollment_id');
        $previousYear = AcademicYear::create(['school_year' => '2024-2025', 'start_date' => '2024-06-01',
            'end_date' => '2025-05-31', 'status' => 'completed']);
        $historicalSection = Section::create(['course_id' => $this->f['course']->id,
            'academic_year_id' => $previousYear->id, 'semester_id' => $this->f['semester']->id,
            'section_name' => 'HISTORICAL-A', 'year_level' => 1, 'capacity' => 40, 'status' => 'closed']);
        Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $historicalSection->id,
            'academic_year_id' => $previousYear->id, 'semester_id' => $this->f['semester']->id,
            'enrollment_date' => '2024-06-01', 'status' => 'completed']);

        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', 'CURRENT-B')
            ->assertJsonPath('data.0.current_enrollment.id', $currentId)
            ->assertJsonPath('data.0.current_enrollment.assignment_state', 'official')
            ->assertJsonPath('data.0.current_enrollment.record_scope', 'current');
        $this->getJson('/api/students/'.$this->f['student']->id.'/history')->assertOk()
            ->assertJsonPath('data.overview.section', 'CURRENT-B')
            ->assertJsonPath('data.academic_history.1.section', 'HISTORICAL-A');

        $this->f['period']->update(['enabled' => false]);
        $this->f['year']->update(['status' => 'completed']);
        $this->f['semester']->update(['status' => 'inactive']);
        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', 'CURRENT-B')
            ->assertJsonPath('data.0.current_enrollment.record_scope', 'latest');
    }

    public function test_continuing_student_without_admission_or_section_has_empty_section_state(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $profile = $user->profile()->create(['first_name' => 'Continuing', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $student = Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id,
            'course_id' => $this->f['course']->id, 'curriculum_id' => $this->f['curriculum']->id,
            'student_number' => 'CONTINUING-NO-SECTION', 'year_level' => 2,
            'admission_date' => now()->subYear(), 'student_status' => 'regular']);

        $this->getJson('/api/students?search=CONTINUING-NO-SECTION')->assertOk()
            ->assertJsonPath('data.0.current_section', null)
            ->assertJsonPath('data.0.admission_record', 'No linked Admission record');
        $this->getJson('/api/students/'.$student->id.'/history')->assertOk()
            ->assertJsonPath('data.overview.section', null)
            ->assertJsonPath('data.overview.section_state', 'not_assigned')
            ->assertJsonPath('data.entry_classification', 'Continuing');
    }

    public function test_irregular_selection_duplicates_curriculum_and_staff_review(): void
    {
        $a = $this->f['application'];
        $a->update(['classification' => 'irregular']);
        Sanctum::actingAs($this->f['user']);
        $id = $this->f['subjects'][0]->id;
        $this->action('subjects', ['subject_ids' => [$id, $id]])->assertUnprocessable();
        $this->action('subjects', ['subject_ids' => [999999]])->assertUnprocessable();
        $this->action('subjects', ['subject_ids' => [$id]])->assertOk()->assertJsonPath('data.load_reviewed_at', null);
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertForbidden();
        $this->action('finalize')->assertForbidden();
        Sanctum::actingAs($this->f['registrar']);
        $this->action('subjects', ['subject_ids' => [$id]])->assertOk()->assertJsonPath('data.load_reviewed_by', $this->f['registrar']->id);
        $this->getJson($this->base().'/applications/'.$a->id)->assertJsonPath('data.total_units', 3);
    }

    public function test_completed_subjects_and_missing_prerequisites_are_blocked(): void
    {
        $this->f['application']->update(['classification' => 'irregular']);
        $subject = $this->f['subjects'][1];
        $subject->update(['prerequisite_subject_id' => $this->f['subjects'][0]->id]);
        $this->action('subjects', ['subject_ids' => [$subject->id]])->assertUnprocessable();
        $previous = AcademicYear::create(['school_year' => 'PRIOR-ACADEMIC', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'completed']);
        $e = Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $previous->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => '2024-06-01', 'status' => 'completed']);
        EnrollmentSubject::create(['enrollment_id' => $e->id, 'subject_id' => $this->f['subjects'][0]->id, 'subject_status' => 'completed', 'remarks' => 'Passed']);
        $this->action('subjects', ['subject_ids' => [$subject->id]])->assertOk();
        $this->action('subjects', ['subject_ids' => [$this->f['subjects'][0]->id]])->assertUnprocessable();
    }

    public function test_transferee_returnee_loads_require_staff_and_preserve_classification(): void
    {
        foreach (['transferee', 'returnee'] as $classification) {
            $this->f['application']->update(['classification' => $classification]);
            Sanctum::actingAs($this->f['user']);
            $this->action('subjects', ['subject_ids' => [$this->f['subjects'][0]->id]])->assertForbidden();
            Sanctum::actingAs($this->f['registrar']);
            $this->action('subjects', ['subject_ids' => [$this->f['subjects'][0]->id]])->assertOk()->assertJsonPath('data.classification', $classification);
        }
    }

    public function test_unapproved_stale_and_ineligible_finalizations_fail(): void
    {
        $this->f['application']->update(['status' => 'submitted']);
        $this->action('finalize')->assertConflict();
        $this->f['application']->update(['status' => 'approved']);
        $this->prepare();
        $this->postJson($this->base().'/applications/'.$this->f['application']->id.'/finalize', ['version' => 1, 'confirmed' => true])->assertConflict();
        $this->f['student']->update(['student_status' => 'leave_of_absence']);
        $this->action('finalize')->assertUnprocessable();
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_capacity_reservations_and_stale_assignments_are_enforced(): void
    {
        $this->f['section']->update(['capacity' => 1]);
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $profile = $user->profile()->create(['first_name' => 'Second', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $s = Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $this->f['course']->id, 'curriculum_id' => $this->f['curriculum']->id, 'student_number' => 'ACADEMIC-SECOND', 'year_level' => 1, 'admission_date' => now()->toDateString(), 'student_status' => 'regular']);
        $a = $this->f['application']->replicate();
        $a->student_id = $s->id;
        $a->owner_user_id = $user->id;
        $a->section_id = null;
        $a->save();
        $this->postJson($this->base().'/applications/'.$a->id.'/assign', ['version' => $a->version, 'section_id' => $this->f['section']->id])->assertConflict();
        $this->getJson($this->base().'/sections')->assertJsonPath('data.data.0.reserved_count', 1);
    }

    public function test_finalization_audit_failure_rolls_back_all_records_and_notifications(): void
    {
        $this->prepare();
        $notices = DB::table('notifications')->count();
        EnrollmentWorkflowEvent::creating(function ($event) {
            if ($event->action === 'enrollment.finalized') {
                throw new \RuntimeException('private test failure');
            }
        });
        $this->action('finalize')->assertStatus(503)->assertDontSee('private test failure');
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('enrollment_subjects', 0);
        $this->assertDatabaseCount('notifications', $notices);
        $this->assertNull($this->f['application']->fresh()->enrollment_id);
    }

    public function test_existing_term_enrollment_is_not_overwritten(): void
    {
        $this->prepare();
        Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => now()->toDateString(), 'status' => 'cancelled']);
        $this->action('finalize')->assertConflict();
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('enrollment_subjects', 0);
    }

    public function test_schedule_conflicts_for_section_professor_room_and_adjacent_classes(): void
    {
        $this->schedule()->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '09:30', 'end_time' => '10:30', 'room' => 'Different'])->assertConflict();
        $other = $this->f['section']->replicate();
        $other->section_name = 'OTHER';
        $other->save();
        $this->schedule(['section_id' => $other->id, 'room' => 'Different'])->assertConflict();
        $pu = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $pp = $pu->profile()->create(['first_name' => 'Other', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        $p = Professor::create(['user_id' => $pu->id, 'user_profile_id' => $pp->id, 'department_id' => $this->f['department']->id, 'employee_number' => 'OTHER-P', 'status' => 'active']);
        $this->schedule(['section_id' => $other->id, 'professor_id' => $p->id, 'room' => ' ac room '])->assertConflict();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][2]->id, 'start_time' => '11:00', 'end_time' => '10:00'])->assertUnprocessable();
        $this->schedule(['day' => 'Invalid'])->assertUnprocessable();
    }

    public function test_post_completes_an_existing_unscheduled_teaching_assignment(): void
    {
        $assignment = SectionSubject::create([
            'section_id' => $this->f['section']->id,
            'subject_id' => $this->f['subjects'][0]->id,
            'professor_id' => $this->f['professor']->id,
        ]);

        $payload = [
            'assignment_id' => $assignment->id,
            'section_id' => $this->f['section']->id,
            'subject_id' => $this->f['subjects'][0]->id,
            'professor_id' => $this->f['professor']->id,
            'day' => 'Tuesday',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'room' => 'Room 101',
        ];

        $this->postJson($this->base().'/schedules', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.day', 'Tuesday')
            ->assertJsonPath('data.room', 'ROOM 101');

        $this->assertDatabaseCount('section_subjects', 1);
        $this->assertDatabaseHas('section_subjects', [
            'id' => $assignment->id,
            'professor_id' => $this->f['professor']->id,
            'day' => 'Tuesday',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'room' => 'ROOM 101',
            'version' => 2,
        ]);

        $this->postJson($this->base().'/schedules', $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'This subject already has a schedule. Reload before continuing.');
        $this->assertDatabaseCount('section_subjects', 1);
    }

    public function test_schedule_versions_edits_deletion_and_referenced_protection(): void
    {
        $this->prepare();
        $s = SectionSubject::first();
        $payload = $s->only(['section_id', 'subject_id', 'professor_id', 'day', 'room', 'version']);
        $payload += ['start_time' => '08:00', 'end_time' => '09:00'];
        $this->putJson($this->base().'/schedules/'.$s->id, $payload)->assertOk();
        $this->putJson($this->base().'/schedules/'.$s->id, $payload)->assertConflict();
        $this->action('finalize')->assertOk();
        $this->deleteJson($this->base().'/schedules/'.$s->id, ['version' => $s->fresh()->version, 'confirmed' => true])->assertConflict();
    }

    public function test_staff_reassignment_transfers_draft_grading_and_all_professor_scopes(): void
    {
        $this->prepare();
        $this->action('finalize')->assertOk();
        $assignment = SectionSubject::query()->where('subject_id', $this->f['subjects'][0]->id)->firstOrFail();

        Sanctum::actingAs($this->f['professorUser']);
        $sheet = $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/classes/'.$assignment->id.'/workspace')
            ->assertOk()
            ->json('data.sheet');

        $otherUser = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $otherProfile = $otherUser->profile()->create(['first_name' => 'Replacement', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        $other = Professor::create(['user_id' => $otherUser->id, 'user_profile_id' => $otherProfile->id, 'department_id' => $this->f['department']->id, 'employee_number' => 'REASSIGN-P', 'status' => 'active']);
        $payload = [
            'section_id' => $assignment->section_id,
            'subject_id' => $assignment->subject_id,
            'professor_id' => $other->id,
            'day' => $assignment->day,
            'start_time' => substr($assignment->start_time, 0, 5),
            'end_time' => substr($assignment->end_time, 0, 5),
            'room' => $assignment->room,
            'version' => $assignment->version,
        ];

        Sanctum::actingAs($this->f['registrar']);
        $this->withHeader('X-CDM-Client', 'web')->putJson($this->base().'/schedules/'.$assignment->id, $payload)->assertForbidden();
        $this->withHeader('X-CDM-Client', 'desktop')->putJson($this->base().'/schedules/'.$assignment->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.professor_id', $other->id);
        $this->assertDatabaseHas('grade_sheets', ['id' => $sheet['id'], 'professor_id' => $other->id, 'status' => 'draft']);
        $this->assertDatabaseHas('enrollment_subjects', ['subject_id' => $assignment->subject_id, 'professor_id' => $other->id]);

        $secondAssignment = SectionSubject::query()->where('subject_id', $this->f['subjects'][1]->id)->firstOrFail();
        $this->withHeader('X-CDM-Client', 'desktop')->putJson($this->base().'/schedules/'.$secondAssignment->id, [
            'section_id' => $secondAssignment->section_id,
            'subject_id' => $secondAssignment->subject_id,
            'professor_id' => $other->id,
            'day' => $secondAssignment->day,
            'start_time' => substr($secondAssignment->start_time, 0, 5),
            'end_time' => substr($secondAssignment->end_time, 0, 5),
            'room' => $secondAssignment->room,
            'version' => $secondAssignment->version,
        ])->assertOk()->assertJsonPath('data.professor_id', $other->id);
        $this->assertFalse(app(EarlyWarningService::class)->professorCanAccessStudent($this->f['professorUser']->id, $this->f['student']->id));
        $this->assertTrue(app(EarlyWarningService::class)->professorCanAccessStudent($otherUser->id, $this->f['student']->id));

        $this->f['section']->update(['adviser_id' => $this->f['professor']->id]);
        Sanctum::actingAs($this->f['professorUser']);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/classes')->assertOk()->assertJsonPath('data.total', 0);
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/classes/'.$assignment->id.'/workspace')->assertNotFound();
        $this->withHeader('X-CDM-Client', 'web')->getJson($this->base().'/professor/sections/'.$this->f['section']->id)->assertNotFound();
        $this->withHeader('X-CDM-Client', 'web')->putJson($this->base().'/schedules/'.$assignment->id, $payload)->assertForbidden();

        Sanctum::actingAs($otherUser);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/classes')->assertOk()->assertJsonPath('data.total', 2);
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/classes/'.$assignment->id.'/workspace')->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->getJson($this->base().'/professor/sections/'.$this->f['section']->id)->assertOk();

        Sanctum::actingAs($this->f['user']);
        $this->withHeader('X-CDM-Client', 'web')->putJson($this->base().'/schedules/'.$assignment->id, $payload)->assertForbidden();

        $guest = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::GUEST])->id]);
        Sanctum::actingAs($guest);
        $this->withHeader('X-CDM-Client', 'web')->putJson($this->base().'/schedules/'.$assignment->id, $payload)->assertForbidden();

        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id]);
        $current = $assignment->fresh();
        Sanctum::actingAs($admin);
        $this->withHeader('X-CDM-Client', 'desktop')->putJson($this->base().'/schedules/'.$assignment->id, array_replace($payload, [
            'professor_id' => $this->f['professor']->id,
            'version' => $current->version,
        ]))->assertOk()->assertJsonPath('data.professor_id', $this->f['professor']->id);

        GradeSheet::findOrFail($sheet['id'])->update(['status' => 'published']);
        $current = $assignment->fresh();
        Sanctum::actingAs($this->f['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->putJson($this->base().'/schedules/'.$assignment->id, array_replace($payload, [
            'version' => $current->version,
        ]))->assertConflict()->assertJsonPath('message', 'A submitted, approved, or published grade sheet prevents Professor reassignment.');
        $this->assertDatabaseHas('section_subjects', ['id' => $assignment->id, 'professor_id' => $this->f['professor']->id]);
        $this->assertDatabaseHas('grade_sheets', ['id' => $sheet['id'], 'professor_id' => $this->f['professor']->id, 'status' => 'published']);
    }

    public function test_both_staff_can_manage_sections_and_invalid_year_or_capacity_fails(): void
    {
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $i => $role) {
            $u = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]);
            Sanctum::actingAs($u);
            $payload = ['course_id' => $this->f['course']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'section_name' => 'NEW-'.$i, 'year_level' => 1, 'capacity' => 20, 'status' => 'open'];
            $s = $this->postJson($this->base().'/sections', $payload)->assertCreated()->json('data');
            $this->putJson($this->base().'/sections/'.$s['id'], $payload + ['version' => 1])->assertOk();
            $this->postJson($this->base().'/sections', array_replace($payload, ['year_level' => 20]))->assertUnprocessable();
        }
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
        $s = $this->f['section']->fresh();
        $this->putJson($this->base().'/sections/'.$s->id, array_replace($s->toArray(), ['capacity' => 0]))->assertUnprocessable();
    }

    public function test_ownership_professor_rosters_staff_equality_and_cor_boundaries(): void
    {
        $this->prepare();
        $a = $this->action('finalize')->assertOk()->json('data');
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            $u = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]);
            Sanctum::actingAs($u);
            $this->getJson($this->base().'/records?search='.urlencode($this->f['student']->student_number).'&sort=name&classification=regular')->assertOk()->assertJsonPath('data.total', 1);
            $this->getJson($this->base().'/records/'.$a['enrollment_id'])->assertOk();
        }
        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            $u = User::factory()->create(['role_id' => Role::where('role_name', $role)->value('id')]);
            Sanctum::actingAs($u);
            $this->getJson($this->base().'/sections')->assertForbidden();
            $this->getJson($this->base().'/records/'.$a['enrollment_id'])->assertStatus($role === Role::STUDENT ? 404 : 403);
        }
        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson($this->base().'/professor')->assertOk()->assertJsonPath('data.total', 1);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson($this->base().'/professor')
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->withHeader('X-CDM-Client', 'web')->getJson($this->base().'/professor/sections/'.$this->f['section']->id)->assertOk()->assertJsonPath('data.total', 1);

        $unrelatedUser = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $unrelatedProfile = $unrelatedUser->profile()->create(['first_name' => 'Unrelated', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $unrelatedStudent = Student::create([
            'user_id' => $unrelatedUser->id,
            'user_profile_id' => $unrelatedProfile->id,
            'course_id' => $this->f['course']->id,
            'curriculum_id' => $this->f['curriculum']->id,
            'student_number' => 'UNRELATED-SUBJECT',
            'year_level' => 1,
            'admission_date' => now()->toDateString(),
            'student_status' => 'regular',
        ]);
        $unrelatedEnrollment = Enrollment::create([
            'student_id' => $unrelatedStudent->id,
            'section_id' => $this->f['section']->id,
            'academic_year_id' => $this->f['year']->id,
            'semester_id' => $this->f['semester']->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'enrolled',
        ]);
        EnrollmentSubject::create([
            'enrollment_id' => $unrelatedEnrollment->id,
            'subject_id' => $this->f['subjects'][2]->id,
            'subject_status' => 'enrolled',
        ]);
        $this->getJson($this->base().'/professor/sections/'.$this->f['section']->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonMissing(['student_number' => 'UNRELATED-SUBJECT']);

        $other = $this->f['section']->replicate();
        $other->section_name = 'UNASSIGNED';
        $other->save();
        $this->getJson($this->base().'/professor/sections/'.$other->id)->assertNotFound();
        $staff = $this->f['registrar'];
        $staff->update(['status' => 'inactive']);
        Sanctum::actingAs($staff);
        $this->getJson($this->base().'/sections')->assertForbidden();
    }
}
