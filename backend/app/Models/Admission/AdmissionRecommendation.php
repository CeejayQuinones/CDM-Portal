<?php

namespace App\Models\Admission;

use Illuminate\Database\Eloquent\Model;

class AdmissionRecommendation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['interests' => 'array', 'catalog_snapshot' => 'array', 'ranked_programs' => 'array', 'evidence' => 'array', 'explanations' => 'array', 'generated_at' => 'immutable_datetime'];
    }
}
