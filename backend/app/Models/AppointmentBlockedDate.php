<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentBlockedDate extends Model
{
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
            'blocked_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }
}
