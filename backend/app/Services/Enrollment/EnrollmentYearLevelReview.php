<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Student;
use App\Models\Subject;

class EnrollmentYearLevelReview
{
    public function __construct(private readonly CompletedSubjectResolver $completedSubjects) {}

    public function assertSelectable(Student $student, int $requestedYear): void
    {
        $programYears = (int) $student->course()->value('years');
        abort_unless(
            $programYears >= 1
                && $requestedYear >= $student->year_level
                && $requestedYear <= $student->year_level + 1
                && $requestedYear <= $programYears,
            422,
            'Choose your current or next year level within your program. The Registrar reviews advancement.',
        );
    }

    /** @return array<string, mixed> */
    public function review(EnrollmentApplication $application, bool $lock = false): array
    {
        $student = $application->student;
        if (! $student) {
            return ['current_year_level' => null, 'requested_year_level' => 1,
                'requires_promotion' => false, 'eligible_for_promotion' => true,
                'required_subjects' => 0, 'completed_subjects' => 0, 'outstanding_subjects' => []];
        }

        $promoting = (int) $application->year_level > (int) $student->year_level;
        $required = $promoting ? Subject::query()
            ->where('curriculum_id', $application->curriculum_id)
            ->where('year_level', $student->year_level)
            ->where('status', 'active')
            ->orderBy('subject_code')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'subject_code', 'subject_name']) : collect();
        $completed = $promoting ? $this->completedSubjects->forApplication($application, $lock) : [];
        $outstanding = $required->reject(fn (Subject $subject) => in_array($subject->id, $completed, true));

        return [
            'current_year_level' => $student->year_level,
            'requested_year_level' => (int) $application->year_level,
            'requires_promotion' => $promoting,
            'eligible_for_promotion' => ! $promoting || ($required->isNotEmpty() && $outstanding->isEmpty()),
            'required_subjects' => $required->count(),
            'completed_subjects' => $required->count() - $outstanding->count(),
            'outstanding_subjects' => $outstanding->map(fn (Subject $subject) => [
                'code' => $subject->subject_code,
                'name' => $subject->subject_name,
            ])->values()->all(),
        ];
    }

    public function assertEligible(EnrollmentApplication $application, bool $lock = false): array
    {
        if ($application->student) {
            $this->assertSelectable($application->student, (int) $application->year_level);
        }
        $review = $this->review($application, $lock);
        abort_unless($review['eligible_for_promotion'], 422,
            'Year-level advancement requires verified passing results for every subject in the current year. Review outstanding subjects or keep the current year level.');

        return $review;
    }
}
