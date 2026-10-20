<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['curriculum_id', 'semester_id', 'subject_code', 'subject_name', 'year_level', 'units', 'lecture_hours', 'laboratory_hours', 'prerequisite_subject_id', 'status'];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prerequisite_subject_id');
    }

    public function dependentSubjects(): HasMany
    {
        return $this->hasMany(self::class, 'prerequisite_subject_id');
    }

    public function sectionSubjects(): HasMany
    {
        return $this->hasMany(SectionSubject::class);
    }

    public function enrollmentSubjects(): HasMany
    {
        return $this->hasMany(EnrollmentSubject::class);
    }
}
