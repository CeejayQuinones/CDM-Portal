<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCertificateRequirement extends Model
{
    protected $fillable = ['event_id', 'required_event_id'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function requiredEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'required_event_id');
    }
}
