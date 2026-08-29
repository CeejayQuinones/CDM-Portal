<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Professor extends Model
{
    protected $fillable = [
        'user_id',
        'user_profile_id',
        'department_id',
        'employee_number',
        'position',
        'specialization',
        'employment_status',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
