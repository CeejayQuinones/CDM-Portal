<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventAttendanceSession extends Model
{
    protected $fillable = ['event_id', 'opened_by', 'opened_at', 'closed_at', 'status', 'qr_token_fingerprint', 'qr_expires_at', 'token_version', 'version'];

    protected $attributes = ['status' => 'open', 'token_version' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'qr_expires_at' => 'datetime', 'token_version' => 'integer', 'version' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EventAttendance::class, 'attendance_session_id');
    }
}
