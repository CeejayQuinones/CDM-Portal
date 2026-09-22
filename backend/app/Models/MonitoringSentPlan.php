<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringSentPlan extends Model
{
    protected $fillable = [
        'student_id',
        'sender_user_id',
        'performance_record_id',
        'title',
        'topic',
        'subject_code',
        'plan_body',
        'source',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function performanceRecord(): BelongsTo
    {
        return $this->belongsTo(MonitoringPerformanceRecord::class, 'performance_record_id');
    }
}
