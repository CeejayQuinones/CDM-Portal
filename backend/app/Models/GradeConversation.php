<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeConversation extends Model
{
    protected $guarded = ['id'];

    public function gradeSheet()
    {
        return $this->belongsTo(GradeSheet::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function professor()
    {
        return $this->belongsTo(Professor::class);
    }

    public function messages()
    {
        return $this->hasMany(GradeMessage::class);
    }
}
