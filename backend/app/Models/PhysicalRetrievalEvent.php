<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalRetrievalEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'registrar_staff_id', 'action', 'cabinet_code_snapshot', 'slot_code_snapshot', 'created_at',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(PhysicalRetrievalTask::class, 'physical_retrieval_task_id');
    }
}
