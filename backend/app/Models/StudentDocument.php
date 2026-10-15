<?php

namespace App\Models;

use App\Services\Ai\DocumentAnalysisTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentDocument extends Model
{
    protected $fillable = ['student_id', 'document_type_id', 'document_storage_location_id', 'file_path', 'verification_status', 'availability_status', 'remarks', 'submitted_date'];

    protected function casts(): array
    {
        return ['submitted_date' => 'date'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function aiAnalysis(): HasOne
    {
        return $this->hasOne(StudentDocumentAiAnalysis::class);
    }

    public function supportsAiAnalysis(): bool
    {
        $this->loadMissing('documentType:id,document_name');

        return DocumentAnalysisTypeRegistry::supports($this->documentType?->document_name);
    }

    /** @return array<string, mixed>|null */
    public function aiAnalysisType(): ?array
    {
        $this->loadMissing('documentType:id,document_name');

        return DocumentAnalysisTypeRegistry::resolve($this->documentType?->document_name);
    }

    public function resetAiAnalysisToPending(): StudentDocumentAiAnalysis
    {
        return $this->aiAnalysis()->updateOrCreate([], [
            'status' => StudentDocumentAiAnalysis::STATUS_PENDING,
            'detected_document_type' => null,
            'confidence' => null,
            'extracted_data' => null,
            'checks' => null,
            'issues' => null,
            'recommendation' => null,
            'provider' => null,
            'model' => null,
            'analyzed_at' => null,
            'error_message' => null,
        ]);
    }
}
