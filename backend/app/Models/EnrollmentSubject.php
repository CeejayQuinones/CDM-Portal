<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnrollmentSubject extends Model
{
    protected $fillable = [
        'enrollment_id',
        'subject_id',
        'professor_id',
        'subject_status',
        'final_grade',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'final_grade' => 'float',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function professor(): BelongsTo
    {
        return $this->belongsTo(Professor::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
