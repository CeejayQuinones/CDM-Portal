<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeSheet extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'version' => 1, 'midterm_weight' => 40, 'finals_weight' => 60];

    protected function casts(): array
    {
        return ['midterm_weight' => 'float', 'finals_weight' => 'float', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'returned_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function sectionSubject()
    {
        return $this->belongsTo(SectionSubject::class);
    }

    public function professor()
    {
        return $this->belongsTo(Professor::class);
    }

    public function assessments()
    {
        return $this->hasMany(GradeAssessment::class);
    }

    public function weights()
    {
        return $this->hasMany(GradeCategoryWeight::class);
    }

    public function submissions()
    {
        return $this->hasMany(GradeSubmissionAttempt::class);
    }

    public function latestSubmission()
    {
        return $this->hasOne(GradeSubmissionAttempt::class)->latestOfMany('attempt_number');
    }

    public function conversations()
    {
        return $this->hasMany(GradeConversation::class);
    }
}
