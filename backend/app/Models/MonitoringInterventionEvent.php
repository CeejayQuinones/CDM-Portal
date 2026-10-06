<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MonitoringInterventionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_uuid',
        'risk_notification_id',
        'actor_user_id',
        'actor_role',
        'student_id',
        'action',
        'risk_level',
        'context_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['context_json' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Monitoring intervention events are immutable.'));
        static::deleting(fn () => throw new LogicException('Monitoring intervention events are immutable.'));
    }
}
