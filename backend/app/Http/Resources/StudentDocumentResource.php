<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentDocument */
class StudentDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->whenLoaded('documentType', fn () => $this->documentType->document_name),
            'status' => $this->verification_status,
            'submitted_date' => $this->submitted_date?->toDateString(),
            // The current schema has no verifier audit fields; keep these explicit for the read-only UI.
            'verified_date' => null,
            'verified_by' => null,
            'remarks' => $this->remarks,
        ];
    }
}
