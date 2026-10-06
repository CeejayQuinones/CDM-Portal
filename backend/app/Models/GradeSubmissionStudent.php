<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeSubmissionStudent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['midterm_grade' => 'float', 'finals_grade' => 'float', 'final_grade' => 'float', 'grade_point' => 'float', 'breakdown' => 'array'];
    }

    public function submission()
    {
        return $this->belongsTo(GradeSubmissionAttempt::class, 'grade_submission_attempt_id');
    }
}
