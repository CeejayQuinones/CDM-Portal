<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRosterImportPreview extends Model
{
    protected $fillable = ['token_hash', 'actor_user_id', 'rows', 'expires_at'];

    protected function casts(): array
    {
        return ['rows' => 'encrypted:array', 'expires_at' => 'datetime'];
    }
}
