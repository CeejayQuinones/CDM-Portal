<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeScore extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['score' => 'float'];
    }
}
