<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalGradeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical grade events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Historical grade events are immutable.'));
    }
}
