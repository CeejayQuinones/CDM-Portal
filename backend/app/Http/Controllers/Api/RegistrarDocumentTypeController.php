<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentTypeRequest;
use App\Http\Requests\UpdateDocumentTypeRequest;
use App\Models\DocumentType;
use App\Models\Enrollment\EnrollmentDocument;
use App\Models\Enrollment\EnrollmentPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RegistrarDocumentTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->ok(DocumentType::query()->orderBy('document_name')->get([
            'id', 'document_name', 'description', 'workflow_category', 'processing_fee', 'processing_days',
            'requires_appointment', 'requires_physical_retrieval', 'student_requestable', 'student_uploadable', 'status', 'created_at', 'updated_at',
        ]), 'Document types retrieved successfully.');
    }

    public function store(StoreDocumentTypeRequest $request): JsonResponse
    {
        $documentType = DocumentType::create([
            ...$request->validated(),
            'processing_fee' => $request->input('processing_fee', 0),
            'processing_days' => $request->input('processing_days', 1),
            'status' => $request->input('status', 'active'),
        ]);

        return $this->ok($documentType, 'Document type created successfully.', 201);
    }

    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType): JsonResponse
    {
        $attributes = $request->validated();
        if (($attributes['workflow_category'] ?? null) === 'official_issuance') {
            $attributes['student_uploadable'] = false;
        }
        $documentType->update($attributes);

        return $this->ok($documentType->fresh(), 'Document type updated successfully.');
    }

    public function destroy(DocumentType $documentType): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($documentType): array {
                $locked = DocumentType::query()->lockForUpdate()->findOrFail($documentType->id);
                $usedByEnrollmentConfiguration = EnrollmentPeriod::query()
                    ->get(['document_requirements'])
                    ->contains(fn (EnrollmentPeriod $period): bool => collect($period->document_requirements ?? [])
                        ->flatten()
                        ->contains(fn (mixed $id): bool => (int) $id === $locked->id));
                $inUse = $locked->documentRequests()->exists()
                    || $locked->studentDocuments()->exists()
                    || EnrollmentDocument::query()->where('document_type_id', $locked->id)->exists()
                    || $usedByEnrollmentConfiguration;

                abort_if(
                    $inUse,
                    409,
                    'This document type is already used by student or Enrollment records. Disable it instead so historical records remain intact.',
                );

                $snapshot = $locked->only([
                    'document_name', 'description', 'workflow_category', 'processing_fee', 'processing_days',
                    'requires_appointment', 'requires_physical_retrieval', 'student_requestable', 'student_uploadable', 'status',
                ]);
                $locked->delete();

                return $snapshot;
            });
        } catch (QueryException) {
            return response()->json([
                'success' => false,
                'message' => 'This document type became linked to another record and cannot be deleted. Disable it instead.',
            ], 409);
        }

        return $this->ok($deleted, 'Document type deleted successfully.');
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
