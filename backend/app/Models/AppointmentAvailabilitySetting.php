<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentAvailabilitySetting extends Model
{
    protected $fillable = [
        'block_saturday',
        'block_sunday',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'block_saturday' => 'boolean',
            'block_sunday' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
