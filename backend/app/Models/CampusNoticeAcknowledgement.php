<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampusNoticeAcknowledgement extends Model
{
    public $timestamps = false;

    protected $fillable = ['campus_notice_id', 'user_id', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'immutable_datetime'];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(CampusNotice::class, 'campus_notice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
