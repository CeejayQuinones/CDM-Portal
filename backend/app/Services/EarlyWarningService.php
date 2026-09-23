<?php

namespace App\Services;

use App\Models\Professor;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EarlyWarningService
{
    /** @return array{summary: array<string,int>, students: list<array<string,mixed>>, filters?: array<string,mixed>} */
    public function overview(?int $professorUserId = null, array $filters = []): array
    {
        $query = $professorUserId === null ? $this->studentDetails() : $this->studentsWithGrades();
        if ($professorUserId !== null) {
            $professorId = Professor::query()->where('user_id', $professorUserId)->value('id');
            if (! $professorId) {
                return ['summary' => $this->summary(collect()), 'students' => [], 'filters' => $this->filterOptions(collect())];
            }
            $query->whereHas('enrollments.enrollmentSubjects', fn (Builder $q) => $q->where('professor_id', $professorId));
        }

        if ($department = trim((string) ($filters['department'] ?? ''))) {
            $query->whereHas('course.department', fn (Builder $q) => $q->where('department_code', $department)->orWhere('department_name', 'like', "%{$department}%"));
        }
        if ($course = trim((string) ($filters['course'] ?? ''))) {
            $query->whereHas('course', fn (Builder $q) => $q->where('course_code', $course)->orWhere('course_name', 'like', "%{$course}%"));
        }
        if ($section = trim((string) ($filters['section'] ?? ''))) {
            $query->whereHas('enrollments.section', fn (Builder $q) => $q->where('section_name', $section)->orWhere('section_name', 'like', "%{$section}%"));
        }

        $students = $query->orderBy('student_number')->get()->map(fn (Student $student) => $this->assessment($student))
            ->sortByDesc(fn (array $a) => ['high' => 3, 'moderate' => 2, 'low' => 1][$a['risk_level']])->values();

        return [
            'summary' => $this->summary($students),
            'students' => $students->all(),
            'filters' => $this->filterOptions($students),
        ];
    }

    /** @return array{summary: array<string,int>, students: list<array<string,mixed>>} */
    public function assessForUserId(int $userId): array
    {
        $student = $this->studentsWithGrades()->where('user_id', $userId)->first() ?: $this->studentDetails()->where('user_id', $userId)->first();
        if (! $student) {
            return ['summary' => $this->summary(collect()), 'students' => []];
        }
        $assessment = $this->assessment($student);

        return ['summary' => $this->summary(collect([$assessment])), 'students' => [$assessment]];
    }

    /** @return array<string,mixed>|null */
    public function assessByStudentId(int $studentId): ?array
    {
        $student = $this->studentsWithGrades()->find($studentId) ?: $this->studentDetails()->find($studentId);

        return $student ? $this->assessment($student) : null;
    }

    public function professorCanAccessStudent(int $professorUserId, int $studentId): bool
    {
        $professorId = Professor::query()->where('user_id', $professorUserId)->value('id');

        return $professorId && Student::query()->whereKey($studentId)->whereHas('enrollments.enrollmentSubjects', fn (Builder $q) => $q->where('professor_id', $professorId))->exists();
    }

    /** @param array<string,mixed> $assessment @return array{summary:string,actions:list<string>,prevention_note:string} */
    public function generateSupportPlan(array $assessment): array
    {
        $focus = collect($assessment['subjects'])->whereIn('risk_level', ['high', 'moderate'])->pluck('subject_code')->filter()->implode(', ');
        if ($focus === '') {
            return ['summary' => 'Grades look stable. Keep current study habits.', 'actions' => ['Review released grades weekly.', 'Ask for help early when a score drops.'], 'prevention_note' => 'Consistent study habits protect current progress.'];
        }

        return ['summary' => ucfirst((string) $assessment['risk_level']).' academic risk needs focused support.', 'actions' => ["Prioritize unfinished work in {$focus}.", 'Prepare specific questions for your next consultation.', 'Review progress after the next graded activity.'], 'prevention_note' => 'Early, focused action can prevent a failing or incomplete result.'];
    }

    /** @param array<string,mixed> $assessment @return array<string,mixed> */
    public function generateStudyPlan(array $assessment): array
    {
        $subjects = collect($assessment['subjects'])->whereIn('risk_level', ['high', 'moderate'])->values();
        if ($subjects->isEmpty()) {
            $subjects = collect($assessment['subjects'])->take(2)->values();
        }
        $week = collect(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])->map(function (string $day, int $i) use ($subjects): array {
            $subject = $subjects[$i % max(1, $subjects->count())] ?? [];

            return ['day' => $day, 'subject_code' => $subject['subject_code'] ?? 'General', 'subject_name' => $subject['subject_name'] ?? 'Academic review', 'duration_minutes' => ($subject['risk_level'] ?? '') === 'high' ? 90 : 60, 'focus' => 'Review weak topics and complete the next required activity.'];
        })->all();

        return ['student_id' => $assessment['student_id'], 'risk_level' => $assessment['risk_level'], 'focus_subjects' => $subjects->map(fn ($s) => ['subject_code' => $s['subject_code'], 'subject_name' => $s['subject_name'], 'risk_level' => $s['risk_level'], 'average_grade' => $s['average_grade']])->all(), 'week' => $week, 'generated_at' => now()->toIso8601String()];
    }

    /** @return array{summary:array<string,int>,alerts:list<array<string,mixed>>} */
    public function adviserAlerts(?int $professorUserId = null): array
    {
        $students = $this->overview($professorUserId)['students'];
        $alerts = collect($students)->filter(fn ($s) => $s['risk_level'] !== 'low' || $s['trend'] === 'declining')->map(fn ($s) => ['id' => 'alert-'.$s['student_id'], 'student_id' => $s['student_id'], 'severity' => $s['risk_level'] === 'high' ? 'urgent' : 'attention', 'risk_level' => $s['risk_level'], 'headline' => $s['headline'], 'warnings' => $s['warnings']])->values();

        return ['summary' => ['urgent' => $alerts->where('severity', 'urgent')->count(), 'attention' => $alerts->where('severity', 'attention')->count(), 'total' => $alerts->count()], 'alerts' => $alerts->all()];
    }

    private function studentsWithGrades(): Builder
    {
        return $this->studentDetails()->whereHas('enrollments.enrollmentSubjects.grades', fn (Builder $q) => $q->where('status', 'approved'));
    }

    private function studentDetails(): Builder
    {
        return Student::query()->with([
            'userProfile',
            'course.department',
            'enrollments.section',
            'enrollments.enrollmentSubjects.subject',
            'enrollments.enrollmentSubjects.grades' => fn ($q) => $q->where('status', 'approved'),
            'enrollments.enrollmentSubjects.grades.gradingPeriod',
            'performanceRecords',
        ]);
    }

    /**
     * Combine signal components into a 0–100 risk score.
     *
     * @param  array{grade_risk: float|int, trend_drop: float|int, weak_quizzes: float|int, incomplete: float|int, force_high?: bool, force_moderate?: bool}  $parts
     * @return array{risk_score: int, risk_level: string, risk_label: string}
     */
    public function composeRisk(array $parts): array
    {
        $weights = config('monitoring.weights');
        $raw = ((float) $parts['grade_risk'] * (float) $weights['grade'])
            + ((float) $parts['trend_drop'] * (float) $weights['trend'])
            + ((float) $parts['weak_quizzes'] * (float) $weights['quizzes'])
            + ((float) $parts['incomplete'] * (float) $weights['incomplete']);

        $score = (int) round(max(0, min(100, $raw)));
        $high = (int) config('monitoring.bands.high', 70);
        $moderate = (int) config('monitoring.bands.moderate', 40);

        if (! empty($parts['force_high'])) {
            $score = max($score, $high);
        } elseif (! empty($parts['force_moderate'])) {
            $score = max($score, $moderate);
        }

        $level = $score >= $high ? 'high' : ($score >= $moderate ? 'moderate' : 'low');

        return [
            'risk_score' => $score,
            'risk_level' => $level,
            'risk_label' => ucfirst($level).' risk',
        ];
    }

    /** @return array<string,mixed> */
    private function assessment(Student $student): array
    {
        $subjects = [];
        $scores = [];
        $latestEnrollment = $student->enrollments->sortByDesc('enrollment_date')->first();
        foreach ($student->enrollments as $enrollment) {
            foreach ($enrollment->enrollmentSubjects as $record) {
                $periods = ['Prelim' => null, 'Midterm' => null, 'Final' => null];
                foreach ($record->grades as $grade) {
                    if (isset($periods[$grade->gradingPeriod?->period_name]) && $grade->grade !== null) {
                        $periods[$grade->gradingPeriod->period_name] = (float) $grade->grade;
                        $scores[] = (float) $grade->grade;
                    }
                }
                $values = array_values(array_filter($periods, fn ($v) => $v !== null));
                if (! $values) {
                    continue;
                }
                $average = round(array_sum($values) / count($values), 2);
                $level = $record->remarks === 'Failed' || $average < (float) config('monitoring.failing_average', 75)
                    ? 'high'
                    : ($record->remarks === 'Incomplete' || $average < (float) config('monitoring.watch_average', 82) ? 'moderate' : 'low');
                $prelimGrade = $periods['Prelim'];
                $midtermGrade = $periods['Midterm'];
                $subjectTrend = ($prelimGrade !== null && $midtermGrade !== null && $midtermGrade <= $prelimGrade - (float) config('monitoring.decline_points', 3))
                    ? 'declining'
                    : 'steady';
                if ($subjectTrend === 'declining' && $level === 'low') {
                    $level = 'moderate';
                }
                $subjects[] = [
                    'subject_code' => $record->subject?->subject_code ?? 'N/A',
                    'subject_name' => $record->subject?->subject_name ?? 'Subject',
                    'periods' => $periods,
                    'average_grade' => $average,
                    'risk_level' => $level,
                    'risk_label' => ucfirst($level).' risk',
                    'status' => $record->remarks,
                    'trend' => $subjectTrend,
                    'missing_midterm' => $prelimGrade !== null && $midtermGrade === null,
                ];
            }
        }
        $average = $scores ? round(array_sum($scores) / count($scores), 2) : null;
        $prelim = collect($subjects)->pluck('periods.Prelim')->filter();
        $midterm = collect($subjects)->pluck('periods.Midterm')->filter();
        $trend = $prelim->isNotEmpty() && $midterm->isNotEmpty() && $midterm->avg() <= $prelim->avg() - (float) config('monitoring.decline_points', 3) ? 'declining' : 'steady';

        $quizPercents = $student->performanceRecords
            ->filter(fn ($record) => (float) $record->max_score > 0)
            ->map(fn ($record) => ((float) $record->score / (float) $record->max_score) * 100)
            ->values();
        $weakLine = (float) config('monitoring.quiz_weak_percent', 75);
        $criticalLine = (float) config('monitoring.quiz_critical_percent', 60);
        $weakQuizCount = $quizPercents->filter(fn (float $percent) => $percent < $weakLine)->count();
        $weakQuizzes = $quizPercents->isNotEmpty() ? ($weakQuizCount / $quizPercents->count()) * 100 : 0;
        if ($quizPercents->contains(fn (float $percent) => $percent < $criticalLine)) {
            $weakQuizzes = max($weakQuizzes, 80);
        }

        $missingMidterms = collect($subjects)->where('missing_midterm', true)->count();
        $subjectDeclines = collect($subjects)->where('trend', 'declining')->count();
        $failedOrIncomplete = collect($subjects)->filter(fn (array $subject) => in_array($subject['status'], ['Failed', 'Incomplete'], true))->count();
        $subjectCount = max(1, count($subjects));

        $failing = (float) config('monitoring.failing_average', 75);
        $watch = (float) config('monitoring.watch_average', 82);
        if ($average === null) {
            $gradeRisk = $quizPercents->isNotEmpty() ? min(60, $weakQuizzes * 0.6) : 0;
        } elseif ($average < $failing) {
            $gradeRisk = min(100, 85 + ($failing - $average));
        } elseif ($average < $watch) {
            $gradeRisk = 45 + (($watch - $average) / max(1, $watch - $failing)) * 35;
        } else {
            $gradeRisk = max(0, (90 - $average) * 2);
        }
        if (collect($subjects)->contains(fn (array $subject) => $subject['status'] === 'Failed' || $subject['average_grade'] < $failing)) {
            $gradeRisk = max($gradeRisk, 90);
        }

        $trendDrop = $trend === 'declining' ? 70 : 0;
        $trendDrop = min(100, $trendDrop + ($subjectDeclines * 15) + min(40, $missingMidterms * 20));

        $incomplete = ($failedOrIncomplete / $subjectCount) * 100;
        if ($missingMidterms > 0) {
            $incomplete = min(100, $incomplete + min(30, $missingMidterms * 15));
        }

        $forceHigh = collect($subjects)->contains('risk_level', 'high') || ($average !== null && $average < $failing);
        $forceModerate = ! $forceHigh && (
            collect($subjects)->contains('risk_level', 'moderate')
            || $trend === 'declining'
            || ($average !== null && $average < $watch)
            || $weakQuizCount > 0
            || $missingMidterms > 0
        );

        $composed = $this->composeRisk([
            'grade_risk' => $gradeRisk,
            'trend_drop' => $trendDrop,
            'weak_quizzes' => $weakQuizzes,
            'incomplete' => $incomplete,
            'force_high' => $forceHigh,
            'force_moderate' => $forceModerate,
        ]);

        $name = trim(implode(' ', array_filter([$student->userProfile?->first_name, $student->userProfile?->last_name]))) ?: 'Student';
        $warnings = collect($subjects)->whereIn('risk_level', ['high', 'moderate'])->map(fn ($s) => "{$s['subject_code']} needs attention (average {$s['average_grade']}".($s['trend'] === 'declining' ? ', declining' : '').').')->values();
        if ($weakQuizCount > 0) {
            $warnings->push("{$weakQuizCount} quiz/topic record".($weakQuizCount === 1 ? '' : 's')." below {$weakLine}%.");
        }
        if ($missingMidterms > 0) {
            $warnings->push("{$missingMidterms} subject".($missingMidterms === 1 ? '' : 's').' still missing a midterm after prelim.');
        }
        if ($subjectDeclines > 0) {
            $warnings->push("{$subjectDeclines} subject".($subjectDeclines === 1 ? '' : 's').' dropped from prelim to midterm.');
        }

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $name,
            'course_code' => $student->course?->course_code,
            'course_name' => $student->course?->course_name,
            'department_code' => $student->course?->department?->department_code,
            'department_name' => $student->course?->department?->department_name,
            'section_name' => $latestEnrollment?->section?->section_name,
            'year_level' => $student->year_level ?? $latestEnrollment?->section?->year_level,
            'average_grade' => $average,
            'risk_score' => $composed['risk_score'],
            'risk_level' => $composed['risk_level'],
            'risk_label' => $composed['risk_label'],
            'signals' => [
                'grade_risk' => (int) round($gradeRisk),
                'trend_drop' => (int) round($trendDrop),
                'weak_quizzes' => (int) round($weakQuizzes),
                'incomplete' => (int) round($incomplete),
                'weak_quiz_count' => $weakQuizCount,
                'missing_midterms' => $missingMidterms,
                'declining_subjects' => $subjectDeclines,
            ],
            'trend' => $trend,
            'trend_label' => match ($trend) {
                'declining' => 'declining',
                default => 'steady',
            },
            'headline' => $composed['risk_level'] === 'low'
                ? 'Grades are currently stable.'
                : "Early warning: risk score {$composed['risk_score']}/100 — intervention is recommended.",
            'warnings' => $warnings->isNotEmpty() ? $warnings->all() : ['No critical early-warning signals right now.'],
            'subjects' => $subjects,
        ];
    }

    /** @param Collection<int,array<string,mixed>> $students @return array<string,list<string>> */
    private function filterOptions(Collection $students): array
    {
        return [
            'departments' => $students->pluck('department_name')->filter()->unique()->sort()->values()->all(),
            'courses' => $students->map(fn ($s) => $s['course_code'] ?: null)->filter()->unique()->sort()->values()->all(),
            'sections' => $students->pluck('section_name')->filter()->unique()->sort()->values()->all(),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $students @return array<string,int> */
    private function summary(Collection $students): array
    {
        return ['high' => $students->where('risk_level', 'high')->count(), 'moderate' => $students->where('risk_level', 'moderate')->count(), 'low' => $students->where('risk_level', 'low')->count(), 'total' => $students->count()];
    }
}
