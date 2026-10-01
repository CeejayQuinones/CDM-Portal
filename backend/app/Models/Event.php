<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    public const MANAGED_STATUSES = ['draft', 'published', 'cancelled', 'archived'];

    protected $fillable = ['title', 'description', 'venue', 'venue_key', 'starts_at', 'ends_at', 'status', 'version', 'created_by', 'updated_by'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'version' => 'integer'];
    }

    public function audiences(): HasMany
    {
        return $this->hasMany(EventAudience::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(EventAuditEvent::class)->latest('created_at');
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
