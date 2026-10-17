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
            'file_kind' => $this->fileKind(),
            'is_ai_analysis_eligible' => $this->supportsAiAnalysis(),
            'ai_analysis_type' => $analysisType ? [
                'key' => $analysisType['key'],
                'label' => $analysisType['label'],
            ] : null,
            'status' => $this->availability_status,
            'availability_status' => $this->availability_status,
            'verification_status' => $this->verification_status,
            'digital_status' => ! $this->file_path || $this->availability_status === 'missing'
                ? 'missing'
                : match ($this->verification_status) {
                    'verified' => 'verified',
                    'rejected' => 'needs_resubmission',
                    default => 'under_review',
                },
            'version' => $this->verification_version,
            'received_at' => $this->submitted_date?->toDateString(),
            'submitted_date' => $this->submitted_date?->toDateString(),
            'verified_date' => $this->verified_at?->toISOString(),
            'verified_at' => $this->verified_at?->toISOString(),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy
                ? (trim(($this->verifiedBy->profile?->first_name ?? '').' '.($this->verifiedBy->profile?->last_name ?? '')) ?: $this->verifiedBy->username)
                : null),
            'remarks' => $this->remarks,
            'physical_status' => $this->physical_copy_status,
            'physical_copy_status' => $this->physical_copy_status,
            'physical_copy_required_at' => $this->physical_copy_required_at?->toISOString(),
            'physical_copy_received_at' => $this->physical_copy_received_at?->toISOString(),
            'physical_copy_instruction' => $this->physical_copy_instruction,
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

    private function fileKind(): ?string
    {
        if (! filled($this->file_path)) {
            return null;
        }

        return match (strtolower(pathinfo($this->file_path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg', 'png', 'webp' => 'image',
            'pdf' => 'pdf',
            default => 'file',
        };
    }
}
