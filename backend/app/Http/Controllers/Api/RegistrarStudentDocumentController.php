<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadStudentDocumentRequest;
use App\Http\Resources\StudentDocumentResource;
use App\Jobs\AnalyzeStudentDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentDocumentAiAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistrarStudentDocumentController extends Controller
{
    private const DISK = 'local';

    public function analyzeAll(Student $student): JsonResponse
    {
        $summary = [
            'queued' => 0,
            'skipped_missing' => 0,
            'skipped_unsupported' => 0,
            'skipped_processing' => 0,
            'skipped_completed' => 0,
        ];

        DB::transaction(function () use ($student, &$summary): void {
            $documents = StudentDocument::query()
                ->where('student_id', $student->id)
                ->with(['documentType:id,document_name', 'aiAnalysis'])
                ->lockForUpdate()
                ->get();

            foreach ($documents as $document) {
                if (! filled($document->file_path)) {
                    $summary['skipped_missing']++;

                    continue;
                }

                if (! $document->supportsAiAnalysis()) {
                    $summary['skipped_unsupported']++;

                    continue;
                }

                $status = $document->aiAnalysis?->status;

                if (in_array($status, [
                    StudentDocumentAiAnalysis::STATUS_PENDING,
                    StudentDocumentAiAnalysis::STATUS_PROCESSING,
                ], true)) {
                    $summary['skipped_processing']++;

                    continue;
                }

                if ($status === StudentDocumentAiAnalysis::STATUS_COMPLETED) {
                    $summary['skipped_completed']++;

                    continue;
                }

                $analysis = $document->resetAiAnalysisToPending();

                AnalyzeStudentDocument::dispatch($analysis->id, $document->file_path)->afterCommit();
                $summary['queued']++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => sprintf('%d document%s queued for AI analysis.', $summary['queued'], $summary['queued'] === 1 ? '' : 's'),
            'data' => $summary,
        ]);
    }

    public function upload(
        UploadStudentDocumentRequest $request,
        StudentDocument $studentDocument,
    ): JsonResponse {
        $studentDocument->loadMissing('documentType:id,document_name');
        $file = $request->file('file');
        $newPath = $this->store($studentDocument, $file);
        $oldPath = $studentDocument->file_path;

        try {
            DB::transaction(function () use ($studentDocument, $newPath): void {
                $studentDocument->update([
                    'file_path' => $newPath,
                    'availability_status' => 'available',
                    'verification_status' => 'pending',
                    'submitted_date' => today(),
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($newPath);

            throw $exception;
        }

        if ($oldPath && $oldPath !== $newPath && $this->isManagedPath($studentDocument, $oldPath)) {
            Storage::disk(self::DISK)->delete($oldPath);
        }

        $studentDocument->load(['documentType', 'aiAnalysis']);

        return response()->json([
            'success' => true,
            'message' => $oldPath ? 'Student document replaced successfully.' : 'Student document uploaded successfully.',
            'data' => new StudentDocumentResource($studentDocument),
        ]);
    }

    public function view(StudentDocument $studentDocument): StreamedResponse
    {
        [$path, $filename] = $this->storedFile($studentDocument);

        return Storage::disk(self::DISK)->response($path, $filename, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(StudentDocument $studentDocument): StreamedResponse
    {
        [$path, $filename] = $this->storedFile($studentDocument);

        return Storage::disk(self::DISK)->download($path, $filename, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(StudentDocument $studentDocument): JsonResponse
    {
        $oldPath = $studentDocument->file_path;

        abort_unless($oldPath && $this->isManagedPath($studentDocument, $oldPath), 404, 'No stored document file is available.');

        DB::transaction(function () use ($studentDocument): void {
            $studentDocument->update([
                'file_path' => null,
                'availability_status' => 'missing',
                'verification_status' => 'pending',
                'submitted_date' => null,
            ]);
        });

        Storage::disk(self::DISK)->delete($oldPath);
        $studentDocument->load(['documentType', 'aiAnalysis']);

        return response()->json([
            'success' => true,
            'message' => 'Student document file deleted successfully.',
            'data' => new StudentDocumentResource($studentDocument),
        ]);
    }

    private function store(StudentDocument $studentDocument, UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => throw new RuntimeException('The validated document type could not be stored.'),
        };
        $type = Str::slug((string) $studentDocument->documentType?->document_name) ?: 'document';
        $directory = sprintf(
            'student-documents/%d/%d-%s',
            $studentDocument->student_id,
            $studentDocument->document_type_id,
            $type,
        );
        $path = $file->storeAs($directory, Str::uuid().'.'.$extension, self::DISK);

        if (! is_string($path)) {
            throw new RuntimeException('The document could not be stored.');
        }

        return $path;
    }

    /** @return array{string, string} */
    private function storedFile(StudentDocument $studentDocument): array
    {
        $path = $studentDocument->file_path;

        abort_unless(
            $path
                && $this->isManagedPath($studentDocument, $path)
                && Storage::disk(self::DISK)->exists($path),
            404,
            'No stored document file is available.',
        );

        $studentDocument->loadMissing('documentType:id,document_name');
        $name = Str::slug((string) $studentDocument->documentType?->document_name) ?: 'student-document';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return [$path, $name.'-'.$studentDocument->id.'.'.$extension];
    }

    private function isManagedPath(StudentDocument $studentDocument, string $path): bool
    {
        return str_starts_with($path, 'student-documents/'.$studentDocument->student_id.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
