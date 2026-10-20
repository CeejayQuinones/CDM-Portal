<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventModeratorRequest extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'revoked'];

    protected $fillable = [
        'event_id', 'requester_user_id', 'status', 'active_request_key', 'assignment_id',
        'request_reason', 'reviewed_by_user_id', 'review_reason', 'requested_at', 'reviewed_at',
        'revoked_at', 'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EventRoleAssignment::class, 'assignment_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public static function activeKey(int $eventId, int $userId): string
    {
        return $eventId.':'.$userId;
    }
}
