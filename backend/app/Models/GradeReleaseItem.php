<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeReleaseItem extends Model
{
    protected $guarded = ['id'];

    public function gradeSheet()
    {
        return $this->belongsTo(GradeSheet::class);
    }
}
