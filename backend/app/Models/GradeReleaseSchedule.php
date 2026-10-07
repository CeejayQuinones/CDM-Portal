<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeReleaseSchedule extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'scheduled', 'version' => 1];

    protected function casts(): array
    {
        return ['release_at' => 'datetime', 'executed_at' => 'datetime'];
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function items()
    {
        return $this->hasMany(GradeReleaseItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
