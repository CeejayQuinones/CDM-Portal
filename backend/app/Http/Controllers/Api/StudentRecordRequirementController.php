<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadStudentDocumentRequest;
use App\Http\Resources\StudentDocumentResource;
use App\Models\DocumentType;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Services\StudentRecords\StudentRecordRequirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentRecordRequirementController extends Controller
{
    public function __construct(private readonly StudentRecordRequirementService $requirements) {}

    public function studentUpload(UploadStudentDocumentRequest $request, DocumentType $documentType): JsonResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        $document = $this->requirements->upload($request->user(), $student, $documentType, $request->file('file'));

        return response()->json([
            'success' => true,
            'message' => $document->verification_version > 1 ? 'Replacement submitted for Registrar review.' : 'Document submitted for Registrar review.',
            'data' => $this->requirements->item($documentType, $document),
        ], 201);
    }

    public function studentView(Request $request, StudentDocument $studentDocument): StreamedResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($studentDocument->student_id === $student->id, 404);

        return $this->file($studentDocument, false);
    }

    public function studentDownload(Request $request, StudentDocument $studentDocument): StreamedResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($studentDocument->student_id === $student->id, 404);

        return $this->file($studentDocument, true);
    }

    public function review(Request $request, StudentDocument $studentDocument, string $action): JsonResponse
    {
        $attributes = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => [$action === 'needs-resubmission' ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);
        $document = $this->requirements->review(
            $request->user(),
            $studentDocument,
            $action,
            (int) $attributes['version'],
            $attributes['reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => $action === 'verify' ? 'Digital copy verified.' : 'Document returned for resubmission.',
            'data' => new StudentDocumentResource($document),
        ]);
    }

    public function physical(Request $request, Student $student, DocumentType $documentType, string $action): JsonResponse
    {
        $attributes = $request->validate([
            'instruction' => ['nullable', 'string', 'max:1000'],
            'reason' => [$action === 'reopen' ? 'required' : 'nullable', 'string', 'max:1000'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);
        $document = $this->requirements->physical(
            $request->user(),
            $student,
            $documentType,
            $action,
            $attributes['instruction'] ?? null,
            $attributes['reason'] ?? null,
            $attributes['remark'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => match ($action) {
                'receive' => 'Physical copy marked as received.',
                'reopen' => 'Physical-copy requirement reopened.',
                default => 'Physical copy is now required.',
            },
            'data' => new StudentDocumentResource($document),
        ]);
    }

    private function file(StudentDocument $document, bool $download): StreamedResponse
    {
        $path = $document->file_path;
        abort_unless(
            $path && ! str_contains($path, '..') && ! str_contains($path, '\\')
                && (str_starts_with($path, 'student-documents/'.$document->student_id.'/')
                    || $document->enrollmentSources()->where('file_path', $path)->exists())
                && Storage::disk('local')->exists($path),
            404,
            'No stored document file is available.',
        );
        $document->loadMissing('documentType:id,document_name');
        $filename = (Str::slug($document->documentType?->document_name) ?: 'student-document').'-'.$document->id.'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $download
            ? Storage::disk('local')->download($path, $filename, $headers)
            : Storage::disk('local')->response($path, $filename, $headers);
    }
}
