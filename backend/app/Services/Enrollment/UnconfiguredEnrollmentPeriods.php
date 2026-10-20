<?php

namespace App\Services\Enrollment;

use App\Models\Student;

final class UnconfiguredEnrollmentPeriods implements EnrollmentPeriodResolver
{
    public function forStudent(Student $student): ?EnrollmentWindow
    {
        // Foundation has no period table: never manufacture dates or infer a term from Admission.
        return null;
    }
}
