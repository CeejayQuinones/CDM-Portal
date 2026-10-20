<?php

namespace App\Services\Enrollment;

use Carbon\CarbonImmutable;

final readonly class EnrollmentWindow
{
    public function __construct(
        public int $periodId,
        public int $academicYearId,
        public int $semesterId,
        public CarbonImmutable $opensAt,
        public CarbonImmutable $closesAt,
        public bool $enabled = true,
    ) {}
}
