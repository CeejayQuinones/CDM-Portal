<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeCategoryWeight extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['weight_percentage' => 'float'];
    }
}
