<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentBlockedDate extends Model
{
    public const TYPES = [
        'holiday',
        'maintenance',
        'office_closure',
        'school_event',
        'other',
    ];

    protected $fillable = [
        'blocked_date',
        'type',
        'reason',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'blocked_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
