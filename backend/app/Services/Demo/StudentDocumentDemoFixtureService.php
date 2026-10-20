<?php

namespace App\Services\Demo;

use App\Models\DocumentType;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class StudentDocumentDemoFixtureService
{
    /**
     * These files are local development inputs and are intentionally excluded from Git.
     *
     * @var array<string, array{filename: string, document_type: string, description: string}>
     */
    public const SAMPLES = [
        'psa' => [
            'filename' => 'psa-sample.jpg',
            'document_type' => 'Birth Certificate',
            'description' => 'PSA Certificate of Live Birth sample',
        ],
        'certificate-of-enrollment' => [
            'filename' => 'certificate-of-enrollment-sample.jpg',
            'document_type' => 'Certificate of Enrollment',
            'description' => 'Enrollment slip sample',
        ],
        'registration-form' => [
            'filename' => 'registration-form-sample.jpg',
            'document_type' => 'Registration Form',
            'description' => 'Official registration form sample',
        ],
        'school-certificate' => [
            'filename' => 'school-certificate-sample.jpg',
            'document_type' => 'Certificate of Enrollment',
            'description' => 'Bona-fide enrollment certification sample',
        ],
        'form-137' => [
            'filename' => 'form-137-sample.webp',
            'document_type' => 'Form 137',
            'description' => 'Learner permanent record sample',
        ],
        'good-moral' => [
            'filename' => 'good-moral-sample.png',
            'document_type' => 'Good Moral Certificate',
            'description' => 'Certificate of good moral character sample',
        ],
    ];

    public function __construct(private readonly ?string $fixtureDirectory = null) {}

    /**
     * @return array{document: StudentDocument, sample: array{filename: string, document_type: string, description: string}, expected_document_type: string}
     */
    public function install(
        Student $student,
        string $sampleKey,
        ?string $documentTypeOverride = null,
        bool $replace = false,
    ): array {
        if (! app()->environment('local')) {
            throw new LogicException('Demo student document fixtures are available in the local environment only.');
        }

        $sample = self::SAMPLES[$sampleKey] ?? null;

        if (! $sample) {
            throw new RuntimeException(sprintf(
                'Unknown sample "%s". Available samples: %s.',
                $sampleKey,
                implode(', ', array_keys(self::SAMPLES)),
            ));
        }

        $source = $this->sourcePath($sample['filename']);

        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException(sprintf(
                'The local fixture "%s" is missing. See docs/development-document-fixtures.md.',
                $sample['filename'],
            ));
        }

        $expectedDocumentType = trim($documentTypeOverride ?: $sample['document_type']);
        $documentType = DocumentType::query()
            ->get(['id', 'document_name'])
            ->first(fn (DocumentType $type): bool => strcasecmp(
                trim($type->document_name),
                $expectedDocumentType,
            ) === 0);

        if (! $documentType) {
            throw new RuntimeException(sprintf(
                'Document type "%s" does not exist.',
                $expectedDocumentType,
            ));
        }

        $document = StudentDocument::query()->firstOrNew([
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
        ]);
        $oldPath = $document->file_path;

        if ($oldPath && ! $replace) {
            throw new RuntimeException(sprintf(
                '%s already has a stored file. Re-run with --replace to overwrite it locally.',
                $expectedDocumentType,
            ));
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $target = sprintf(
            'student-documents/%d/%d-%s/demo-%s-%s.%s',
            $student->id,
            $documentType->id,
            Str::slug($documentType->document_name),
            Str::slug($sampleKey),
            Str::uuid(),
            $extension,
        );
        $stream = fopen($source, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The local demo fixture could not be opened.');
        }

        try {
            if (! Storage::disk('local')->put($target, $stream)) {
                throw new RuntimeException('The local demo fixture could not be copied to private storage.');
            }
        } finally {
            fclose($stream);
        }

        try {
            DB::transaction(function () use ($document, $sample, $expectedDocumentType, $target): void {
                $document->fill([
                    'file_path' => $target,
                    'availability_status' => 'available',
                    'verification_status' => 'pending',
                    'submitted_date' => today(),
                    'remarks' => sprintf(
                        'LOCAL DEMO ONLY: %s associated with %s. Not an authenticated student record.',
                        $sample['description'],
                        $expectedDocumentType,
                    ),
                ])->save();
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($target);

            throw $exception;
        }

        if ($oldPath && $oldPath !== $target && $this->isManagedPath($student, $oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }

        return [
            'document' => $document->fresh(['documentType', 'aiAnalysis']),
            'sample' => $sample,
            'expected_document_type' => $expectedDocumentType,
        ];
    }

    private function sourcePath(string $filename): string
    {
        return rtrim($this->fixtureDirectory ?: storage_path('app/test-documents'), '\\/')
            .DIRECTORY_SEPARATOR.$filename;
    }

    private function isManagedPath(Student $student, string $path): bool
    {
        return str_starts_with($path, 'student-documents/'.$student->id.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
