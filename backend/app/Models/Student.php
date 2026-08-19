<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_profile_id',
        'course_id',
        'curriculum_id',
        'student_number',
        'admission_date',
        'year_level',
        'student_status',
    ];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'year_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** The student's most recent academic context, if one exists. */
    public function latestEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)->latestOfMany();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }
}
