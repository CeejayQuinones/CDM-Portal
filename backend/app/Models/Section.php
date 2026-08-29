<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = [
        'course_id',
        'academic_year_id',
        'semester_id',
        'section_name',
        'year_level',
        'adviser_id',
        'capacity',
        'status',
    ];
}