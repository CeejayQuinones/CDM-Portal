<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_uuid', 'event_id', 'actor_user_id', 'actor_role', 'action', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Event audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Event audit records are immutable.'));
    }
}
