<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskNotification extends Model
{
    protected $fillable = ['student_id', 'sender_user_id', 'risk_level', 'title', 'message', 'context_json', 'read_at'];

    protected function casts(): array
    {
        return ['context_json' => 'array', 'read_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
