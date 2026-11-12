<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginOtpChallenge extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'client',
        'code_hash',
        'failed_attempts',
        'expires_at',
        'resend_available_at',
        'consumed_at',
        'superseded_at',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'consumed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
