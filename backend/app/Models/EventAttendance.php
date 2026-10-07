<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendance extends Model
{
    public const STATUSES = ['present', 'late', 'excused', 'absent'];

    protected $fillable = ['event_id', 'attendance_session_id', 'student_id', 'course_id_at_attendance', 'year_level_at_attendance', 'section_id_at_attendance', 'status', 'checked_in_at', 'checked_out_at', 'source', 'recorded_by', 'remarks', 'version'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'year_level_at_attendance' => 'integer', 'version' => 'integer'];
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

    public function courseAtAttendance(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id_at_attendance');
    }

    public function sectionAtAttendance(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id_at_attendance');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
