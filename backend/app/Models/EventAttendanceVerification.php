<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendanceVerification extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public const ACTIONS = ['check_in', 'check_out'];

    protected $fillable = [
        'event_id', 'attendance_session_id', 'student_id', 'action', 'status',
        'active_request_key', 'token_fingerprint', 'requested_at',
        'reviewed_by_user_id', 'reviewed_at', 'review_reason',
    ];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public static function activeKey(int $sessionId, int $studentId, string $action): string
    {
        return $sessionId.':'.$studentId.':'.$action;
    }
}
