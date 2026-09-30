<?php

namespace App\Models;

use App\Models\Enrollment\EnrollmentApplication;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = ['course_id', 'academic_year_id', 'semester_id', 'section_name', 'year_level', 'adviser_id', 'capacity', 'status', 'version'];

    protected $attributes = ['version' => 1];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function schedules()
    {
        return $this->hasMany(SectionSubject::class);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function applications()
    {
        return $this->hasMany(EnrollmentApplication::class);
    }
}
