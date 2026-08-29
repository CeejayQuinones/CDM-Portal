<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingPeriod extends Model
{
    protected $fillable = [
        'period_name',
        'period_order',
        'status',
    ];
}
