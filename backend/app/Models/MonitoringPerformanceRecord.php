<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringPerformanceRecord extends Model
{
    protected $fillable = [
        'student_id',
        'professor_user_id',
        'subject_code',
        'subject_name',
        'assessment_name',
        'topic',
        'score',
        'max_score',
        'notes',
        'attachment_path',
        'attachment_name',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'max_score' => 'float',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_user_id');
    }
}
