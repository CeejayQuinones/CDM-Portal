<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grade extends Model
{
    protected $fillable = [
        'enrollment_subject_id',
        'grading_period_id',
        'professor_id',
        'grade',
        'remarks',
        'status',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'grade' => 'float',
            'submitted_at' => 'datetime',
        ];
    }

    public function enrollmentSubject(): BelongsTo
    {
        return $this->belongsTo(EnrollmentSubject::class);
    }

    public function gradingPeriod(): BelongsTo
    {
        return $this->belongsTo(GradingPeriod::class);
    }

    public function professor(): BelongsTo
    {
        return $this->belongsTo(Professor::class);
    }
}
