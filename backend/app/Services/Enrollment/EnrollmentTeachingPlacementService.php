<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment\EnrollmentApplication;
use App\Models\SectionSubject;
use App\Models\Subject;
use Illuminate\Support\Collection;

class EnrollmentTeachingPlacementService
{
    /** A student's selected classes may belong to different sections, but may not meet at the same time. */
    public function assertNoTimetableConflict(Collection $assignments): void
    {
        $meetings = $assignments->filter()->flatMap(fn (SectionSubject $assignment) => $assignment->meetings->map(
            fn ($meeting) => ['assignment_id' => $assignment->id, 'day' => $meeting->day,
                'start' => (string) $meeting->start_time, 'end' => (string) $meeting->end_time],
        ))->values();

        foreach ($meetings as $index => $meeting) {
            foreach ($meetings->slice($index + 1) as $other) {
                abort_if($meeting['assignment_id'] !== $other['assignment_id']
                    && $meeting['day'] === $other['day']
                    && $meeting['start'] < $other['end']
                    && $meeting['end'] > $other['start'], 409,
                    'The selected teaching sections have overlapping class times. Choose a different section before finalizing.');
            }
        }
    }

    public function valid(EnrollmentApplication $application, Subject $subject, SectionSubject $assignment): bool
    {
        $section = $assignment->section;

        return (int) $assignment->subject_id === (int) $subject->id
            && $assignment->professor_id !== null
            && $section !== null
            && $section->status === 'open'
            && (int) $section->course_id === (int) $application->course_id
            && (int) $section->curriculum_id === (int) $application->curriculum_id
            && (int) $section->academic_year_id === (int) $application->academic_year_id
            && (int) $section->semester_id === (int) $application->semester_id
            && (int) $section->year_level === (int) $subject->year_level;
    }

    /** @return Collection<int, SectionSubject> */
    public function options(EnrollmentApplication $application, array $subjectIds): Collection
    {
        if ($subjectIds === []) {
            return collect();
        }

        return SectionSubject::query()->with(['section', 'subject', 'professor.user.profile', 'meetings'])
            ->whereIn('subject_id', $subjectIds)
            ->whereNotNull('professor_id')
            ->whereHas('section', fn ($query) => $query
                ->where('status', 'open')
                ->where('course_id', $application->course_id)
                ->where('curriculum_id', $application->curriculum_id)
                ->where('academic_year_id', $application->academic_year_id)
                ->where('semester_id', $application->semester_id))
            ->orderBy('id')->get()
            ->filter(fn (SectionSubject $assignment) => (int) $assignment->section->year_level === (int) $assignment->subject?->year_level)
            ->values();
    }
}
