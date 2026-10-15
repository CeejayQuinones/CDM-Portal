<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSetting extends Model
{
    protected $fillable = ['student_id', 'preferred_display_name', 'bio', 'notification_preferences', 'academic_preferences', 'appearance'];

    protected function casts(): array
    {
        return ['notification_preferences' => 'array', 'academic_preferences' => 'array'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
