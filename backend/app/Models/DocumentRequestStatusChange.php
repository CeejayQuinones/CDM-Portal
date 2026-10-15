<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequestStatusChange extends Model
{
    protected $fillable = [
        'document_request_id',
        'registrar_staff_id',
        'actor_type',
        'from_status',
        'to_status',
        'action',
        'reason',
    ];

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function registrarStaff(): BelongsTo
    {
        return $this->belongsTo(RegistrarStaff::class);
    }
}
