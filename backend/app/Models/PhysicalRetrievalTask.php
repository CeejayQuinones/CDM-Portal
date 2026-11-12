<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhysicalRetrievalTask extends Model
{
    protected $fillable = [
        'student_id', 'active_student_id', 'status', 'cabinet_code_snapshot', 'slot_code_snapshot',
        'retrieved_by', 'returned_by', 'retrieved_at', 'returned_at',
    ];

    protected function casts(): array
    {
        return ['retrieved_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentRequests(): BelongsToMany
    {
        return $this->belongsToMany(DocumentRequest::class, 'physical_retrieval_request');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PhysicalRetrievalEvent::class);
    }
}
