<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeStudentDocument;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentDocumentAiAnalysis;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Ai\Analyzers\GeminiDocumentAnalyzer;
use App\Services\Ai\Analyzers\MockDocumentAnalyzer;
use App\Services\Ai\Contracts\DocumentAnalyzer;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\DocumentAnalyzerException;
use App\Services\Demo\StudentDocumentDemoFixtureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StudentDocumentAiAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_registrar_can_upload_a_valid_image_and_birth_certificate_analysis_is_queued(): void
    {
        Queue::fake();
        $document = $this->createBirthCertificate();
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->postJson("/api/registrar/student-documents/{$document->id}/upload", [
            'file' => UploadedFile::fake()->image('birth-certificate.jpg', 1200, 800),
        ])
            ->assertOk()
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.ai_analysis.status', 'pending');

        $document->refresh();
        $this->assertStringStartsWith(
            "student-documents/{$document->student_id}/{$document->document_type_id}-birth-certificate/",
            $document->file_path,
        );
        Storage::disk('local')->assertExists($document->file_path);
        Queue::assertPushedOn('document-analysis', AnalyzeStudentDocument::class);
        Queue::assertPushed(AnalyzeStudentDocument::class, 1);
    }

    public function test_registrar_can_upload_a_valid_pdf_without_queueing_an_unsupported_document(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $document = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $this->createDocumentType('Registration Form')->id,
        ]);
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->postJson("/api/registrar/student-documents/{$document->id}/upload", [
            'file' => UploadedFile::fake()->create('registration-form.pdf', 200, 'application/pdf'),
        ])
            ->assertOk()
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.ai_analysis', null);

        Storage::disk('local')->assertExists($document->fresh()->file_path);
        Queue::assertNothingPushed();
    }

    public function test_upload_rejects_invalid_and_oversized_files(): void
    {
        $document = $this->createBirthCertificate();
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->postJson("/api/registrar/student-documents/{$document->id}/upload", [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->postJson("/api/registrar/student-documents/{$document->id}/upload", [
            'file' => UploadedFile::fake()->create('too-large.pdf', 10 * 1024 + 1, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertNull($document->fresh()->file_path);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_replacement_updates_the_path_deletes_the_old_file_and_requeues_analysis(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $type = $this->createDocumentType('Birth Certificate');
        $oldPath = "student-documents/{$student->id}/{$type->id}-birth-certificate/original.pdf";
        Storage::disk('local')->put($oldPath, 'old document');
        $document = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'file_path' => $oldPath,
            'availability_status' => 'available',
        ]);
        Queue::fake();
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->postJson("/api/registrar/student-documents/{$document->id}/upload", [
            'file' => UploadedFile::fake()->image('replacement.png'),
        ])->assertOk()->assertJsonPath('message', 'Student document replaced successfully.');

        $newPath = $document->fresh()->file_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
        Queue::assertPushed(AnalyzeStudentDocument::class, 1);
    }

    public function test_view_and_download_are_private_and_registrar_only(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $type = $this->createDocumentType('Form 137');
        $path = "student-documents/{$student->id}/{$type->id}-form-137/document.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $document = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'file_path' => $path,
            'availability_status' => 'available',
        ]);

        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));
        $this->get("/api/registrar/student-documents/{$document->id}/view")
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=form-137-'.$document->id.'.pdf');
        $this->get("/api/registrar/student-documents/{$document->id}/download")
            ->assertOk()
            ->assertDownload('form-137-'.$document->id.'.pdf');

        Sanctum::actingAs($this->createUserWithRole(Role::ADMIN));
        $this->get("/api/registrar/student-documents/{$document->id}/view")->assertForbidden();
        $this->get("/api/registrar/student-documents/{$document->id}/download")->assertForbidden();

        Sanctum::actingAs($this->createUserWithRole(Role::STUDENT));
        $this->get("/api/registrar/student-documents/{$document->id}/view")->assertForbidden();
        $this->get("/api/registrar/student-documents/{$document->id}/download")->assertForbidden();
    }

    public function test_deleting_a_file_requires_step_up_and_marks_the_document_missing(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $type = $this->createDocumentType('Birth Certificate');
        $path = "student-documents/{$student->id}/{$type->id}-birth-certificate/document.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $document = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'file_path' => $path,
            'availability_status' => 'available',
            'verification_status' => 'verified',
            'submitted_date' => today(),
        ]);
        $registrar = $this->createUserWithRole(Role::REGISTRAR_STAFF);
        Sanctum::actingAs($registrar);

        $this->deleteJson("/api/registrar/student-documents/{$document->id}/file")
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');
        Storage::disk('local')->assertExists($path);

        $this->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->deleteJson("/api/registrar/student-documents/{$document->id}/file")
            ->assertOk()
            ->assertJsonPath('data.has_file', false)
            ->assertJsonPath('data.status', 'missing')
            ->assertJsonPath('data.ai_analysis', null);

        $document->refresh();
        $this->assertNull($document->file_path);
        $this->assertNull($document->submitted_date);
        $this->assertSame('missing', $document->availability_status);
        $this->assertSame('pending', $document->verification_status);
        $this->assertNull($document->aiAnalysis()->first());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_document_analyzer_binding_switches_between_mock_and_gemini(): void
    {
        config()->set('services.document_analysis.driver', 'mock');
        $this->assertInstanceOf(MockDocumentAnalyzer::class, $this->app->make(DocumentAnalyzer::class));

        $this->configureGemini(model: 'gemini-2.5-flash');
        $this->assertInstanceOf(GeminiDocumentAnalyzer::class, $this->app->make(DocumentAnalyzer::class));
    }

    public function test_invalid_gemini_configuration_fails_with_a_clear_exception(): void
    {
        config()->set('services.document_analysis.driver', 'gemini');
        config()->set('services.document_analysis.gemini.api_key', null);
        config()->set('services.document_analysis.gemini.model', 'gemini-test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY must be configured');

        $this->app->make(DocumentAnalyzer::class);
    }

    public function test_gemini_extracts_a_valid_birth_certificate_and_laravel_computes_matches(): void
    {
        $contents = '%PDF-private-birth-certificate';
        $document = $this->createGeminiBirthCertificate($contents);
        $this->configureGemini(model: 'gemini-2.5-flash');
        $this->fakeGemini();

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertSame('birth_certificate', $result['detected_document_type']);
        $this->assertSame(0.94, $result['confidence']);
        $this->assertSame('Sample Student', $result['extracted_data']['name']);
        $this->assertSame('2004-03-12', $result['extracted_data']['date_of_birth']);
        $this->assertSame([
            'document_type_match' => true,
            'name_match' => true,
            'date_of_birth_match' => true,
            'readable' => true,
        ], $result['checks']);
        $this->assertSame([], $result['issues']);
        $this->assertSame('review_and_approve', $result['recommendation']);
        $this->assertSame('gemini', $result['provider']);
        $this->assertSame('gemini-2.5-flash', $result['model']);

        Http::assertSent(function (Request $request) use ($contents): bool {
            $data = $request->data();

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
                && ! str_contains($request->url(), 'test-api-key')
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && data_get($data, 'contents.0.parts.0.inlineData.mimeType') === 'application/pdf'
                && data_get($data, 'contents.0.parts.0.inlineData.data') === base64_encode($contents)
                && str_contains(data_get($data, 'contents.0.parts.1.text'), 'Expected document type: Birth Certificate')
                && data_get($data, 'generationConfig.responseMimeType') === 'application/json'
                && data_get($data, 'generationConfig.responseSchema.type') === 'OBJECT'
                && data_get($data, 'generationConfig.responseSchema.required.0') === 'detected_document_type'
                && data_get($data, 'generationConfig.responseFormat') === null;
        });
    }

    public function test_gemini_wrong_document_type_is_likely_incorrect(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        $this->fakeGemini(['detected_document_type' => 'Form 137']);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertFalse($result['checks']['document_type_match']);
        $this->assertSame('likely_incorrect', $result['recommendation']);
    }

    public function test_gemini_normalizes_certificate_of_enrollment_and_laravel_matches_student_fields(): void
    {
        $document = $this->createGeminiDocument('Certificate of Enrollment');
        $this->configureGemini();
        $this->fakeGemini([
            'detected_document_type' => 'Enrollment Certificate',
            'student_number' => str_replace('-', ' ', $document->student->student_number),
            'course_program' => 'Bachelor of Science in Information Technology (BSIT)',
            'year_level' => '1st Year',
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'institution' => 'Colegio de Montalban',
        ]);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Certificate of Enrollment');

        $this->assertSame('certificate_of_enrollment', $result['detected_document_type']);
        $this->assertSame([
            'document_type_match' => true,
            'name_match' => true,
            'student_number_match' => true,
            'course_match' => true,
            'year_level_match' => true,
            'readable' => true,
        ], $result['checks']);
        $this->assertSame('review_and_approve', $result['recommendation']);
        $this->assertSame('2026-2027', $result['extracted_data']['academic_year']);

        Http::assertSent(fn (Request $request): bool => data_get(
            $request->data(),
            'generationConfig.responseSchema.properties.student_number.type',
        ) === 'STRING' && data_get(
            $request->data(),
            'generationConfig.responseSchema.properties.date_of_birth',
        ) === null);
    }

    public function test_missing_optional_enrollment_fields_need_review_instead_of_failing(): void
    {
        $document = $this->createGeminiDocument('Certificate of Enrollment');
        $this->configureGemini();
        $this->fakeGemini([
            'detected_document_type' => 'Certificate of Enrollment',
            'student_number' => null,
            'course_program' => null,
            'year_level' => null,
        ]);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Certificate of Enrollment');

        $this->assertNull($result['checks']['student_number_match']);
        $this->assertNull($result['checks']['course_match']);
        $this->assertNull($result['checks']['year_level_match']);
        $this->assertSame('needs_review', $result['recommendation']);
    }

    public function test_gemini_normalizes_form_137_and_checks_academic_record_structure(): void
    {
        $document = $this->createGeminiDocument('Form 137');
        $this->configureGemini();
        $this->fakeGemini([
            'detected_document_type' => 'Learner Permanent Record',
            'school_name' => 'Sample Secondary School',
            'grade_year_information' => 'Grades 7 to 10',
            'subjects_grades_present' => true,
            'school_year' => '2021-2025',
        ]);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Form 137');

        $this->assertSame('form_137', $result['detected_document_type']);
        $this->assertTrue($result['checks']['name_match']);
        $this->assertTrue($result['checks']['school_document_structure_present']);
        $this->assertSame('review_and_approve', $result['recommendation']);
        $this->assertTrue($result['extracted_data']['subjects_grades_present']);
    }

    public function test_gemini_normalizes_good_moral_and_requires_an_issuer(): void
    {
        $document = $this->createGeminiDocument('Good Moral Certificate');
        $this->configureGemini();
        $this->fakeGemini([
            'detected_document_type' => 'Certificate of Good Moral Character',
            'issuing_school' => 'Sample Secondary School',
            'date_issued' => 'August 29, 2026',
            'signatory_name' => 'Sample Principal',
            'signatory_position' => 'Principal',
        ]);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Good Moral Certificate');

        $this->assertSame('good_moral', $result['detected_document_type']);
        $this->assertTrue($result['checks']['issuer_present']);
        $this->assertSame('2026-08-29', $result['extracted_data']['date_issued']);
        $this->assertSame('review_and_approve', $result['recommendation']);
    }

    public function test_enrollment_student_number_mismatch_is_computed_by_laravel(): void
    {
        $document = $this->createGeminiDocument('Certificate of Enrollment');
        $this->configureGemini();
        $this->fakeGemini([
            'detected_document_type' => 'Certificate of Enrollment',
            'student_number' => '99-99999',
            'course_program' => 'BSIT',
            'year_level' => 'Year 1',
        ]);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Certificate of Enrollment');

        $this->assertFalse($result['checks']['student_number_match']);
        $this->assertSame('likely_incorrect', $result['recommendation']);
    }

    public function test_local_psa_demo_fixture_uses_private_storage_and_the_existing_ai_queue(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        Queue::fake();
        $student = $this->createStudent();
        $this->createDocumentType('Birth Certificate');
        $fixtures = $this->createSyntheticDemoFixtures(['psa-sample.jpg']);

        $result = (new StudentDocumentDemoFixtureService($fixtures))->install($student, 'psa');
        $document = $result['document'];

        $this->assertSame('Birth Certificate', $document->documentType->document_name);
        $this->assertTrue($document->supportsAiAnalysis());
        $this->assertStringStartsWith("student-documents/{$student->id}/", $document->file_path);
        $this->assertStringNotContainsString('CDM_Frontend', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertSame(StudentDocumentAiAnalysis::STATUS_PENDING, $document->aiAnalysis->status);
        Queue::assertPushedOn('document-analysis', AnalyzeStudentDocument::class);

        $this->configureGemini();
        $this->fakeGemini(['detected_document_type' => 'Birth Certificate']);
        Http::assertNothingSent();
        $analysisResult = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertSame('birth_certificate', $analysisResult['detected_document_type']);
        $this->assertTrue($analysisResult['checks']['document_type_match']);
    }

    public function test_registration_form_demo_fixture_is_a_wrong_birth_certificate_with_mocked_gemini(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        Queue::fake();
        $student = $this->createStudent();
        $this->createDocumentType('Birth Certificate');
        $fixtures = $this->createSyntheticDemoFixtures(['registration-form-sample.jpg']);
        $document = (new StudentDocumentDemoFixtureService($fixtures))->install(
            $student,
            'registration-form',
            'Birth Certificate',
        )['document'];
        $this->configureGemini();
        $this->fakeGemini(['detected_document_type' => 'Registration Form']);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertFalse($result['checks']['document_type_match']);
        $this->assertSame('likely_incorrect', $result['recommendation']);
        Queue::assertPushedOn('document-analysis', AnalyzeStudentDocument::class);
    }

    public function test_expanded_demo_fixtures_use_the_supported_document_mappings(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        Queue::fake();
        $student = $this->createStudent();
        $this->createDocumentType('Certificate of Enrollment');
        $this->createDocumentType('Form 137');
        $this->createDocumentType('Good Moral Certificate');
        $fixtures = $this->createSyntheticDemoFixtures([
            'certificate-of-enrollment-sample.jpg',
            'school-certificate-sample.jpg',
            'form-137-sample.webp',
            'good-moral-sample.png',
        ]);
        $service = new StudentDocumentDemoFixtureService($fixtures);

        $first = $service->install($student, 'certificate-of-enrollment')['document'];
        $second = $service->install($student, 'school-certificate', replace: true)['document'];
        $form137 = $service->install($student, 'form-137')['document'];
        $goodMoral = $service->install($student, 'good-moral')['document'];

        $this->assertTrue($first->supportsAiAnalysis());
        $this->assertTrue($second->supportsAiAnalysis());
        $this->assertTrue($form137->supportsAiAnalysis());
        $this->assertTrue($goodMoral->supportsAiAnalysis());
        $this->assertSame(StudentDocumentAiAnalysis::STATUS_PENDING, $second->aiAnalysis->status);
        $this->assertStringEndsWith('.webp', $form137->file_path);
        Queue::assertPushed(AnalyzeStudentDocument::class, 4);

        $this->configureGemini();
        $this->fakeGemini([
            'detected_document_type' => 'Form 137',
            'subjects_grades_present' => true,
        ]);
        $this->app->make(DocumentAnalyzer::class)->analyze($form137, 'Form 137');
        Http::assertSent(fn (Request $request): bool => data_get(
            $request->data(),
            'contents.0.parts.0.inlineData.mimeType',
        ) === 'image/webp');
    }

    public function test_demo_document_command_is_not_registered_outside_local_environment(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertArrayNotHasKey('demo:student-documents', Artisan::all());
    }

    public function test_laravel_name_and_date_normalization_tolerates_common_formatting(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        $this->fakeGemini([
            'student_full_name' => '  student, SAMPLE  ',
            'date_of_birth' => '03/12/2004',
        ]);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertTrue($result['checks']['name_match']);
        $this->assertTrue($result['checks']['date_of_birth_match']);
        $this->assertSame('2004-03-12', $result['extracted_data']['date_of_birth']);
        $this->assertSame('review_and_approve', $result['recommendation']);
    }

    public function test_low_confidence_gemini_extraction_needs_review(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        $this->fakeGemini(['confidence' => 0.55]);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertSame('needs_review', $result['recommendation']);
    }

    public function test_gemini_name_mismatch_is_computed_by_laravel(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        $this->fakeGemini(['student_full_name' => 'Clearly Different Person']);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertFalse($result['checks']['name_match']);
        $this->assertSame('likely_incorrect', $result['recommendation']);
        $this->assertContains('Extracted student name does not match the student profile.', $result['issues']);
    }

    public function test_gemini_dob_mismatch_is_computed_by_laravel(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        $this->fakeGemini(['date_of_birth' => '2001-01-01']);

        $result = $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');

        $this->assertFalse($result['checks']['date_of_birth_match']);
        $this->assertSame('likely_incorrect', $result['recommendation']);
        $this->assertContains('Extracted date of birth does not match the student profile.', $result['issues']);
    }

    public function test_gemini_malformed_json_is_rejected(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{not-json']]]]],
        ])]);

        $this->expectException(DocumentAnalyzerException::class);
        $this->expectExceptionMessage('malformed structured document analysis');

        $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');
    }

    public function test_gemini_timeout_is_safely_wrapped(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        Http::fake(['*' => Http::failedConnection('provider connection details')]);

        try {
            $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');
            $this->fail('A connection exception should have been wrapped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini document analysis timed out or could not connect.', $exception->getMessage());
        }
    }

    public function test_gemini_provider_error_is_safely_wrapped(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $this->configureGemini();
        Http::fake(['*' => Http::response(['error' => ['message' => 'provider details']], 429)]);

        try {
            $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');
            $this->fail('A rate-limit response should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini rate limit was reached.', $exception->getMessage());
        }
    }

    public function test_gemini_failures_do_not_log_credentials_or_document_contents(): void
    {
        $contents = 'private-document-contents-marker';
        $document = $this->createGeminiBirthCertificate($contents);
        $this->configureGemini(apiKey: 'secret-api-key-marker');
        Log::spy();
        Http::fake(['*' => Http::response(['error' => ['message' => $contents]], 500)]);

        try {
            $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');
            $this->fail('The provider failure should have thrown an exception.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('secret-api-key-marker', $exception->getMessage());
            $this->assertStringNotContainsString($contents, $exception->getMessage());
        }

        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_local_gemini_log_contains_safe_provider_diagnostics_and_redacts_sensitive_data(): void
    {
        $contents = 'private-document-contents-marker';
        $encodedContents = base64_encode($contents);
        $document = $this->createGeminiBirthCertificate($contents);
        $this->configureGemini(apiKey: 'secret-api-key-marker');
        $this->app->detectEnvironment(fn (): string => 'local');
        Log::spy();
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => "Unknown name responseFormat. secret-api-key-marker {$contents} {$encodedContents}",
            ],
        ], 400)]);

        try {
            $this->app->make(DocumentAnalyzer::class)->analyze($document, 'Birth Certificate');
            $this->fail('The invalid provider request should have thrown an exception.');
        } catch (DocumentAnalyzerException) {
            // The diagnostic assertion below verifies the sanitized logging context.
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Gemini document analysis failed.',
                Mockery::on(function (array $context) use ($contents, $encodedContents): bool {
                    $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                    return $context['exception_class'] === DocumentAnalyzerException::class
                        && $context['http_status'] === 400
                        && $context['gemini_error_code'] === 400
                        && $context['gemini_error_status'] === 'INVALID_ARGUMENT'
                        && str_contains($context['provider_message'], 'Unknown name responseFormat.')
                        && $context['configured_model'] === 'gemini-test'
                        && $context['mime_type'] === 'application/pdf'
                        && $context['stage'] === 'provider_response'
                        && ! str_contains($serialized, 'secret-api-key-marker')
                        && ! str_contains($serialized, $contents)
                        && ! str_contains($serialized, $encodedContents)
                        && ! str_contains($serialized, 'inlineData');
                }),
            );
    }

    public function test_queued_provider_failure_stays_generic_and_records_gemini_metadata(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $analysis = $document->aiAnalysis()->firstOrFail();
        $this->configureGemini();
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Unknown name responseFormat at generation_config.',
            ],
        ], 400)]);

        (new AnalyzeStudentDocument($analysis->id, $document->file_path))
            ->handle($this->app->make(DocumentAnalysisService::class));

        $analysis->refresh();
        $this->assertSame(StudentDocumentAiAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertSame('gemini', $analysis->provider);
        $this->assertSame('gemini-test', $analysis->model);
        $this->assertSame('Document analysis could not be completed.', $analysis->error_message);
        $this->assertNull($analysis->analyzed_at);

        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));
        $this->getJson("/api/students/{$document->student_id}/documents")
            ->assertOk()
            ->assertJsonPath('data.documents.0.ai_analysis.status', 'failed')
            ->assertJsonPath('data.documents.0.ai_analysis.provider', 'gemini')
            ->assertJsonPath('data.documents.0.ai_analysis.model', 'gemini-test')
            ->assertJsonMissingPath('data.documents.0.ai_analysis.error_message');
    }

    public function test_queued_gemini_job_stores_the_normalized_completed_result(): void
    {
        $document = $this->createGeminiBirthCertificate();
        $analysis = $document->aiAnalysis()->firstOrFail();
        $this->configureGemini();
        $this->fakeGemini();

        (new AnalyzeStudentDocument($analysis->id, $document->file_path))
            ->handle($this->app->make(DocumentAnalysisService::class));

        $analysis->refresh();
        $this->assertSame(StudentDocumentAiAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertSame('birth_certificate', $analysis->detected_document_type);
        $this->assertSame('0.9400', $analysis->confidence);
        $this->assertSame('Sample Student', $analysis->extracted_data['name']);
        $this->assertTrue($analysis->checks['name_match']);
        $this->assertTrue($analysis->checks['date_of_birth_match']);
        $this->assertSame('review_and_approve', $analysis->recommendation);
        $this->assertSame('gemini', $analysis->provider);
        $this->assertSame('gemini-test', $analysis->model);
        $this->assertNotNull($analysis->analyzed_at);
    }

    public function test_birth_certificate_upload_creates_a_pending_analysis_and_dispatches_a_job(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $documentType = $this->createDocumentType('Birth Certificate');

        $document = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'file_path' => 'student-documents/fake/birth-certificate.pdf',
            'availability_status' => 'available',
        ]);

        $analysis = $document->aiAnalysis()->firstOrFail();

        $this->assertSame(StudentDocumentAiAnalysis::STATUS_PENDING, $analysis->status);
        $this->assertDatabaseCount('student_document_ai_analyses', 1);
        Queue::assertPushedOn(
            'document-analysis',
            AnalyzeStudentDocument::class,
            fn (AnalyzeStudentDocument $job): bool => $job->analysisId === $analysis->id
                && $job->filePath === $document->file_path,
        );
    }

    public function test_each_configured_document_type_triggers_the_existing_analysis_job(): void
    {
        Queue::fake();
        $student = $this->createStudent();

        foreach ([
            'Birth Certificate',
            'Certificate of Enrollment',
            'Form 137',
            'Good Moral Certificate',
        ] as $index => $name) {
            $document = StudentDocument::query()->create([
                'student_id' => $student->id,
                'document_type_id' => $this->createDocumentType($name)->id,
                'file_path' => "student-documents/{$student->id}/supported-{$index}.pdf",
                'availability_status' => 'available',
            ]);

            $this->assertSame(StudentDocumentAiAnalysis::STATUS_PENDING, $document->aiAnalysis()->firstOrFail()->status);
        }

        Queue::assertPushed(AnalyzeStudentDocument::class, 4);
        Queue::assertPushedOn('document-analysis', AnalyzeStudentDocument::class);
    }

    public function test_unrelated_document_type_does_not_create_analysis_or_dispatch_a_job(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $documentType = $this->createDocumentType('Registration Form');

        StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'file_path' => 'student-documents/fake/registration-form.pdf',
            'availability_status' => 'available',
        ]);

        $this->assertDatabaseCount('student_document_ai_analyses', 0);
        Queue::assertNothingPushed();
    }

    public function test_mock_analyzer_result_is_stored_as_completed(): void
    {
        config()->set('services.document_analysis.driver', 'mock');
        Queue::fake();
        $document = $this->createBirthCertificate('student-documents/fake/birth-certificate.pdf');
        $analysis = $document->aiAnalysis()->firstOrFail();

        (new AnalyzeStudentDocument($analysis->id, $document->file_path))
            ->handle($this->app->make(DocumentAnalysisService::class));

        $analysis->refresh();

        $this->assertSame(StudentDocumentAiAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertSame('birth_certificate', $analysis->detected_document_type);
        $this->assertSame('0.9900', $analysis->confidence);
        $this->assertSame([], $analysis->extracted_data);
        $this->assertTrue($analysis->checks['development_mock']);
        $this->assertSame('needs_review', $analysis->recommendation);
        $this->assertSame('mock', $analysis->provider);
        $this->assertSame('deterministic-development-v1', $analysis->model);
        $this->assertNotNull($analysis->analyzed_at);
    }

    public function test_failed_analysis_is_recorded_without_changing_the_document(): void
    {
        Queue::fake();
        $document = $this->createBirthCertificate('student-documents/fake/unreadable-birth-certificate.pdf');
        $analysis = $document->aiAnalysis()->firstOrFail();

        $this->app->instance(DocumentAnalyzer::class, new class implements DocumentAnalyzer
        {
            public function analyze(StudentDocument $document, string $expectedDocumentType): array
            {
                throw new RuntimeException('Sensitive provider failure details.');
            }
        });

        (new AnalyzeStudentDocument($analysis->id, $document->file_path))
            ->handle($this->app->make(DocumentAnalysisService::class));

        $analysis->refresh();

        $this->assertSame(StudentDocumentAiAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertSame('Document analysis could not be completed.', $analysis->error_message);
        $this->assertNull($analysis->analyzed_at);
        $this->assertDatabaseHas('student_documents', [
            'id' => $document->id,
            'file_path' => 'student-documents/fake/unreadable-birth-certificate.pdf',
        ]);
    }

    public function test_replacing_a_birth_certificate_resets_analysis_without_reanalyzing_unchanged_updates(): void
    {
        Queue::fake();
        $document = $this->createBirthCertificate('student-documents/fake/birth-certificate-v1.pdf');
        $analysis = $document->aiAnalysis()->firstOrFail();
        $analysis->update([
            'status' => StudentDocumentAiAnalysis::STATUS_COMPLETED,
            'confidence' => 0.8000,
            'provider' => 'mock',
            'analyzed_at' => now(),
        ]);

        Queue::fake();
        $document->update(['file_path' => 'student-documents/fake/birth-certificate-v2.pdf']);

        $analysis->refresh();
        $this->assertSame(StudentDocumentAiAnalysis::STATUS_PENDING, $analysis->status);
        $this->assertNull($analysis->confidence);
        $this->assertNull($analysis->provider);
        $this->assertNull($analysis->analyzed_at);
        Queue::assertPushed(AnalyzeStudentDocument::class, 1);

        $document->update(['remarks' => 'File unchanged.']);
        Queue::assertPushed(AnalyzeStudentDocument::class, 1);
        $this->assertDatabaseCount('student_document_ai_analyses', 1);
    }

    public function test_registrar_can_bulk_queue_multiple_eligible_documents_without_running_analysis_synchronously(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $first = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $this->createDocumentType('Birth Certificate')->id,
            'file_path' => 'student-documents/fake/birth-certificate-one.pdf',
            'availability_status' => 'available',
        ]);
        $second = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $this->createDocumentType('Form 137')->id,
            'file_path' => 'student-documents/fake/form-137.pdf',
            'availability_status' => 'available',
        ]);
        $first->aiAnalysis()->delete();
        $second->aiAnalysis()->update([
            'status' => StudentDocumentAiAnalysis::STATUS_FAILED,
            'error_message' => 'Document analysis could not be completed.',
        ]);
        Queue::fake();

        $analyzer = Mockery::mock(DocumentAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');
        $this->app->instance(DocumentAnalyzer::class, $analyzer);
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->postJson("/api/registrar/students/{$student->id}/documents/analyze-all")
            ->assertOk()
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.skipped_missing', 0)
            ->assertJsonPath('data.skipped_unsupported', 0)
            ->assertJsonPath('data.skipped_processing', 0)
            ->assertJsonPath('data.skipped_completed', 0);

        $this->assertDatabaseHas('student_document_ai_analyses', [
            'student_document_id' => $first->id,
            'status' => StudentDocumentAiAnalysis::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('student_document_ai_analyses', [
            'student_document_id' => $second->id,
            'status' => StudentDocumentAiAnalysis::STATUS_PENDING,
            'error_message' => null,
        ]);
        Queue::assertPushed(AnalyzeStudentDocument::class, 2);
        Queue::assertPushedOn('document-analysis', AnalyzeStudentDocument::class);
    }

    public function test_bulk_analysis_skips_missing_and_unsupported_documents(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $this->createDocumentType('Birth Certificate')->id,
            'file_path' => null,
            'availability_status' => 'missing',
        ]);
        StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $this->createDocumentType('Registration Form')->id,
            'file_path' => 'student-documents/fake/registration-form.pdf',
            'availability_status' => 'available',
        ]);
        Queue::fake();
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->postJson("/api/registrar/students/{$student->id}/documents/analyze-all")
            ->assertOk()
            ->assertJsonPath('data.queued', 0)
            ->assertJsonPath('data.skipped_missing', 1)
            ->assertJsonPath('data.skipped_unsupported', 1)
            ->assertJsonPath('data.skipped_processing', 0)
            ->assertJsonPath('data.skipped_completed', 0);

        Queue::assertNothingPushed();
    }

    public function test_bulk_analysis_does_not_duplicate_processing_or_completed_analyses(): void
    {
        Queue::fake();
        $student = $this->createStudent();
        $processing = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $this->createDocumentType('Birth Certificate')->id,
            'file_path' => 'student-documents/fake/processing.pdf',
            'availability_status' => 'available',
        ]);
        $completed = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $this->createDocumentType('Certificate of Enrollment')->id,
            'file_path' => 'student-documents/fake/completed.pdf',
            'availability_status' => 'available',
        ]);
        $processing->aiAnalysis()->update(['status' => StudentDocumentAiAnalysis::STATUS_PROCESSING]);
        $completed->aiAnalysis()->update([
            'status' => StudentDocumentAiAnalysis::STATUS_COMPLETED,
            'analyzed_at' => now(),
        ]);
        Queue::fake();
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->postJson("/api/registrar/students/{$student->id}/documents/analyze-all")
            ->assertOk()
            ->assertJsonPath('data.queued', 0)
            ->assertJsonPath('data.skipped_processing', 1)
            ->assertJsonPath('data.skipped_completed', 1);

        Queue::assertNothingPushed();
        $this->assertSame(StudentDocumentAiAnalysis::STATUS_PROCESSING, $processing->aiAnalysis()->firstOrFail()->status);
        $this->assertSame(StudentDocumentAiAnalysis::STATUS_COMPLETED, $completed->aiAnalysis()->firstOrFail()->status);
    }

    public function test_bulk_analysis_endpoint_is_registrar_staff_only(): void
    {
        Queue::fake();
        $student = $this->createStudent();

        Sanctum::actingAs($this->createUserWithRole(Role::ADMIN));
        $this->postJson("/api/registrar/students/{$student->id}/documents/analyze-all")->assertForbidden();

        Sanctum::actingAs($this->createUserWithRole(Role::STUDENT));
        $this->postJson("/api/registrar/students/{$student->id}/documents/analyze-all")->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_registrar_document_get_returns_stored_summary_without_dispatching_analysis(): void
    {
        Queue::fake();
        $document = $this->createBirthCertificate('student-documents/fake/birth-certificate.pdf');
        $document->aiAnalysis()->update([
            'status' => StudentDocumentAiAnalysis::STATUS_COMPLETED,
            'detected_document_type' => 'birth_certificate',
            'confidence' => 0.9900,
            'checks' => ['development_mock' => true],
            'issues' => ['Development placeholder only.'],
            'recommendation' => 'needs_review',
            'provider' => 'mock',
            'model' => 'deterministic-development-v1',
            'analyzed_at' => now(),
        ]);
        Queue::fake();
        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));

        $this->getJson("/api/students/{$document->student_id}/documents")
            ->assertOk()
            ->assertJsonPath('data.documents.0.ai_analysis.status', 'completed')
            ->assertJsonPath('data.documents.0.ai_analysis.detected_document_type', 'birth_certificate')
            ->assertJsonPath('data.documents.0.ai_analysis.confidence', '0.9900')
            ->assertJsonPath('data.documents.0.ai_analysis.checks.development_mock', true)
            ->assertJsonPath('data.documents.0.ai_analysis.recommendation', 'needs_review')
            ->assertJsonPath('data.documents.0.ai_analysis.provider', 'mock')
            ->assertJsonPath('data.documents.0.ai_analysis.is_mock', true)
            ->assertJsonPath('data.documents.0.ai_analysis_type.key', 'birth_certificate')
            ->assertJsonPath('data.documents.0.ai_analysis_type.label', 'Birth Certificate')
            ->assertJsonMissingPath('data.documents.0.ai_analysis.extracted_data');

        Queue::assertNothingPushed();
    }

    public function test_student_document_analysis_output_preserves_registrar_and_admin_access_rules(): void
    {
        $document = $this->createBirthCertificate();

        Sanctum::actingAs($this->createUserWithRole(Role::REGISTRAR_STAFF));
        $this->getJson("/api/students/{$document->student_id}/documents")->assertOk();

        Sanctum::actingAs($this->createUserWithRole(Role::ADMIN));
        $this->getJson("/api/students/{$document->student_id}/documents")->assertOk();

        Sanctum::actingAs($this->createUserWithRole(Role::STUDENT));
        $this->getJson("/api/students/{$document->student_id}/documents")->assertForbidden();
    }

    private function createBirthCertificate(?string $filePath = null): StudentDocument
    {
        $student = $this->createStudent();
        $documentType = $this->createDocumentType('Birth Certificate');

        return StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'file_path' => $filePath,
            'availability_status' => 'available',
        ]);
    }

    private function createGeminiBirthCertificate(string $contents = '%PDF-test-document'): StudentDocument
    {
        $document = $this->createGeminiDocument('Birth Certificate', $contents);
        $document->student->userProfile->update(['birth_date' => '2004-03-12']);

        return $document->fresh(['student.userProfile', 'student.course', 'documentType', 'aiAnalysis']);
    }

    private function createGeminiDocument(
        string $documentType,
        string $contents = '%PDF-test-document',
    ): StudentDocument {
        Queue::fake();
        $student = $this->createStudent();
        $type = $this->createDocumentType($documentType);
        $document = StudentDocument::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'availability_status' => 'available',
        ]);
        $slug = Str::slug($documentType);
        $path = "student-documents/{$document->student_id}/{$document->document_type_id}-{$slug}/document.pdf";
        Storage::disk('local')->put($path, $contents);
        $document->update(['file_path' => $path]);

        return $document->fresh(['student.userProfile', 'student.course', 'documentType', 'aiAnalysis']);
    }

    private function configureGemini(string $apiKey = 'test-api-key', string $model = 'gemini-test'): void
    {
        config()->set('services.document_analysis.driver', 'gemini');
        config()->set('services.document_analysis.gemini.api_key', $apiKey);
        config()->set('services.document_analysis.gemini.model', $model);
        config()->set('services.document_analysis.gemini.timeout', 5);
    }

    /** @param list<string> $filenames */
    private function createSyntheticDemoFixtures(array $filenames): string
    {
        $directory = storage_path('framework/testing/demo-documents-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        foreach ($filenames as $filename) {
            $image = UploadedFile::fake()->image($filename, 120, 160);
            File::copy($image->getPathname(), $directory.DIRECTORY_SEPARATOR.$filename);
        }

        return $directory;
    }

    /** @param array<string, mixed> $overrides */
    private function fakeGemini(array $overrides = []): void
    {
        $extraction = array_replace([
            'detected_document_type' => 'Birth Certificate',
            'confidence' => 0.94,
            'student_full_name' => 'Sample Student',
            'date_of_birth' => '2004-03-12',
            'readable' => true,
            'quality' => 'clear',
            'document_type_indicators' => ['Certificate of Live Birth heading'],
            'issues' => [],
        ], $overrides);

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => json_encode($extraction, JSON_THROW_ON_ERROR)]],
                ],
            ]],
        ])]);
    }

    private function createDocumentType(string $name): DocumentType
    {
        return DocumentType::query()->create([
            'document_name' => $name,
            'processing_fee' => 0,
            'processing_days' => 1,
            'requires_appointment' => false,
            'status' => 'active',
        ]);
    }

    private function createStudent(): Student
    {
        $studentUser = $this->createUserWithRole(Role::STUDENT);
        $profile = UserProfile::query()->create([
            'user_id' => $studentUser->id,
            'first_name' => 'Sample',
            'last_name' => 'Student',
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => fake()->unique()->safeEmail(),
        ]);
        DB::table('departments')->insertOrIgnore([
            'id' => 1,
            'department_code' => 'ICS',
            'department_name' => 'Institute of Computer Studies',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $course = Course::query()->firstOrCreate(
            ['course_code' => 'BSIT'],
            [
                'department_id' => 1,
                'course_name' => 'Bachelor of Science in Information Technology',
                'years' => 4,
                'status' => 'active',
            ],
        );
        $curriculum = Curriculum::query()->firstOrCreate(
            ['curriculum_code' => 'BSIT-2026'],
            [
                'course_id' => $course->id,
                'curriculum_name' => 'BSIT Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
            ],
        );

        return Student::query()->create([
            'user_id' => $studentUser->id,
            'user_profile_id' => $profile->id,
            'course_id' => $course->id,
            'curriculum_id' => $curriculum->id,
            'student_number' => fake()->unique()->numerify('26-#####'),
            'admission_date' => '2026-08-01',
            'year_level' => 1,
            'student_status' => 'regular',
        ]);
    }

    private function createUserWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}
