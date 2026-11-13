<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAccountVisitRequest extends Model
{
    protected $fillable = [
        'reference', 'student_number', 'full_name', 'contact_email',
        'preferred_date', 'preferred_time', 'status', 'scheduled_date',
        'scheduled_time', 'handled_by_user_id', 'handled_at',
        'identity_checked_by_user_id', 'identity_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date', 'scheduled_date' => 'date', 'handled_at' => 'datetime',
            'identity_checked_at' => 'datetime',
        ];
    }
}
