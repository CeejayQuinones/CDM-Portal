<?php

namespace App\Services\Grading;

use App\Models\GradingScale;

class GradingScaleService
{
    public function resolve(float $percentage): ?array
    {
        $scale = GradingScale::query()
            ->where('is_active', true)
            ->where('min_percentage', '<=', $percentage)
            ->where('max_percentage', '>=', $percentage)
            ->orderByDesc('min_percentage')
            ->first();

        return $scale ? ['grade_point' => (float) $scale->grade_point, 'remarks' => $scale->remark] : null;
    }
}
