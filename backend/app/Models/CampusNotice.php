<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampusNotice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const CATEGORIES = [
        'general', 'enrollment', 'admission', 'registrar_reminder',
        'class_suspension', 'school_policy', 'academic_reminder',
    ];

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    public const AUDIENCES = [Role::STUDENT, Role::GUEST];

    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    protected $fillable = [
        'title', 'message', 'category', 'priority', 'audiences', 'status',
        'publication_start_at', 'publication_end_at', 'effective_start_at', 'effective_end_at',
        'is_recurring', 'recurrence_weekdays', 'requires_acknowledgement', 'official_source',
        'affected_scope', 'target_route', 'created_by_user_id', 'updated_by_user_id',
        'published_by_user_id', 'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'audiences' => 'array',
            'recurrence_weekdays' => 'array',
            'is_recurring' => 'boolean',
            'requires_acknowledgement' => 'boolean',
            'publication_start_at' => 'immutable_datetime',
            'publication_end_at' => 'immutable_datetime',
            'effective_start_at' => 'immutable_datetime',
            'effective_end_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(CampusNoticeAuditEvent::class);
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(CampusNoticeAcknowledgement::class);
    }
}
