<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingScale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_percentage' => 'decimal:2',
            'max_percentage' => 'decimal:2',
            'grade_point' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
