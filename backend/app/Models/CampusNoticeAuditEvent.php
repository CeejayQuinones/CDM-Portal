<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CampusNoticeAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['campus_notice_id', 'actor_user_id', 'action', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campus notice audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Campus notice audit events are append-only.'));
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(CampusNotice::class, 'campus_notice_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
