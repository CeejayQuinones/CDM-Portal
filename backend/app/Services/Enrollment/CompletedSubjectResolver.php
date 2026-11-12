<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment\EnrollmentApplication;
use App\Models\EnrollmentSubject;
use App\Models\HistoricalGradeRecord;

class CompletedSubjectResolver
{
    public function forApplication(EnrollmentApplication $application, bool $lock = false): array
    {
        if (! $application->student_id) {
            return [];
        }

        $published = EnrollmentSubject::join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
            ->where('enrollments.student_id', $application->student_id)
            ->whereIn('enrollments.status', ['enrolled', 'completed'])
            ->where('enrollment_subjects.subject_status', 'completed')
            ->where('enrollment_subjects.remarks', 'Passed')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->pluck('enrollment_subjects.subject_id')->all();

        $historical = HistoricalGradeRecord::where('student_id', $application->student_id)
            ->where('curriculum_id', $application->curriculum_id)
            ->where('verification_status', 'verified')->where('official_result', 'Passed')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->pluck('subject_id')->all();

        return array_values(array_unique([...$published, ...$historical]));
    }
}
