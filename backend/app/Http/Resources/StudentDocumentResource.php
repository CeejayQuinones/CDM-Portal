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
        $analysisType = $this->aiAnalysisType();

        return [
            'id' => $this->id,
            'name' => $this->whenLoaded('documentType', fn () => $this->documentType->document_name),
            'document_type_id' => $this->document_type_id,
            'has_file' => filled($this->file_path),
            'is_ai_analysis_eligible' => $this->supportsAiAnalysis(),
            'ai_analysis_type' => $analysisType ? [
                'key' => $analysisType['key'],
                'label' => $analysisType['label'],
            ] : null,
            'status' => $this->availability_status,
            'availability_status' => $this->availability_status,
            'verification_status' => $this->verification_status,
            'received_at' => $this->submitted_date?->toDateString(),
            'submitted_date' => $this->submitted_date?->toDateString(),
            // The current schema has no verifier audit fields; keep these explicit for the read-only UI.
            'verified_date' => null,
            'verified_by' => null,
            'remarks' => $this->remarks,
            'ai_analysis' => $this->whenLoaded('aiAnalysis', fn () => $this->file_path && $this->aiAnalysis ? [
                'status' => $this->aiAnalysis->status,
                'detected_document_type' => $this->aiAnalysis->detected_document_type,
                'confidence' => $this->aiAnalysis->confidence,
                'checks' => $this->aiAnalysis->checks,
                'issues' => $this->aiAnalysis->issues,
                'recommendation' => $this->aiAnalysis->recommendation,
                'provider' => $this->aiAnalysis->provider,
                'model' => $this->aiAnalysis->model,
                'is_mock' => $this->aiAnalysis->provider === 'mock',
                'analyzed_at' => $this->aiAnalysis->analyzed_at?->toISOString(),
            ] : null),
        ];
    }
}
