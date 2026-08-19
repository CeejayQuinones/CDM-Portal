<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = ['document_name', 'description', 'processing_fee', 'processing_days', 'requires_appointment', 'status'];

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }
}
