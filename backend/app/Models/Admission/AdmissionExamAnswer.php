<?php

namespace App\Models\Admission;

use Illuminate\Database\Eloquent\Model;

class AdmissionExamAnswer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['saved_at' => 'immutable_datetime', 'is_correct' => 'boolean'];
    }
}
