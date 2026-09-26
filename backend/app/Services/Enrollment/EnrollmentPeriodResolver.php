<?php

namespace App\Services\Enrollment;

use App\Models\Student;

interface EnrollmentPeriodResolver
{
    // A future provider must select an unambiguous authorized window from real period storage.
    public function forStudent(Student $student): ?EnrollmentWindow;
}
