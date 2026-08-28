<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRecordLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'cabinet_slot_id',
        'assigned_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function cabinetSlot(): BelongsTo
    {
        return $this->belongsTo(CabinetSlot::class);
    }
}
