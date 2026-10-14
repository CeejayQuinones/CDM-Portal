<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRoleAssignment extends Model
{
    public const SEMI_COORDINATOR = 'semi_coordinator';

    public const REGISTERED_MODERATOR = 'registered_moderator';

    public const REQUESTED_MODERATOR = 'requested_moderator';

    /** @deprecated Compatibility with assignments created before the Event client correction. */
    public const MODERATOR = 'moderator';

    public const EVENT_STAFF = 'event_staff';

    /** @deprecated Compatibility with assignments created before the Event client correction. */
    public const CLASS_MAYOR = 'class_mayor';

    public const RESPONSIBILITIES = [
        self::SEMI_COORDINATOR,
        self::REGISTERED_MODERATOR,
        self::REQUESTED_MODERATOR,
        self::EVENT_STAFF,
        self::MODERATOR,
        self::CLASS_MAYOR,
    ];

    protected $fillable = [
        'event_id',
        'user_id',
        'responsibility',
        'assigned_by_user_id',
        'assigned_at',
        'starts_at',
        'ends_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function scopeGranting(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $window) => $window->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $window) => $window->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isGranting(): bool
    {
        return $this->revoked_at === null
            && ($this->starts_at === null || ! $this->starts_at->isFuture())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function responsibilityLabel(): string
    {
        return match ($this->responsibility) {
            self::SEMI_COORDINATOR => 'Semi-Coordinator',
            self::REGISTERED_MODERATOR, self::MODERATOR => 'Registered Moderator',
            self::EVENT_STAFF => 'Event Staff / Manual Verification',
            self::REQUESTED_MODERATOR, self::CLASS_MAYOR => 'Requested Moderator / Class Mayor',
            default => 'Event Personnel',
        };
    }

    public function canonicalResponsibility(): string
    {
        return match ($this->responsibility) {
            self::MODERATOR => self::REGISTERED_MODERATOR,
            self::CLASS_MAYOR => self::REQUESTED_MODERATOR,
            default => $this->responsibility,
        };
    }
}
