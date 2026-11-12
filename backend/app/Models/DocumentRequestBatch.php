<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRequestBatch extends Model
{
    protected $fillable = ['student_id', 'idempotency_key', 'payload_hash'];

    protected $hidden = ['idempotency_key', 'payload_hash'];

    protected $appends = ['group_reference'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    protected function groupReference(): Attribute
    {
        return Attribute::get(fn (): string => sprintf('GROUP-%06d', $this->getKey()));
    }
}
