<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocumentSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function studentDocument(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class);
    }
}
