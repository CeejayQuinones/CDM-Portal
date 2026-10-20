<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['department_code', 'department_name', 'description', 'status'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
