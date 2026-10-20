<?php

namespace App\Services;

use App\Models\EnrollmentSubject;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\SectionSubject;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EarlyWarningService
{
    private const FINALIZED_ENROLLMENT_STATUSES = ['enrolled', 'completed'];

    private const ACTIVE_SUBJECT_STATUSES = ['enrolled', 'completed'];

    /** A change band for a material checkpoint movement, not a passing-grade rule. */
    private const MATERIAL_CHANGE = 5.0;

    /** A larger within-subject decrease used as an early-warning signal. */
    private const CONCERNING_DECLINE = 10.0;

    private const SEVERE_DECLINE = 20.0;

    /** @return array<string, mixed> */
    public function overview(?int $professorUserId = null, array $filters = []): array
    {
        return $this->buildOverview(null, $professorUserId, $filters);
    }

    /** @return array<string, mixed> */
    public function assessForUserId(int $userId, array $filters = []): array
    {
        return $this->buildOverview($userId, null, $filters);
    }

    /** @return array<string, mixed>|null */
    public function assessByStudentId(int $studentId, ?int $professorUserId = null, array $filters = []): ?array
    {
        $student = Student::query()->find($studentId);
        if (! $student) {
            return null;
        }

        $overview = $this->buildOverview($student->user_id, $professorUserId, $filters);

        return collect($overview['students'])->firstWhere('student_id', $studentId);
    }

    public function professorCanAccessStudent(int $professorUserId, int $studentId): bool
    {
        $professorId = Professor::query()
            ->where('user_id', $professorUserId)
            ->where('status', 'active')
            ->value('id');

        if (! $professorId) {
            return false;
        }

        $assignments = SectionSubject::query()
            ->where('professor_id', $professorId)
            ->get(['section_id', 'subject_id']);

        return $assignments->contains(function (SectionSubject $assignment) use ($professorId, $studentId): bool {
            return EnrollmentSubject::query()
                ->where('professor_id', $professorId)
                ->where('subject_id', $assignment->subject_id)
                ->whereIn('subject_status', self::ACTIVE_SUBJECT_STATUSES)
                ->whereHas('enrollment', fn (Builder $query) => $query
                    ->where('student_id', $studentId)
                    ->where('section_id', $assignment->section_id)
                    ->whereIn('status', self::FINALIZED_ENROLLMENT_STATUSES))
                ->exists();
        });
    }

    /** @param array<string, mixed> $assessment @return array{summary:string,actions:list<string>,prevention_note:string} */
    public function generateSupportPlan(array $assessment): array
    {
        $focus = collect($assessment['subjects'] ?? [])
            ->whereIn('risk_level', ['high', 'moderate'])
            ->pluck('subject_code')
            ->filter()
            ->implode(', ');

        if ($focus === '') {
            return [
                'summary' => 'No material published-grade warning is currently available.',
                'actions' => ['Review newly published results when they become available.', 'Ask the subject professor about any unclear official result.'],
                'prevention_note' => 'This guidance uses published results only and does not predict a final outcome.',
            ];
        }

        return [
            'summary' => "Published results show signals that merit attention in {$focus}.",
            'actions' => ["Review the published checkpoint change in {$focus}.", 'Prepare specific questions for the subject professor.', 'Recheck progress after the next official result is published.'],
            'prevention_note' => 'The monitoring level is an early-warning signal, not an institutional pass/fail decision.',
        ];
    }

    /** @param array<string, mixed> $assessment @return array<string, mixed> */
    public function generateStudyPlan(array $assessment): array
    {
        $level = $assessment['risk_level'];
        $published = collect($assessment['subjects'] ?? []);
        $focusSubjects = $published
            ->filter(fn (array $subject) => in_array($subject['risk_level'], ['high', 'moderate'], true) || $subject['trend'] === 'declining')
            ->sortBy(fn (array $subject) => sprintf('%d:%010.2f:%s', match ($subject['risk_level']) {
                'high' => 0,
                'moderate' => 1,
                default => 2,
            }, 1000 + (float) ($subject['checkpoint_change'] ?? 0), $subject['subject_code']))
            ->values();
        if ($focusSubjects->isEmpty() && $level !== 'insufficient') {
            $focusSubjects = $published->take(2)->values();
        }

        $sessionCount = match ($level) {
            'high' => min(7, max(5, $focusSubjects->count() + 4)),
            'moderate' => min(6, max(4, $focusSubjects->count() + 3)),
            'stable' => 3,
            default => 2,
        };
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $sessionTypes = ['Published result review', 'Targeted concept review', 'Next-assessment preparation', 'Practice and self-check', 'Consultation preparation', 'Weekly progress review', 'Study schedule reinforcement'];
        $week = collect(range(0, $sessionCount - 1))->map(function (int $index) use ($assessment, $days, $focusSubjects, $level, $sessionTypes): array {
            $subject = $focusSubjects->isEmpty() ? null : $focusSubjects[$index % $focusSubjects->count()];
            $duration = match ($level) {
                'high' => $subject && $subject['risk_level'] === 'high' ? 90 : 75,
                'moderate' => $subject && $subject['trend'] === 'declining' ? 75 : 60,
                'stable' => 45,
                default => 30,
            };
            $focus = match (true) {
                $level === 'insufficient' => 'Review available course materials and check again when more official results are published.',
                $index % 5 === 0 => 'Compare the published Midterm, Finals, and final results and list topics that need clarification.',
                $index % 5 === 1 => 'Review the subject concepts connected to the published performance signal.',
                $index % 5 === 2 => 'Prepare for the next announced assessment using current course materials.',
                $index % 5 === 3 => 'Complete a self-check and record questions without changing any official grade data.',
                default => 'Prepare specific questions for a consultation with the subject Professor.',
            };

            return [
                'day' => $days[$index],
                'time_slot' => $index < 5 ? 'Flexible weekday block' : 'Flexible weekend block',
                'session_type' => $sessionTypes[$index],
                'subject_code' => $subject['subject_code'] ?? 'General',
                'subject_name' => $subject['subject_name'] ?? 'Academic review',
                'focus' => $focus,
                'objective' => $this->planObjective($level, $assessment['data_completeness']['status']),
                'duration_minutes' => $duration,
                'priority' => $subject['risk_level'] ?? ($level === 'insufficient' ? 'insufficient' : 'stable'),
            ];
        })->all();

        return [
            'student_id' => $assessment['student_id'],
            'student_number' => $assessment['student_number'],
            'student_name' => $assessment['student_name'],
            'course_code' => $assessment['course_code'],
            'section' => $assessment['section'],
            'risk_level' => $level,
            'risk_label' => $assessment['risk_label'],
            'plan_label' => 'Suggested Academic Support Plan',
            'plan_type' => match ($level) {
                'high' => 'Recovery support',
                'moderate' => 'Targeted support',
                'stable' => 'Maintenance support',
                default => 'Preliminary support',
            },
            'headline' => match ($level) {
                'high' => 'Prioritize published high-risk and declining subject signals with larger review blocks.',
                'moderate' => 'Use targeted review and next-assessment preparation for the published concern.',
                'stable' => 'Maintain consistent review habits around the available published results.',
                default => 'More Published grade data is needed to build a detailed support plan.',
            },
            'objective' => $this->planObjective($level, $assessment['data_completeness']['status']),
            'risk_reasons' => $assessment['reasons'],
            'data_completeness' => $assessment['data_completeness'],
            'focus_subjects' => $focusSubjects->map(fn (array $subject) => [
                'subject_code' => $subject['subject_code'],
                'subject_name' => $subject['subject_name'],
                'risk_level' => $subject['risk_level'],
                'risk_label' => $subject['risk_label'],
                'trend' => $subject['trend'],
                'checkpoint_change' => $subject['checkpoint_change'],
                'midterm_grade' => $subject['midterm_grade'],
                'finals_grade' => $subject['finals_grade'],
                'final_grade' => $subject['final_grade'],
                'reasons' => $subject['reasons'],
            ])->all(),
            'total_hours' => round(collect($week)->sum('duration_minutes') / 60, 1),
            'session_count' => count($week),
            'week' => $week,
            'generated_at' => $assessment['evaluated_at'],
        ];
    }

    /** @return array{summary:array<string,int>,alerts:list<array<string,mixed>>} */
    public function adviserAlerts(?int $professorUserId = null, array $filters = []): array
    {
        $students = $this->overview($professorUserId, $filters)['students'];
        $alerts = collect($students)
            ->filter(fn (array $student) => in_array($student['risk_level'], ['high', 'moderate'], true) || $student['trend'] === 'declining')
            ->map(function (array $student): array {
                $severity = $student['risk_level'] === 'high' ? 'urgent' : 'attention';

                return [
                    'id' => 'alert-'.$student['student_id'].'-'.($student['term']['academic_year_id'] ?? 'none').'-'.($student['term']['semester_id'] ?? 'none'),
                    'student_id' => $student['student_id'],
                    'student_number' => $student['student_number'],
                    'student_name' => $student['student_name'],
                    'course_code' => $student['course_code'],
                    'section' => $student['section'],
                    'severity' => $severity,
                    'risk_level' => $student['risk_level'],
                    'risk_label' => $student['risk_label'],
                    'title' => $severity === 'urgent' ? 'High academic-risk signal' : 'Academic trend needs attention',
                    'message' => $student['headline'],
                    'risk_reasons' => $student['reasons'],
                    'trend' => $student['trend'],
                    'trend_label' => $student['trend_label'],
                    'published_subjects_analyzed' => $student['subjects_analyzed'],
                    'at_risk_subjects' => $student['at_risk_subjects'],
                    'evaluated_at' => $student['evaluated_at'],
                ];
            })->values();

        return [
            'summary' => [
                'urgent' => $alerts->where('severity', 'urgent')->count(),
                'attention' => $alerts->where('severity', 'attention')->count(),
                'total' => $alerts->count(),
            ],
            'alerts' => $alerts->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildOverview(?int $studentUserId, ?int $professorUserId, array $filters): array
    {
        $professorId = $professorUserId === null ? null : Professor::query()
            ->where('user_id', $professorUserId)
            ->where('status', 'active')
            ->value('id');

        if ($professorUserId !== null && ! $professorId) {
            return $this->emptyOverview();
        }

        $assignments = $professorId
            ? SectionSubject::query()->where('professor_id', $professorId)->get(['section_id', 'subject_id'])
            : collect();
        $assignmentKeys = $assignments->mapWithKeys(fn (SectionSubject $row) => [$row->section_id.':'.$row->subject_id => true]);

        $enrollmentSubjects = EnrollmentSubject::query()
            ->whereIn('subject_status', self::ACTIVE_SUBJECT_STATUSES)
            ->when($professorId, fn (Builder $query) => $query->where('professor_id', $professorId))
            ->when($studentUserId, fn (Builder $query) => $query->whereHas('enrollment.student', fn (Builder $student) => $student->where('user_id', $studentUserId)))
            ->whereHas('enrollment', fn (Builder $query) => $query->whereIn('status', self::FINALIZED_ENROLLMENT_STATUSES))
            ->with([
                'subject',
                'enrollment.academicYear',
                'enrollment.semester',
                'enrollment.section',
                'enrollment.student.userProfile',
                'enrollment.student.course',
            ])
            ->get()
            ->filter(function (EnrollmentSubject $row) use ($assignmentKeys, $professorId): bool {
                if (! $professorId) {
                    return true;
                }

                return isset($assignmentKeys[$row->enrollment->section_id.':'.$row->subject_id]);
            })
            ->values();

        $terms = $enrollmentSubjects
            ->map(fn (EnrollmentSubject $row) => $this->termFromEnrollmentSubject($row))
            ->unique(fn (array $term) => $term['academic_year_id'].':'.$term['semester_id'])
            ->sortByDesc(fn (array $term) => sprintf('%010d:%010d', $term['academic_year_id'], $term['semester_order']))
            ->values();
        $selectedTerm = $this->selectedTerm($terms, $filters);

        $selectedSubjects = $selectedTerm
            ? $enrollmentSubjects->filter(fn (EnrollmentSubject $row) => $row->enrollment->academic_year_id === $selectedTerm['academic_year_id'] && $row->enrollment->semester_id === $selectedTerm['semester_id'])->values()
            : collect();

        $students = $selectedSubjects->map(fn (EnrollmentSubject $row) => $row->enrollment->student)->filter()->unique('id')->values();
        if ($studentUserId && $students->isEmpty()) {
            $student = Student::query()->with(['userProfile', 'course'])->where('user_id', $studentUserId)->first();
            if ($student) {
                $students = collect([$student]);
            }
        }

        $sheets = $selectedSubjects->isEmpty()
            ? collect()
            : GradeSheet::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->when($professorId, fn (Builder $query) => $query
                    ->where('professor_id', $professorId)
                    ->whereHas('sectionSubject', fn (Builder $assignment) => $assignment->where('professor_id', $professorId)))
                ->whereHas('latestSubmission.students', fn (Builder $query) => $query->whereIn('enrollment_subject_id', $selectedSubjects->pluck('id')))
                ->with(['latestSubmission.students', 'sectionSubject.subject', 'sectionSubject.section'])
                ->get();

        $officialResults = $this->officialResults($sheets, $selectedSubjects);
        $assessments = $students->map(fn (Student $student) => $this->assessment(
            $student,
            $selectedSubjects->filter(fn (EnrollmentSubject $row) => $row->enrollment->student_id === $student->id)->values(),
            $officialResults,
            $selectedTerm,
        ));

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $needle = mb_strtolower($search);
            $assessments = $assessments->filter(fn (array $student) => str_contains(mb_strtolower($student['student_name'].' '.$student['student_number'].' '.($student['course_code'] ?? '')), $needle));
        }
        if ($risk = $filters['risk'] ?? null) {
            $assessments = $assessments->where('risk_level', $risk);
        }

        $assessments = $assessments
            ->sortBy(fn (array $student) => sprintf('%d:%s', $this->riskOrder($student['risk_level']), $student['student_number']))
            ->values();

        return [
            'summary' => $this->summary($assessments),
            'students' => $assessments->all(),
            'terms' => $terms->all(),
            'selected_term' => $selectedTerm,
            'methodology' => [
                'source' => 'Published Grade Sheets and their latest immutable submission snapshots, matched to finalized Enrollment subjects.',
                'excluded' => ['Draft, Submitted, Returned, and Approved grade sheets', 'live professor score entries', 'legacy grades and grading periods', 'Admission examinations', 'Event attendance'],
                'risk_rule' => 'Signals use within-subject checkpoint decreases and lower-quarter standing within the same published class snapshot. They do not apply an institutional passing grade or GWA cutoff.',
                'trend_rule' => 'Trend compares finals with midterm for the same subject. Average movement of at least five points is Improving or Declining; smaller movement is Stable.',
            ],
        ];
    }

    /** @param Collection<int, GradeSheet> $sheets @param Collection<int, EnrollmentSubject> $subjects */
    private function officialResults(Collection $sheets, Collection $subjects): Collection
    {
        $validSubjects = $subjects->keyBy('id');
        $results = collect();

        foreach ($sheets->sortByDesc(fn (GradeSheet $sheet) => ($sheet->published_at?->timestamp ?? 0).':'.str_pad((string) $sheet->id, 10, '0', STR_PAD_LEFT)) as $sheet) {
            $attempt = $sheet->latestSubmission;
            if (! $attempt) {
                continue;
            }
            $cohort = $attempt->students->pluck('final_grade')->filter(fn ($grade) => $grade !== null)->map(fn ($grade) => (float) $grade)->values();

            foreach ($attempt->students as $result) {
                $subject = $validSubjects->get($result->enrollment_subject_id);
                if (! $subject || $subject->enrollment->student_id !== $result->student_id || $results->has($result->enrollment_subject_id)) {
                    continue;
                }

                $results->put($result->enrollment_subject_id, [
                    'sheet' => $sheet,
                    'result' => $result,
                    'cohort' => $cohort,
                ]);
            }
        }

        return $results;
    }

    /** @param Collection<int, EnrollmentSubject> $subjects @return array<string, mixed> */
    private function assessment(Student $student, Collection $subjects, Collection $officialResults, ?array $term): array
    {
        $publishedSubjects = $subjects->map(function (EnrollmentSubject $subject) use ($officialResults): ?array {
            $official = $officialResults->get($subject->id);
            if (! $official) {
                return null;
            }

            $result = $official['result'];
            $midterm = $result->midterm_grade === null ? null : (float) $result->midterm_grade;
            $finals = $result->finals_grade === null ? null : (float) $result->finals_grade;
            $delta = $midterm !== null && $finals !== null ? round($finals - $midterm, 2) : null;
            $percentile = $this->cohortPercentile($result->final_grade, $official['cohort']);
            $bottomQuarter = $percentile !== null && $percentile <= 0.25;
            $level = match (true) {
                $delta !== null && $delta <= -self::SEVERE_DECLINE => 'high',
                $delta !== null && $delta <= -self::CONCERNING_DECLINE && $bottomQuarter => 'high',
                $delta !== null && $delta <= -self::CONCERNING_DECLINE => 'moderate',
                $bottomQuarter => 'moderate',
                default => 'stable',
            };
            $reasons = [];
            if ($delta !== null && $delta <= -self::CONCERNING_DECLINE) {
                $reasons[] = sprintf('Finals decreased by %s points from the published midterm result.', $this->number(abs($delta)));
            }
            if ($bottomQuarter) {
                $reasons[] = 'The official final result is in the lower quarter of this published class snapshot.';
            }
            if ($reasons === []) {
                $reasons[] = 'No material within-subject decline or lower-quarter signal was found in the published snapshot.';
            }

            return [
                'enrollment_subject_id' => $subject->id,
                'grade_sheet_id' => $official['sheet']->id,
                'subject_code' => $subject->subject?->subject_code ?? 'N/A',
                'subject_name' => $subject->subject?->subject_name ?? 'Subject',
                'midterm_grade' => $midterm,
                'finals_grade' => $finals,
                'final_grade' => $result->final_grade === null ? null : (float) $result->final_grade,
                'checkpoint_change' => $delta,
                'trend' => $this->trend($delta),
                'risk_level' => $level,
                'risk_label' => $this->riskLabel($level),
                'reasons' => $reasons,
                'published_at' => $official['sheet']->published_at?->toIso8601String(),
            ];
        })->filter()->values();

        $moderateCount = $publishedSubjects->where('risk_level', 'moderate')->count();
        $level = match (true) {
            $publishedSubjects->isEmpty() => 'insufficient',
            $publishedSubjects->contains('risk_level', 'high') || $moderateCount >= 2 => 'high',
            $moderateCount === 1 => 'moderate',
            default => 'stable',
        };
        $deltas = $publishedSubjects->pluck('checkpoint_change')->filter(fn ($delta) => $delta !== null);
        $averageDelta = $deltas->isEmpty() ? null : round((float) $deltas->average(), 2);
        $trend = $this->trend($averageDelta);
        $reasons = $publishedSubjects
            ->whereIn('risk_level', ['high', 'moderate'])
            ->flatMap(fn (array $subject) => collect($subject['reasons'])->map(fn (string $reason) => $subject['subject_code'].': '.$reason))
            ->values();
        if ($reasons->isEmpty()) {
            $reasons->push($publishedSubjects->isEmpty()
                ? 'No published official grade snapshot is available for the selected term.'
                : 'Published results do not currently show a material decline or repeated lower-quarter pattern.');
        }

        $expected = $subjects->count();
        $published = $publishedSubjects->count();
        $completeness = match (true) {
            $published === 0 => 'no_published_data',
            $expected > 0 && $published === $expected => 'complete',
            default => 'partial',
        };
        $name = trim(implode(' ', array_filter([
            $student->userProfile?->first_name,
            $student->userProfile?->middle_name,
            $student->userProfile?->last_name,
        ]))) ?: 'Student';
        $evaluatedAt = now()->toIso8601String();

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $name,
            'course_code' => $student->course?->course_code,
            'section' => $subjects->first()?->enrollment?->section?->section_name,
            'term' => $term,
            'risk_level' => $level,
            'risk_label' => $this->riskLabel($level),
            'trend' => $trend,
            'trend_label' => $this->trendLabel($trend),
            'headline' => $this->headline($level),
            'reasons' => $reasons->all(),
            'warnings' => $reasons->all(),
            'subjects_analyzed' => $published,
            'at_risk_subjects' => $publishedSubjects->whereIn('risk_level', ['high', 'moderate'])->count(),
            'evaluated_at' => $evaluatedAt,
            'data_completeness' => [
                'status' => $completeness,
                'label' => match ($completeness) {
                    'complete' => 'Complete published coverage',
                    'partial' => 'Partial published coverage',
                    default => 'No published data',
                },
                'published_subjects' => $published,
                'expected_subjects' => $expected,
            ],
            'subjects' => $publishedSubjects->all(),
        ];
    }

    /** @param Collection<int, float|int> $cohort */
    private function cohortPercentile(float|int|null $grade, Collection $cohort): ?float
    {
        if ($grade === null || $cohort->count() < 4) {
            return null;
        }

        $value = (float) $grade;
        $lower = $cohort->filter(fn (float $candidate) => $candidate < $value)->count();
        $equal = $cohort->filter(fn (float $candidate) => abs($candidate - $value) < 0.00001)->count();

        return ($lower + (0.5 * max(0, $equal - 1))) / max(1, $cohort->count() - 1);
    }

    /** @param Collection<int, array<string, mixed>> $terms */
    private function selectedTerm(Collection $terms, array $filters): ?array
    {
        if ($terms->isEmpty()) {
            return null;
        }

        $academicYearId = isset($filters['academic_year_id']) ? (int) $filters['academic_year_id'] : null;
        $semesterId = isset($filters['semester_id']) ? (int) $filters['semester_id'] : null;
        if ($academicYearId && $semesterId) {
            return $terms->first(fn (array $term) => $term['academic_year_id'] === $academicYearId && $term['semester_id'] === $semesterId);
        }

        return $terms->first();
    }

    /** @return array<string, mixed> */
    private function termFromEnrollmentSubject(EnrollmentSubject $subject): array
    {
        $enrollment = $subject->enrollment;

        return [
            'academic_year_id' => $enrollment->academic_year_id,
            'academic_year' => $enrollment->academicYear?->school_year ?? 'Academic year',
            'semester_id' => $enrollment->semester_id,
            'semester' => $enrollment->semester?->semester_name ?? 'Semester',
            'semester_order' => (int) ($enrollment->semester?->semester_order ?? 0),
        ];
    }

    private function trend(?float $delta): string
    {
        return match (true) {
            $delta === null => 'insufficient',
            $delta >= self::MATERIAL_CHANGE => 'improving',
            $delta <= -self::MATERIAL_CHANGE => 'declining',
            default => 'stable',
        };
    }

    private function trendLabel(string $trend): string
    {
        return match ($trend) {
            'improving' => 'Improving',
            'declining' => 'Declining',
            'stable' => 'Stable',
            default => 'Insufficient data',
        };
    }

    private function riskLabel(string $level): string
    {
        return match ($level) {
            'high' => 'High',
            'moderate' => 'Moderate',
            'stable' => 'Stable',
            default => 'Insufficient data',
        };
    }

    private function headline(string $level): string
    {
        return match ($level) {
            'high' => 'Multiple or severe published-grade signals need timely review.',
            'moderate' => 'A published-grade signal merits attention.',
            'stable' => 'Published results are currently stable under the monitoring rules.',
            default => 'There is not enough published official data to classify academic risk.',
        };
    }

    private function riskOrder(string $level): int
    {
        return match ($level) {
            'high' => 0,
            'moderate' => 1,
            'stable' => 2,
            default => 3,
        };
    }

    /** @param Collection<int, array<string, mixed>> $students @return array<string, int> */
    private function summary(Collection $students): array
    {
        return [
            'high' => $students->where('risk_level', 'high')->count(),
            'moderate' => $students->where('risk_level', 'moderate')->count(),
            'stable' => $students->where('risk_level', 'stable')->count(),
            'insufficient' => $students->where('risk_level', 'insufficient')->count(),
            'total' => $students->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyOverview(): array
    {
        return [
            'summary' => $this->summary(collect()),
            'students' => [],
            'terms' => [],
            'selected_term' => null,
            'methodology' => [
                'source' => 'Published Grade Sheets and their latest immutable submission snapshots, matched to finalized Enrollment subjects.',
                'excluded' => ['Unpublished grading work'],
                'risk_rule' => 'No assessment was available for the requested scope.',
                'trend_rule' => 'No assessment was available for the requested scope.',
            ],
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function planObjective(string $level, string $completeness): string
    {
        $coverage = $completeness === 'complete'
            ? 'using the complete published subject set for this term'
            : 'while recognizing that the published subject set is incomplete';

        return match ($level) {
            'high' => "Review the strongest published warning signals, prepare for consultation, and monitor the next official result {$coverage}.",
            'moderate' => "Target the published concern, prepare for the next assessment, and reinforce a consistent review routine {$coverage}.",
            'stable' => "Maintain a repeatable review schedule and continue checking official results {$coverage}.",
            default => 'Maintain a light review routine until enough Published grade data is available for a detailed plan.',
        };
    }
}
