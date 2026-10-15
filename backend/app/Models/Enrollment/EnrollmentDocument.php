<?php

namespace App\Models\Enrollment;

use App\Models\DocumentType;
use App\Models\StudentDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function studentDocument(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class);
    }
}
