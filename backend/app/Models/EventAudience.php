<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAudience extends Model
{
    public const TYPES = ['all_students', 'all_professors', 'all_users', 'course', 'year_level', 'section'];

    protected $fillable = ['event_id', 'audience_type', 'course_id', 'year_level', 'section_id'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
