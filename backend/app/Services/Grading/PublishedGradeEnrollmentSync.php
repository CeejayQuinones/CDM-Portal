<?php

namespace App\Services\Grading;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeSheet;

class PublishedGradeEnrollmentSync
{
    public function synchronize(GradeSheet $sheet): void
    {
        $assignment = $sheet->sectionSubject()->with('section')->firstOrFail();
        $attempt = $sheet->latestSubmission()->with(['students' => fn ($query) => $query->orderBy('enrollment_subject_id')])->firstOrFail();

        foreach ($attempt->students as $result) {
            $enrollmentSubject = EnrollmentSubject::query()->lockForUpdate()->findOrFail($result->enrollment_subject_id);
            $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollmentSubject->enrollment_id);

            abort_unless(
                (int) $enrollmentSubject->subject_id === (int) $assignment->subject_id
                && (int) $enrollment->student_id === (int) $result->student_id
                && ($enrollmentSubject->section_subject_id
                    ? (int) $enrollmentSubject->section_subject_id === (int) $assignment->id
                    : (int) $enrollment->section_id === (int) $assignment->section_id)
                && (int) $enrollment->academic_year_id === (int) $assignment->section->academic_year_id
                && (int) $enrollment->semester_id === (int) $assignment->section->semester_id,
                409,
                'A published result no longer matches its authoritative Enrollment subject.',
            );

            [$subjectStatus, $remarks] = match (strtoupper(trim((string) $result->remarks))) {
                'PASSED' => ['completed', 'Passed'],
                'FAILED' => ['completed', 'Failed'],
                'INCOMPLETE' => ['enrolled', 'Incomplete'],
                default => abort(422, 'Every published result requires an official PASSED, FAILED, or INCOMPLETE outcome.'),
            };

            $enrollmentSubject->forceFill([
                'subject_status' => $subjectStatus,
                'final_grade' => $result->final_grade,
                'remarks' => $remarks,
            ])->save();
        }
    }
}
