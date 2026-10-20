<?php

namespace App\Models\Admission;

use Illuminate\Database\Eloquent\Model;

class AdmissionExamSessionQuestion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Admission evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Admission evidence is immutable.'));
    }
}
