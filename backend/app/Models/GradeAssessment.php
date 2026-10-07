<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeAssessment extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['max_score' => 'float'];
    }

    public function gradeSheet()
    {
        return $this->belongsTo(GradeSheet::class);
    }

    public function scores()
    {
        return $this->hasMany(GradeScore::class);
    }
}
