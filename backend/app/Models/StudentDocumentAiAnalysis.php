<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocumentAiAnalysis extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'student_document_id',
        'status',
        'detected_document_type',
        'confidence',
        'extracted_data',
        'checks',
        'issues',
        'recommendation',
        'provider',
        'model',
        'analyzed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'extracted_data' => 'array',
            'checks' => 'array',
            'issues' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function studentDocument(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class);
    }
}
