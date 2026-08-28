<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cabinet extends Model
{
    use HasFactory;

    protected $fillable = [
        'cabinet_code',
        'description',
        'rows',
        'columns',
    ];

    protected function casts(): array
    {
        return [
            'rows' => 'integer',
            'columns' => 'integer',
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(CabinetSlot::class);
    }
}
