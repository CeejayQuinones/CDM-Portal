<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAccountActivation extends Model
{
    protected $fillable = ['student_id', 'prepared_by_user_id', 'record_reference'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }
}
