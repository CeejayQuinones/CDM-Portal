<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CabinetSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'cabinet_id',
        'slot_code',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function studentRecordLocations(): HasMany
    {
        return $this->hasMany(StudentRecordLocation::class);
    }
}
