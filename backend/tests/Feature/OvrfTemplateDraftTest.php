<?php

namespace Tests\Feature;

use App\Models\OvrfTemplateDraft;
use App\Models\Role;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class OvrfTemplateDraftTest extends TestCase
{
    private string $base = '/api/registrar/ovrf-template-drafts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Storage::fake('local');
        $this->withHeader('X-CDM-Client', 'desktop');
    }

    private function signIn(string $role = Role::REGISTRAR_STAFF): User
    {
        $user = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]);
        Sanctum::actingAs($user);
        $this->withHeader('X-CDM-Client', $role === Role::STUDENT ? 'web' : 'desktop');

        return $user;
    }

    private function pdf(int $pages = 1): UploadedFile
    {
        $html = '<html><body>'.str_repeat('<div style="page-break-after: always">Blank OVRF page</div>', $pages - 1).'<p>Blank OVRF</p></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return UploadedFile::fake()->createWithContent('blank.pdf', $pdf->output());
    }

    public function test_only_registrar_can_manage_private_draft_versions(): void
    {
        $this->getJson($this->base)->assertUnauthorized();
        $this->post($this->base, ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->signIn(Role::STUDENT);
        $this->withHeader('X-CDM-Client', 'web')->getJson($this->base)->assertForbidden();
        $this->post($this->base, ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        $this->signIn(Role::ADMIN);
        $this->getJson($this->base)->assertForbidden();
        $this->post($this->base, ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();

        $this->signIn();
        $first = $this->post($this->base, ['file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertDontSee('private_path');
        $second = $this->post($this->base, ['file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertCreated()->assertDontSee('private_path');
        $firstId = $first->json('data.id');
        $secondId = $second->json('data.id');
        $this->assertGreaterThan($firstId, $secondId);
        $this->assertSame($firstId, $first->json('data.version'));
        $this->getJson($this->base)->assertOk()->assertJsonCount(2, 'data.data');
        $this->assertDatabaseCount('ovrf_template_draft_events', 2);

        $firstPath = OvrfTemplateDraft::findOrFail($firstId)->private_path;
        $secondPath = OvrfTemplateDraft::findOrFail($secondId)->private_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertExists([$firstPath, $secondPath]);
        $this->assertStringStartsWith('ovrf-template-drafts/', $firstPath);
        $this->assertStringStartsWith('%PDF-', $this->get($this->base.'/'.$firstId.'/preview')->assertOk()
            ->assertHeader('Content-Security-Policy', 'sandbox')->getContent());
        $this->postJson($this->base.'/'.$firstId.'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson($this->base.'/'.$firstId.'/archive')->assertConflict();
        $this->get($this->base.'/'.$firstId.'/preview')->assertOk();
        Storage::disk('local')->assertExists([$firstPath, $secondPath]);
        $this->assertDatabaseCount('ovrf_template_draft_events', 5);

        $this->signIn(Role::STUDENT);
        $this->withHeader('X-CDM-Client', 'web')->get($this->base.'/'.$firstId.'/preview')->assertForbidden();
        $this->postJson($this->base.'/'.$secondId.'/archive')->assertForbidden();
    }

    public function test_upload_rejects_invalid_and_active_content_without_storing_files(): void
    {
        $this->signIn();
        $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a PDF')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent('blank.pdf', '%PDF-1.4\nnot a complete PDF')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($this->base, ['file' => UploadedFile::fake()->create('oversized.pdf', 5121, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $valid = $this->pdf()->getContent();
        $dangerous = str_replace('%%EOF', '/OpenAction 1 0 R'."\n".'%%EOF', $valid);
        $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent('active.pdf', $dangerous)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('ovrf_template_drafts', 0);
        Storage::disk('local')->assertDirectoryEmpty('ovrf-template-drafts');
    }

    public function test_upload_reports_specific_unsupported_pdf_structures_and_rejects_unsafe_files(): void
    {
        $this->signIn();
        $valid = $this->pdf()->getContent();
        $cases = [
            'unsupported version' => [preg_replace('/\A%PDF-1\.[0-7]/', '%PDF-1.8', $valid), 'supported PDF version'],
            'cross-reference stream' => [str_replace('%%EOF', '/Type /XRef'."\n".'%%EOF', $valid), 'cross-reference streams'],
            'compressed object stream' => [str_replace('%%EOF', '/Type /ObjStm'."\n".'%%EOF', $valid), 'compressed object streams'],
            'escaped PDF name' => [str_replace('%%EOF', '/#4aS'."\n".'%%EOF', $valid), 'escaped names'],
            'encryption' => [str_replace('%%EOF', '/Encrypt 1 0 R'."\n".'%%EOF', $valid), 'Encrypted PDFs'],
            'active content' => [str_replace('%%EOF', '/OpenAction 1 0 R'."\n".'%%EOF', $valid), 'active content'],
            'embedded file' => [str_replace('%%EOF', '/Type /EmbeddedFile'."\n".'%%EOF', $valid), 'embedded file'],
            'content credentials attachment' => [str_replace('%%EOF', '/Type /EmbeddedFile /Subtype /application#2Fc2pa /AFRelationship /C2PA_Manifest'."\n".'%%EOF', $valid), 'Content Credentials (C2PA) manifest'],
            'broken cross-reference' => [preg_replace('/startxref\s+\d+/', 'startxref'."\n".'999999', $valid), 'cross-reference table or trailer'],
        ];

        foreach ($cases as $name => [$bytes, $message]) {
            $this->assertIsString($bytes, $name);
            $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent('blank.pdf', $bytes)], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('file')->assertSee($message);
        }

        $this->assertDatabaseCount('ovrf_template_drafts', 0);
        Storage::disk('local')->assertDirectoryEmpty('ovrf-template-drafts');
    }

    public function test_upload_rejects_incorrect_mime_and_excessive_pages(): void
    {
        $this->signIn();
        $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent('wrong.pdf', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII='))], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($this->base, ['file' => $this->pdf(11)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file')->assertSee('between 1 and 10 readable pages');

        $this->assertDatabaseCount('ovrf_template_drafts', 0);
        Storage::disk('local')->assertDirectoryEmpty('ovrf-template-drafts');
    }

    public function test_stored_draft_change_fails_closed_and_official_generator_is_not_replaced(): void
    {
        $this->signIn();
        $id = $this->post($this->base, ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $draft = OvrfTemplateDraft::findOrFail($id);
        Storage::disk('local')->put($draft->private_path, 'changed content');
        $this->get($this->base.'/'.$id.'/preview')->assertNotFound();
        $this->assertDatabaseCount('ovrf_template_draft_events', 1);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(404, $this->get('/api/enrollment/academic/records/999999/ovrf')->getStatusCode());
    }

    public function test_draft_upload_does_not_change_existing_finalized_ovrf_generation(): void
    {
        $fixture = EnrollmentAcademicFixture::create('ovrf-template-draft');
        Sanctum::actingAs($fixture['registrar']);
        $application = $fixture['application']->fresh();
        $path = '/api/enrollment/academic/applications/'.$application->id;

        $this->postJson($path.'/subjects', ['version' => $application->version, 'subject_ids' => []])->assertOk();
        $application->refresh();
        $this->postJson($path.'/assign', ['version' => $application->version, 'section_id' => $fixture['section']->id])->assertOk();
        $application->refresh();
        $enrollmentId = $this->postJson($path.'/finalize', ['version' => $application->version, 'confirmed' => true])
            ->assertOk()->json('data.enrollment_id');

        $endpoint = '/api/enrollment/academic/records/'.$enrollmentId.'/ovrf';
        $before = $this->get($endpoint)->assertOk()->assertHeader('X-OVRF-Validation-Status', 'physical-verification-required');
        $this->post($this->base, ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $after = $this->get($endpoint)->assertOk()->assertHeader('X-OVRF-Validation-Status', 'physical-verification-required');

        $this->assertStringStartsWith('%PDF-', $before->getContent());
        $this->assertStringStartsWith('%PDF-', $after->getContent());
        $this->assertSame($before->headers->get('Content-Disposition'), $after->headers->get('Content-Disposition'));
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('ovrf_template_drafts', 1);
    }
}
