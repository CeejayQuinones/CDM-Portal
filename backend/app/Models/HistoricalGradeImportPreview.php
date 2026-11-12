<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoricalGradeImportPreview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rows' => 'array', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
