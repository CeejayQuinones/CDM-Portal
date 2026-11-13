<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestBatch;
use App\Models\DocumentType;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentRequestBatchService
{
    public function __construct(
        private readonly PhysicalRetrievalService $retrieval,
        private readonly DocumentRequestFeeService $fees,
    ) {}

    /** @param array<int, array<string, mixed>> $items */
    public function submit(Student $student, string $key, array $items): array
    {
        usort($items, fn (array $left, array $right): int => $left['document_type_id'] <=> $right['document_type_id']);
        $hash = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($student, $key, $items, $hash): array {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            $existing = DocumentRequestBatch::query()
                ->where('student_id', $student->id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for different documents.']);
                }

                return ['batch' => $existing->load('documentRequests.documentType:id,document_name,requires_appointment,processing_days,processing_fee'), 'created' => false];
            }

            $types = DocumentType::query()->whereIn('id', array_column($items, 'document_type_id'))
                ->where('status', 'active')->where('student_requestable', true)->get()->keyBy('id');
            foreach ($items as $index => $item) {
                if (! $types->has($item['document_type_id'])) {
                    throw ValidationException::withMessages(["items.{$index}.document_type_id" => 'This document is not available for student requests.']);
                }
            }

            $requestDate = $this->fees->requestDate();
            $batch = DocumentRequestBatch::query()->create([
                'student_id' => $student->id,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
            ]);

            foreach ($items as $item) {
                $type = $types[$item['document_type_id']];
                $documentRequest = DocumentRequest::query()->create([
                    'student_id' => $student->id,
                    'document_request_batch_id' => $batch->id,
                    'document_type_id' => $type->id,
                    'quantity' => $item['quantity'],
                    'total_fee' => $this->fees->hasRequestedToday($student->id, $type->id, $requestDate)
                        ? $type->processing_fee * $item['quantity']
                        : 0,
                    'purpose' => $item['purpose_category'],
                    'purpose_details' => $item['purpose_details'] ?? null,
                    'status' => 'pending',
                    'request_date' => $requestDate,
                ]);
                $documentRequest->setRelation('documentType', $type);
                $this->retrieval->attachNewRequest($documentRequest);
            }

            return ['batch' => $batch->load('documentRequests.documentType:id,document_name,requires_appointment,processing_days,processing_fee'), 'created' => true];
        }, 3);
    }
}
