<?php

namespace App\Models\Admission;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdmissionAuditEvent extends Model
{
    public const IDENTITY_CREATED = 'admission.identity_created';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Admission audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Admission audit events are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplicant::class, 'applicant_id');
    }
}
