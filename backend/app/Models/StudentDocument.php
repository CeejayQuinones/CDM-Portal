<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocument extends Model
{
    protected $fillable = ['student_id', 'document_type_id', 'document_storage_location_id', 'file_path', 'verification_status', 'remarks', 'submitted_date'];

    protected function casts(): array
    {
        return ['submitted_date' => 'date'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
