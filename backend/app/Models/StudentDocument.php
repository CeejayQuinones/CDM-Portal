<?php

namespace App\Models;

use App\Models\Enrollment\EnrollmentDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentDocument extends Model
{
    protected $fillable = [
        'student_id', 'document_type_id', 'document_storage_location_id', 'file_path',
        'verification_status', 'verification_version', 'availability_status', 'remarks', 'submitted_date',
        'verified_at', 'verified_by_user_id', 'physical_copy_status', 'physical_copy_required_at',
        'physical_copy_received_at', 'physical_copy_required_by_user_id',
        'physical_copy_received_by_user_id', 'physical_copy_instruction',
    ];

    protected function casts(): array
    {
        return [
            'submitted_date' => 'date',
            'verification_version' => 'integer',
            'verified_at' => 'immutable_datetime',
            'physical_copy_required_at' => 'immutable_datetime',
            'physical_copy_received_at' => 'immutable_datetime',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function physicalCopyRequiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physical_copy_required_by_user_id');
    }

    public function physicalCopyReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physical_copy_received_by_user_id');
    }

    public function enrollmentSources(): HasMany
    {
        return $this->hasMany(EnrollmentDocument::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(StudentDocumentSubmission::class)->orderByDesc('version');
    }

}
