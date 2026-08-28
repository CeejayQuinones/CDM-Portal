<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentTypeRequest;
use App\Http\Requests\UpdateDocumentTypeRequest;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;

class RegistrarDocumentTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->ok(DocumentType::query()->orderBy('document_name')->get(['id', 'document_name', 'description', 'processing_fee', 'processing_days', 'requires_appointment', 'status', 'created_at', 'updated_at']), 'Document types retrieved successfully.');
    }

    public function store(StoreDocumentTypeRequest $request): JsonResponse
    {
        $documentType = DocumentType::create([
            ...$request->validated(),
            'processing_fee' => $request->input('processing_fee', 0),
            'processing_days' => $request->input('processing_days', 1),
            'status' => 'active',
        ]);

        return $this->ok($documentType, 'Document type created successfully.', 201);
    }

    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType): JsonResponse
    {
        $documentType->update($request->validated());

        return $this->ok($documentType->fresh(), 'Document type updated successfully.');
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
