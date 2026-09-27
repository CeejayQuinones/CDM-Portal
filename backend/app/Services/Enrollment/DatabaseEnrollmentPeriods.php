<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class DatabaseEnrollmentPeriods implements EnrollmentPeriodResolver
{
    public function forStudent(Student $student): ?EnrollmentWindow
    {
        $periods = EnrollmentPeriod::where('enabled', true)->where('closes_at', '>', now())->whereHas('academicYear', fn ($q) => $q->where('status', 'active'))->whereHas('semester', fn ($q) => $q->where('status', 'active'))->orderBy('opens_at')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
        $open = $periods->filter(fn ($p) => $p->state === 'open');
        if ($open->count() > 1) {
            return null;
        }
        $p = $open->first() ?? $periods->first() ?? EnrollmentPeriod::latest('closes_at')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();

        return $p ? new EnrollmentWindow($p->id, $p->academic_year_id, $p->semester_id, $p->opens_at, $p->closes_at, $p->enabled) : null;
    }
}
