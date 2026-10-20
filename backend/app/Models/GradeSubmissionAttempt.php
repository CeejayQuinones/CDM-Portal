<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeSubmissionAttempt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'configuration' => 'array', 'midterm_weight' => 'float', 'finals_weight' => 'float'];
    }

    public function gradeSheet()
    {
        return $this->belongsTo(GradeSheet::class);
    }

    public function students()
    {
        return $this->hasMany(GradeSubmissionStudent::class);
    }
}
