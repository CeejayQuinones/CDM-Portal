<?php

namespace App\Models\Admission;

use App\Models\Course;
use Illuminate\Database\Eloquent\Model;

class AdmissionProgramSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['subjects' => 'array', 'career_paths' => 'array', 'recommendation_profile' => 'array', 'is_recommendable' => 'boolean', 'version' => 'integer'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
