<?php

namespace App\Models\Admission;

use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class AdmissionCycle extends Model
{
    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::OPEN, self::CLOSED, self::ARCHIVED];

    protected $fillable = ['code', 'name', 'academic_year_id', 'status', 'opens_at', 'closes_at', 'confirmation_closes_at', 'created_by_user_id', 'updated_by_user_id'];

    protected $attributes = ['status' => self::DRAFT, 'policy_version' => 1];

    protected function casts(): array
    {
        return ['opens_at' => 'immutable_datetime', 'closes_at' => 'immutable_datetime', 'confirmation_closes_at' => 'immutable_datetime', 'exam_policy' => 'array', 'policy_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $cycle): void {
            if (! in_array($cycle->status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Invalid admission cycle status.']);
            }
            if ($cycle->status === self::OPEN && (! $cycle->opens_at || ! $cycle->closes_at || ! $cycle->confirmation_closes_at)) {
                throw ValidationException::withMessages(['opens_at' => 'An open cycle requires all admission dates.']);
            }
            if (($cycle->opens_at && $cycle->closes_at && $cycle->opens_at->gte($cycle->closes_at))
                || ($cycle->closes_at && $cycle->confirmation_closes_at && $cycle->closes_at->gt($cycle->confirmation_closes_at))) {
                throw ValidationException::withMessages(['closes_at' => 'Admission dates must be ordered.']);
            }
            if ($cycle->exists && $cycle->isDirty('code') && $cycle->applicants()->exists()) {
                throw ValidationException::withMessages(['code' => 'A cycle code cannot change after use.']);
            }
        });
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(AdmissionApplicant::class, 'cycle_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
