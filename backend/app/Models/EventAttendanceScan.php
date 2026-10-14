<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAttendanceScan extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_id', 'attendance_session_id', 'student_id', 'scanned_at', 'result', 'token_fingerprint', 'client_platform', 'metadata'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Event attendance scan records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Event attendance scan records are immutable.'));
    }
}
