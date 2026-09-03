<?php

use App\Models\Student;
use App\Services\Demo\StudentDocumentDemoFixtureService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app()->environment('local')) {
    Artisan::command(
        'demo:student-documents
            {studentId : Student database ID}
            {sample=psa : psa, certificate-of-enrollment, registration-form, school-certificate, form-137, or good-moral}
            {--document-type= : Explicit target document type for mismatch testing}
            {--replace : Replace an existing stored file for that document type}',
        function (): int {
            $student = Student::query()->find($this->argument('studentId'));

            if (! $student) {
                $this->error('Student not found.');

                return Command::FAILURE;
            }

            try {
                $result = app(StudentDocumentDemoFixtureService::class)->install(
                    $student,
                    (string) $this->argument('sample'),
                    $this->option('document-type') ?: null,
                    (bool) $this->option('replace'),
                );
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return Command::FAILURE;
            }

            $document = $result['document'];
            $this->info('Local demo document installed in private storage.');
            $this->line('Sample: '.$result['sample']['description']);
            $this->line('Student document type: '.$result['expected_document_type']);
            $this->line('AI supported now: '.($document->supportsAiAnalysis() ? 'yes' : 'no'));
            $this->warn('Demo fixture only. This is not an authenticated student record.');

            return Command::SUCCESS;
        },
    )->purpose('Install a local-only sample file through the Student Documents observer workflow');
}
