<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRecordRecentView extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'student_id', 'viewed_at'];

    protected function casts(): array
    {
        return ['viewed_at' => 'immutable_datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
