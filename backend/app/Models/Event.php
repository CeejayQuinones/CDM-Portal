<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    public const MANAGED_STATUSES = ['draft', 'published', 'cancelled', 'archived'];

    public const ATTENDANCE_MODE_CHECK_IN_ONLY = 'check_in_only';

    public const ATTENDANCE_MODE_CHECK_IN_AND_OUT = 'check_in_and_out';

    public const ATTENDANCE_MODES = [self::ATTENDANCE_MODE_CHECK_IN_ONLY, self::ATTENDANCE_MODE_CHECK_IN_AND_OUT];

    public const ATTENDANCE_METHODS = ['dynamic_event', 'static_event', 'participant_static', 'participant_dynamic', 'manual'];

    public const DEFAULT_ATTENDANCE_METHODS = ['dynamic_event', 'participant_static', 'participant_dynamic', 'manual'];

    protected $fillable = ['parent_event_id', 'sort_order', 'title', 'description', 'venue', 'venue_key', 'starts_at', 'ends_at', 'status', 'attendance_mode', 'late_threshold_minutes', 'minimum_participation_minutes', 'attendance_methods', 'certificates_enabled', 'certificate_minimum_minutes', 'version', 'created_by', 'updated_by'];

    protected $attributes = ['status' => 'draft', 'attendance_mode' => 'check_in_only', 'late_threshold_minutes' => 15, 'sort_order' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'attendance_methods' => 'array', 'certificates_enabled' => 'boolean', 'certificate_minimum_minutes' => 'integer', 'late_threshold_minutes' => 'integer', 'minimum_participation_minutes' => 'integer', 'sort_order' => 'integer', 'version' => 'integer'];
    }

    public function audiences(): HasMany
    {
        return $this->hasMany(EventAudience::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    public function subEvents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id')->orderBy('sort_order')->orderBy('starts_at');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(EventAuditEvent::class)->latest('created_at');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(EventRoleAssignment::class);
    }

    public function moderatorRequests(): HasMany
    {
        return $this->hasMany(EventModeratorRequest::class);
    }

    public function attendanceSession()
    {
        return $this->hasOne(EventAttendanceSession::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EventAttendance::class);
    }

    public function attendanceVerifications(): HasMany
    {
        return $this->hasMany(EventAttendanceVerification::class);
    }

    public function certificateRequirements(): HasMany
    {
        return $this->hasMany(EventCertificateRequirement::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(EventCertificate::class);
    }

    public function supportsAttendanceMethod(string $method): bool
    {
        return in_array($method, $this->attendance_methods ?: self::DEFAULT_ATTENDANCE_METHODS, true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function presentationStatus(): string
    {
        if ($this->status !== 'published') {
            return $this->status;
        }
        if (now()->lt($this->starts_at)) {
            return 'published';
        }

        return now()->lt($this->ends_at) ? 'ongoing' : 'completed';
    }
}
