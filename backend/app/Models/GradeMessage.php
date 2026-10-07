<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'unsent_at' => 'datetime'];
    }

    public function conversation()
    {
        return $this->belongsTo(GradeConversation::class, 'grade_conversation_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
