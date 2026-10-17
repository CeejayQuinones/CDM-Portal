<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadStudentDocumentRequest;
use App\Http\Resources\StudentDocumentResource;
use App\Jobs\AnalyzeStudentDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentDocumentAiAnalysis;
use App\Models\StudentDocumentSubmission;
use App\Services\StudentRecords\StudentRecordAuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistrarStudentDocumentController extends Controller
{
    private const DISK = 'local';

    public function __construct(private readonly StudentRecordAuditWriter $audit) {}

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
            DB::transaction(function () use ($request, $studentDocument, $newPath, $oldPath): void {
                $studentDocument = StudentDocument::query()->lockForUpdate()->findOrFail($studentDocument->id);
                $this->unlinkEnrollmentSources($studentDocument, $oldPath);
                $version = $oldPath ? $studentDocument->verification_version + 1 : max(1, $studentDocument->verification_version);
                if ($oldPath) {
                    StudentDocumentSubmission::query()->firstOrCreate(
                        ['student_document_id' => $studentDocument->id, 'version' => $studentDocument->verification_version],
                        [
                            'file_path' => $oldPath,
                            'submitted_by_user_id' => null,
                            'submitted_at' => $studentDocument->submitted_date ?? $studentDocument->created_at ?? now(),
                            'verification_status' => $studentDocument->verification_status,
                            'reviewed_at' => $studentDocument->verification_status === 'pending' ? null : $studentDocument->updated_at,
                            'review_remark' => $studentDocument->remarks,
                        ],
                    );
                }
                $studentDocument->update([
                    'file_path' => $newPath,
                    'availability_status' => 'available',
                    'verification_status' => 'pending',
                    'verification_version' => $version,
                    'submitted_date' => today(),
                    'verified_at' => null,
                    'verified_by_user_id' => null,
                ]);
                StudentDocumentSubmission::query()->create([
                    'student_document_id' => $studentDocument->id,
                    'version' => $version,
                    'file_path' => $newPath,
                    'submitted_by_user_id' => $request->user()->id,
                    'submitted_at' => now(),
                    'verification_status' => 'pending',
                ]);
                $this->audit->record(
                    $request->user(),
                    $studentDocument->student_id,
                    $oldPath ? 'document_file_replaced' : 'document_file_uploaded',
                    ['document_type_id' => $studentDocument->document_type_id],
                );
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($newPath);

            throw $exception;
        }

        $studentDocument->refresh()->load(['documentType', 'aiAnalysis', 'verifiedBy.profile']);

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

    public function destroy(Request $request, StudentDocument $studentDocument): JsonResponse
    {
        $oldPath = $studentDocument->file_path;

        abort_unless($oldPath && $this->isManagedPath($studentDocument, $oldPath), 404, 'No stored document file is available.');

        DB::transaction(function () use ($request, $studentDocument): void {
            $this->unlinkEnrollmentSources($studentDocument, $studentDocument->file_path);
            $studentDocument->update([
                'file_path' => null,
                'availability_status' => 'missing',
                'verification_status' => 'pending',
                'submitted_date' => null,
            ]);
            $this->audit->record($request->user(), $studentDocument->student_id, 'document_file_deleted', [
                'document_type_id' => $studentDocument->document_type_id,
            ]);
        });

        if ($this->isOwnedStudentDocumentPath($studentDocument, $oldPath)) {
            Storage::disk(self::DISK)->delete($oldPath);
        }
        $studentDocument->load(['documentType', 'aiAnalysis', 'verifiedBy.profile']);

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
        if ($this->isOwnedStudentDocumentPath($studentDocument, $path)) {
            return true;
        }

        $source = $studentDocument->enrollmentSources()
            ->where('file_path', $path)
            ->first(['id', 'application_id', 'file_path']);

        return $source
            && str_starts_with($path, 'enrollment/'.$source->application_id.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }

    private function isOwnedStudentDocumentPath(StudentDocument $studentDocument, string $path): bool
    {
        return str_starts_with($path, 'student-documents/'.$studentDocument->student_id.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }

    private function unlinkEnrollmentSources(StudentDocument $studentDocument, ?string $path): void
    {
        if (! $path || $this->isOwnedStudentDocumentPath($studentDocument, $path)) {
            return;
        }

        $studentDocument->enrollmentSources()
            ->where('file_path', $path)
            ->update(['student_document_id' => null]);
    }
}
