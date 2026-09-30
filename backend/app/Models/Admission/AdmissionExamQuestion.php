<?php

namespace App\Models\Admission;

use Illuminate\Database\Eloquent\Model;

class AdmissionExamQuestion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
