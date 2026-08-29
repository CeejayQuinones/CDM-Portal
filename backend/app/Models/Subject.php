<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        'curriculum_id',
        'semester_id',
        'subject_code',
        'subject_name',
        'year_level',
        'units',
        'lecture_hours',
        'laboratory_hours',
        'prerequisite_subject_id',
        'status',
    ];
}
