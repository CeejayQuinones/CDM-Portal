<?php

namespace App\Models\Enrollment;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Student;
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
}
