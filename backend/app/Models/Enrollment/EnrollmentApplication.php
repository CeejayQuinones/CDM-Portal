<?php

namespace App\Models\Enrollment;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;

class EnrollmentApplication extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'status' => 'draft'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function period()
    {
        return $this->belongsTo(EnrollmentPeriod::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function documents()
    {
        return $this->hasMany(EnrollmentDocument::class, 'application_id');
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'enrollment_application_subjects', 'application_id', 'subject_id')->withTimestamps();
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }
}
