<?php

namespace App\Models\Enrollment;

use Illuminate\Database\Eloquent\Model;

class EnrollmentWorkflowEvent extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Enrollment evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Enrollment evidence is immutable.'));
    }
}
