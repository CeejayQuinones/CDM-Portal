<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendance extends Model
{
    public const STATUSES = ['present', 'late', 'excused', 'absent'];

    protected $fillable = ['event_id', 'attendance_session_id', 'student_id', 'status', 'checked_in_at', 'checked_out_at', 'source', 'recorded_by', 'remarks', 'version'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'version' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventAttendanceSession::class, 'attendance_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
