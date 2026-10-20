<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationEmailVerification extends Model
{
    protected $fillable = [
        'email',
        'code_hash',
        'token_hash',
        'expires_at',
        'verified_at',
        'consumed_at',
        'last_sent_at',
        'attempts',
    ];

    protected $hidden = ['code_hash', 'token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
