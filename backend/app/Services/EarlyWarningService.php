<?php

namespace App\Services;

use App\Models\Professor;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EarlyWarningService
{
    /**
     * Build early-warning overview for students with approved grade records.
     *
     * @return array{summary: array<string, int>, students: list<array<string, mixed>>}
     */
    public function overview(?int $professorUserId = null): array
    {
        $query = $this->baseStudentQuery();

        if ($professorUserId !== null) {
            $professorId = Professor::query()->where('user_id', $professorUserId)->value('id');

            if (! $professorId) {
                return [
                    'summary' => ['high' => 0, 'moderate' => 0, 'low' => 0, 'total' => 0],
                    'students' => [],
                ];
            }

            $query->whereHas('enrollments.enrollmentSubjects', function (Builder $builder) use ($professorId): void {
                $builder->where('professor_id', $professorId);
            });
        }

        $students = $query
            ->orderBy('student_number')
            ->get()
            ->map(function (Student $student) {
                $assessment = $this->assessStudent($student);
                $assessment['support_plan'] = $this->generateSupportPlan($assessment);

                return $assessment;
            })
            ->sortByDesc(fn (array $item) => ['high' => 3, 'moderate' => 2, 'low' => 1][$item['risk_level']] ?? 0)
            ->values();

        return [
            'summary' => $this->summarize($students),
            'students' => $students->all(),
        ];
    }

    /**
     * Assess a single student and include a generated support plan.
     *
     * @return array{summary: array<string, int>, students: list<array<string, mixed>>}
     */
    public function assessForUserId(int $userId): array
    {
        $student = $this->baseStudentQuery()
            ->where('user_id', $userId)
            ->first();

        if (! $student) {
            $student = Student::query()
                ->with(['userProfile', 'course', 'enrollments.enrollmentSubjects.subject', 'enrollments.enrollmentSubjects.grades.gradingPeriod'])
                ->where('user_id', $userId)
                ->first();
        }

        if (! $student) {
            return [
                'summary' => ['high' => 0, 'moderate' => 0, 'low' => 0, 'total' => 0],
                'students' => [],
            ];
        }

        $assessment = $this->assessStudent($student);
        $assessment['support_plan'] = $this->generateSupportPlan($assessment);

        return [
            'summary' => $this->summarize(collect([$assessment])),
            'students' => [$assessment],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function assessByStudentId(int $studentId): ?array
    {
        $student = $this->baseStudentQuery()->find($studentId)
            ?? Student::query()
                ->with(['userProfile', 'course', 'enrollments.enrollmentSubjects.subject', 'enrollments.enrollmentSubjects.grades.gradingPeriod'])
                ->find($studentId);

        return $student ? $this->assessStudent($student) : null;
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array{summary: string, actions: list<string>, prevention_note: string}
     */
    public function generateSupportPlan(array $assessment): array
    {
        $actions = [];
        $riskLevel = $assessment['risk_level'];
        $atRiskSubjects = collect($assessment['subjects'])
            ->filter(fn (array $subject) => in_array($subject['risk_level'], ['high', 'moderate'], true))
            ->values();

        if ($atRiskSubjects->isEmpty()) {
            return [
                'summary' => 'Grades look stable. Keep current study habits and check AI Monitoring after each grading period.',
                'actions' => [
                    'Review released grades weekly inside the portal.',
                    'Join at least one peer study session for your heaviest subject.',
                    'Message your professor early if a quiz or project score drops.',
                ],
                'prevention_note' => 'Staying consistent now prevents sudden risk of failing later in the term.',
            ];
        }

        foreach ($atRiskSubjects as $subject) {
            $code = $subject['subject_code'];
            if ($subject['risk_level'] === 'high') {
                $actions[] = "Book a consultation for {$code} this week and prioritize unfinished requirements.";
                $actions[] = "Create a 5-day recovery checklist for {$code} covering quizzes, labs, and missed topics.";
            } else {
                $actions[] = "Raise {$code} by focusing on the next graded activity and attending open consultation hours.";
            }
        }

        if (($assessment['trend'] ?? '') === 'declining') {
            $actions[] = 'Stop the declining trend: compare prelim vs midterm mistakes and rewrite notes for weak topics.';
        }

        if ($riskLevel === 'high') {
            $actions[] = 'Inform your adviser/registrar early so intervention can happen before finals.';
            $summary = 'High risk of failing detected. Immediate academic recovery actions are recommended.';
            $note = 'Acting on this plan now is the best way to prevent a failed or incomplete mark.';
        } else {
            $actions[] = 'Set a mid-week progress check so moderate risk does not become high risk.';
            $summary = 'Moderate academic risk detected. Targeted support can still prevent failing outcomes.';
            $note = 'Small weekly improvements in at-risk subjects reduce the chance of failing.';
        }

        return [
            'summary' => $summary,
            'actions' => array_values(array_unique($actions)),
            'prevention_note' => $note,
        ];
    }

    /**
     * Build a weekly guided study plan from declining / at-risk subjects.
     *
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    public function generateStudyPlan(array $assessment): array
    {
        $focusSubjects = collect($assessment['subjects'] ?? [])
            ->filter(function (array $subject) {
                if (in_array($subject['risk_level'], ['high', 'moderate'], true)) {
                    return true;
                }

                $prelim = $subject['periods']['Prelim'] ?? null;
                $midterm = $subject['periods']['Midterm'] ?? null;

                return $prelim !== null && $midterm !== null && ((float) $midterm < (float) $prelim - 2);
            })
            ->values();

        if ($focusSubjects->isEmpty()) {
            $focusSubjects = collect($assessment['subjects'] ?? [])->take(2)->values();
        }

        $sessions = [
            ['day' => 'Monday', 'time' => '19:00–20:30', 'type' => 'Concept Review', 'focus' => 'Revisit lecture notes and mark unclear topics for targeted review.'],
            ['day' => 'Tuesday', 'time' => '19:00–20:00', 'type' => 'Practice Drill', 'focus' => 'Solve practice problems and past quiz items under timed conditions.'],
            ['day' => 'Wednesday', 'time' => '18:30–20:00', 'type' => 'Deep Work', 'focus' => 'Rebuild weak subtopics with examples, diagrams, or worked solutions.'],
            ['day' => 'Thursday', 'time' => '19:00–20:00', 'type' => 'Lab / Output', 'focus' => 'Complete unfinished lab, coding, or assignment requirements.'],
            ['day' => 'Friday', 'time' => '19:00–20:00', 'type' => 'Self-Assessment', 'focus' => 'Self-quiz and log mistakes with corrected solutions in a notebook.'],
            ['day' => 'Saturday', 'time' => '09:00–10:30', 'type' => 'Consultation Prep', 'focus' => 'Prepare 3 clarifying questions and review with a peer or adviser.'],
            ['day' => 'Sunday', 'time' => '16:00–17:00', 'type' => 'Weekly Review', 'focus' => 'Reflect on progress, adjust next week priorities, and rest strategically.'],
        ];

        $week = [];
        $totalMinutes = 0;
        foreach ($sessions as $i => $session) {
            $subject = $focusSubjects[$i % max(1, $focusSubjects->count())] ?? null;
            $priority = $subject['risk_level'] ?? 'low';
            $minutes = $priority === 'high' ? 90 : ($priority === 'moderate' ? 75 : 60);
            $totalMinutes += $minutes;

            $week[] = [
                'day' => $session['day'],
                'time_slot' => $session['time'],
                'session_type' => $session['type'],
                'subject_code' => $subject['subject_code'] ?? 'General',
                'subject_name' => $subject['subject_name'] ?? 'Academic recovery block',
                'focus' => $session['focus'],
                'objective' => $priority === 'high'
                    ? 'Stabilize failing-risk performance before the next graded activity.'
                    : 'Improve consistency and close topic gaps.',
                'duration_minutes' => $minutes,
                'priority' => $priority,
            ];
        }

        $focusCodes = $focusSubjects->pluck('subject_code')->filter()->implode(', ');

        return [
            'student_id' => $assessment['student_id'],
            'student_name' => $assessment['student_name'],
            'student_number' => $assessment['student_number'],
            'course_code' => $assessment['course_code'] ?? null,
            'risk_level' => $assessment['risk_level'],
            'risk_label' => $assessment['risk_label'] ?? $assessment['risk_level'],
            'week_label' => 'Academic Recovery Week · '.now()->format('M d').'–'.now()->addDays(6)->format('M d, Y'),
            'headline' => $focusSubjects->isEmpty()
                ? 'Maintenance study plan designed to keep academic performance stable.'
                : "Professional recovery plan focused on {$focusCodes}.",
            'objective' => 'Reduce risk of failing by prioritizing weak subjects, protecting study consistency, and preparing for the next graded assessments.',
            'total_hours' => round($totalMinutes / 60, 1),
            'session_count' => count($week),
            'focus_subjects' => $focusSubjects->map(fn (array $subject) => [
                'subject_code' => $subject['subject_code'],
                'subject_name' => $subject['subject_name'],
                'risk_level' => $subject['risk_level'],
                'risk_label' => $subject['risk_label'] ?? $subject['risk_level'],
                'average_grade' => $subject['average_grade'],
            ])->all(),
            'week' => $week,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{summary: array<string, int>, plans: list<array<string, mixed>>}
     */
    public function studyPlansOverview(?int $professorUserId = null): array
    {
        $overview = $this->overview($professorUserId);
        $plans = collect($overview['students'])
            ->map(fn (array $student) => $this->generateStudyPlan($student))
            ->values()
            ->all();

        return [
            'summary' => [
                'total' => count($plans),
                'with_focus_subjects' => collect($plans)->filter(fn (array $plan) => count($plan['focus_subjects']) > 0)->count(),
            ],
            'plans' => $plans,
        ];
    }

    /**
     * Build adviser alert inbox items from early-warning assessments.
     *
     * @return array{summary: array<string, int>, alerts: list<array<string, mixed>>}
     */
    public function adviserAlerts(?int $professorUserId = null): array
    {
        $overview = $this->overview($professorUserId);

        $alerts = collect($overview['students'])
            ->filter(fn (array $student) => in_array($student['risk_level'], ['high', 'moderate'], true)
                || ($student['trend'] ?? '') === 'declining')
            ->map(function (array $student) {
                $severity = $student['risk_level'] === 'high' ? 'urgent' : 'attention';
                $title = $student['risk_level'] === 'high'
                    ? "High risk: {$student['student_name']}"
                    : "Watch list: {$student['student_name']}";

                return [
                    'id' => 'alert-'.$student['student_id'],
                    'student_id' => $student['student_id'],
                    'student_name' => $student['student_name'],
                    'student_number' => $student['student_number'],
                    'course_code' => $student['course_code'],
                    'severity' => $severity,
                    'risk_level' => $student['risk_level'],
                    'risk_label' => $student['risk_label'],
                    'title' => $title,
                    'message' => $student['headline'],
                    'warnings' => $student['warnings'],
                    'trend_label' => $student['trend_label'],
                    'average_grade' => $student['average_grade'],
                    'at_risk_subjects' => $student['at_risk_subjects'],
                    'created_at' => now()->toIso8601String(),
                ];
            })
            ->sortBy(fn (array $alert) => $alert['severity'] === 'urgent' ? 0 : 1)
            ->values();

        return [
            'summary' => [
                'urgent' => $alerts->where('severity', 'urgent')->count(),
                'attention' => $alerts->where('severity', 'attention')->count(),
                'total' => $alerts->count(),
            ],
            'alerts' => $alerts->all(),
        ];
    }

    private function baseStudentQuery(): Builder
    {
        return Student::query()
            ->with([
                'userProfile',
                'course',
                'enrollments.enrollmentSubjects.subject',
                'enrollments.enrollmentSubjects.grades' => fn ($query) => $query->where('status', 'approved'),
                'enrollments.enrollmentSubjects.grades.gradingPeriod',
            ])
            ->whereHas('enrollments.enrollmentSubjects.grades', fn (Builder $query) => $query->where('status', 'approved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function assessStudent(Student $student): array
    {
        $subjects = [];
        $allScores = [];

        foreach ($student->enrollments as $enrollment) {
            foreach ($enrollment->enrollmentSubjects as $enrollmentSubject) {
                $periods = [
                    'Prelim' => null,
                    'Midterm' => null,
                    'Final' => null,
                ];

                foreach ($enrollmentSubject->grades as $grade) {
                    if ($grade->status !== null && $grade->status !== 'approved') {
                        continue;
                    }

                    $periodName = $grade->gradingPeriod?->period_name;
                    if ($periodName && array_key_exists($periodName, $periods)) {
                        $periods[$periodName] = $grade->grade;
                        if ($grade->grade !== null) {
                            $allScores[] = (float) $grade->grade;
                        }
                    }
                }

                $subjectScores = array_values(array_filter($periods, fn ($score) => $score !== null));
                if ($subjectScores === []) {
                    continue;
                }

                $subjectAverage = round(array_sum($subjectScores) / count($subjectScores), 2);
                $subjectRisk = $this->scoreRisk($subjectAverage, $subjectScores, $enrollmentSubject->remarks);

                $subjects[] = [
                    'subject_code' => $enrollmentSubject->subject?->subject_code ?? 'N/A',
                    'subject_name' => $enrollmentSubject->subject?->subject_name ?? 'Subject',
                    'periods' => $periods,
                    'average_grade' => $subjectAverage,
                    'risk_level' => $subjectRisk['level'],
                    'risk_label' => $subjectRisk['label'],
                    'remarks' => $enrollmentSubject->remarks,
                ];
            }
        }

        $average = count($allScores) ? round(array_sum($allScores) / count($allScores), 2) : null;
        $trend = $this->detectTrend($subjects);
        $overall = $this->scoreRisk($average, $allScores, null, $trend, $subjects);
        $warnings = $this->buildWarnings($subjects, $average, $trend);

        $profile = $student->userProfile;
        $name = trim(implode(' ', array_filter([
            $profile?->first_name,
            $profile?->middle_name,
            $profile?->last_name,
        ]))) ?: 'Student';

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $name,
            'course_code' => $student->course?->course_code,
            'average_grade' => $average,
            'at_risk_subjects' => collect($subjects)->whereIn('risk_level', ['high', 'moderate'])->count(),
            'risk_level' => $overall['level'],
            'risk_label' => $overall['label'],
            'trend' => $trend,
            'trend_label' => match ($trend) {
                'declining' => 'Declining',
                'improving' => 'Improving',
                default => 'Steady',
            },
            'headline' => $overall['headline'],
            'warnings' => $warnings,
            'subjects' => $subjects,
            'support_plan' => null,
        ];
    }

    /**
     * @param  list<float|int>  $scores
     * @param  list<array<string, mixed>>|null  $subjects
     * @return array{level: string, label: string, headline: string}
     */
    private function scoreRisk(?float $average, array $scores, ?string $remarks = null, string $trend = 'steady', ?array $subjects = null): array
    {
        $highSubjects = collect($subjects ?? [])->where('risk_level', 'high')->count();
        $moderateSubjects = collect($subjects ?? [])->where('risk_level', 'moderate')->count();

        if ($remarks === 'Failed' || ($average !== null && $average < 75) || $highSubjects >= 1) {
            return [
                'level' => 'high',
                'label' => 'High risk',
                'headline' => 'Early warning: elevated risk of failing one or more subjects.',
            ];
        }

        if (
            ($average !== null && $average < 82)
            || $moderateSubjects >= 1
            || $trend === 'declining'
            || $remarks === 'Incomplete'
        ) {
            return [
                'level' => 'moderate',
                'label' => 'Moderate risk',
                'headline' => 'Watch closely: grades show early signs that may lead to academic trouble.',
            ];
        }

        return [
            'level' => 'low',
            'label' => 'Stable',
            'headline' => 'Grades are currently stable with no strong failing signals.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $subjects
     */
    private function detectTrend(array $subjects): string
    {
        $prelim = [];
        $midterm = [];

        foreach ($subjects as $subject) {
            if ($subject['periods']['Prelim'] !== null) {
                $prelim[] = (float) $subject['periods']['Prelim'];
            }
            if ($subject['periods']['Midterm'] !== null) {
                $midterm[] = (float) $subject['periods']['Midterm'];
            }
        }

        if (! $prelim || ! $midterm) {
            return 'steady';
        }

        $delta = (array_sum($midterm) / count($midterm)) - (array_sum($prelim) / count($prelim));

        if ($delta <= -3) {
            return 'declining';
        }

        if ($delta >= 3) {
            return 'improving';
        }

        return 'steady';
    }

    /**
     * @param  list<array<string, mixed>>  $subjects
     * @return list<string>
     */
    private function buildWarnings(array $subjects, ?float $average, string $trend): array
    {
        $warnings = [];

        foreach ($subjects as $subject) {
            if ($subject['risk_level'] === 'high') {
                $warnings[] = "{$subject['subject_code']} is in the failing zone (avg {$subject['average_grade']}).";
            } elseif ($subject['risk_level'] === 'moderate') {
                $warnings[] = "{$subject['subject_code']} is approaching risk (avg {$subject['average_grade']}).";
            }
        }

        if ($trend === 'declining') {
            $warnings[] = 'Overall grade trend is declining from prelim to midterm.';
        }

        if ($average !== null && $average < 75) {
            $warnings[] = "Term average {$average} is below the passing threshold.";
        }

        if (! $warnings) {
            $warnings[] = 'No critical early-warning signals right now. Keep monitoring after each grade release.';
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $students
     * @return array{high: int, moderate: int, low: int, total: int}
     */
    private function summarize(Collection $students): array
    {
        return [
            'high' => $students->where('risk_level', 'high')->count(),
            'moderate' => $students->where('risk_level', 'moderate')->count(),
            'low' => $students->where('risk_level', 'low')->count(),
            'total' => $students->count(),
        ];
    }
}
