<?php

namespace App\Http\Resources;

use App\Models\StudentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StudentDocument */
class StudentDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->whenLoaded('documentType', fn () => $this->documentType->document_name),
            'document_type_id' => $this->document_type_id,
            'status' => $this->availability_status,
            'availability_status' => $this->availability_status,
            'verification_status' => $this->verification_status,
            'received_at' => $this->submitted_date?->toDateString(),
            'submitted_date' => $this->submitted_date?->toDateString(),
            // The current schema has no verifier audit fields; keep these explicit for the read-only UI.
            'verified_date' => null,
            'verified_by' => null,
            'remarks' => $this->remarks,
        ];
    }
}
