<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradePeriodSchedule extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['midterm_opens_at' => 'datetime', 'midterm_deadline' => 'datetime', 'finals_opens_at' => 'datetime', 'finals_deadline' => 'datetime'];
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }
}
