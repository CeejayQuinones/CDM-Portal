<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCertificate extends Model
{
    protected $fillable = ['event_id', 'student_id', 'version', 'verification_code', 'status', 'active_issue_key', 'issued_at', 'issued_by_user_id', 'revoked_at', 'revoked_by_user_id', 'revocation_reason'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
