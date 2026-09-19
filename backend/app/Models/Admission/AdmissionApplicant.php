<?php

namespace App\Models\Admission;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdmissionApplicant extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const EXPIRED = 'expired';

    public const CONVERTED = 'converted';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW, self::ACCEPTED, self::REJECTED, self::WITHDRAWN, self::EXPIRED, self::CONVERTED];

    public const ACTIVE_STATUSES = [self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW, self::ACCEPTED];

    // Creation is owned by AdmissionIdentityService, never a request payload.
    protected $guarded = ['*'];

    protected $attributes = ['status' => self::DRAFT, 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot_version' => 'integer', 'profile_snapshot' => 'array',
            'contact_verified_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime', 'expired_at' => 'immutable_datetime', 'converted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $applicant): void {
            $applicant->applicant_number = 'APP-'.Str::uuid();
        });
        static::saving(function (self $applicant): void {
            if (! in_array($applicant->status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Invalid admission application status.']);
            }
            if ($applicant->exists && $applicant->isDirty(['user_id', 'cycle_id', 'applicant_number'])) {
                throw ValidationException::withMessages(['applicant_number' => 'Application ownership and number cannot change.']);
            }
            if (($applicant->source_system === null) !== ($applicant->source_applicant_id === null)) {
                throw ValidationException::withMessages(['source_system' => 'Both provenance fields must be supplied together.']);
            }
            // Reserve the nullable link without implementing a conversion workflow.
            $converted = $applicant->status === self::CONVERTED;
            if (($applicant->converted_student_id !== null) !== $converted || ($applicant->converted_at !== null) !== $converted) {
                throw ValidationException::withMessages(['converted_student_id' => 'Converted status, Student linkage and timestamp must agree.']);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'cycle_id');
    }
}
