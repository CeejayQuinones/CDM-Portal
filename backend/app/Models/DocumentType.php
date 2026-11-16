<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = [
        'document_name', 'description', 'workflow_category', 'processing_fee', 'processing_days',
        'requires_appointment', 'requires_physical_retrieval', 'student_requestable', 'student_uploadable', 'registrar_window', 'status',
    ];

    protected function casts(): array
    {
        return [
            'processing_fee' => 'decimal:2',
            'processing_days' => 'integer',
            'requires_appointment' => 'boolean',
            'requires_physical_retrieval' => 'boolean',
            'student_requestable' => 'boolean',
            'student_uploadable' => 'boolean',
        ];
    }

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }
}
