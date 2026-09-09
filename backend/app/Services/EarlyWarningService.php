<?php

namespace App\Services;

use App\Models\Professor;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EarlyWarningService
{
    /** @return array{summary: array<string,int>, students: list<array<string,mixed>>} */
    public function overview(?int $professorUserId = null): array
    {
        $query = $this->studentsWithGrades();
        if ($professorUserId !== null) {
            $professorId = Professor::query()->where('user_id', $professorUserId)->value('id');
            if (! $professorId) {
                return ['summary' => $this->summary(collect()), 'students' => []];
            }
            $query->whereHas('enrollments.enrollmentSubjects', fn (Builder $q) => $q->where('professor_id', $professorId));
        }
        $students = $query->orderBy('student_number')->get()->map(fn (Student $student) => $this->assessment($student))
            ->sortByDesc(fn (array $a) => ['high' => 3, 'moderate' => 2, 'low' => 1][$a['risk_level']])->values();

        return ['summary' => $this->summary($students), 'students' => $students->all()];
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
        return Student::query()->with(['userProfile', 'course', 'enrollments.enrollmentSubjects.subject', 'enrollments.enrollmentSubjects.grades' => fn ($q) => $q->where('status', 'approved'), 'enrollments.enrollmentSubjects.grades.gradingPeriod']);
    }

    /** @return array<string,mixed> */
    private function assessment(Student $student): array
    {
        $subjects = [];
        $scores = [];
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
                $level = $record->remarks === 'Failed' || $average < 75 ? 'high' : ($record->remarks === 'Incomplete' || $average < 82 ? 'moderate' : 'low');
                $subjects[] = ['subject_code' => $record->subject?->subject_code ?? 'N/A', 'subject_name' => $record->subject?->subject_name ?? 'Subject', 'periods' => $periods, 'average_grade' => $average, 'risk_level' => $level, 'status' => $record->remarks];
            }
        }
        $average = $scores ? round(array_sum($scores) / count($scores), 2) : null;
        $prelim = collect($subjects)->pluck('periods.Prelim')->filter();
        $midterm = collect($subjects)->pluck('periods.Midterm')->filter();
        $trend = $prelim->isNotEmpty() && $midterm->isNotEmpty() && $midterm->avg() <= $prelim->avg() - 3 ? 'declining' : 'steady';
        $level = collect($subjects)->contains('risk_level', 'high') || ($average !== null && $average < 75) ? 'high' : (collect($subjects)->contains('risk_level', 'moderate') || $trend === 'declining' || ($average !== null && $average < 82) ? 'moderate' : 'low');
        $name = trim(implode(' ', array_filter([$student->userProfile?->first_name, $student->userProfile?->last_name]))) ?: 'Student';
        $warnings = collect($subjects)->whereIn('risk_level', ['high', 'moderate'])->map(fn ($s) => "{$s['subject_code']} needs attention (average {$s['average_grade']}).")->values()->all();

        return ['student_id' => $student->id, 'student_number' => $student->student_number, 'student_name' => $name, 'course_code' => $student->course?->course_code, 'average_grade' => $average, 'risk_level' => $level, 'risk_label' => ucfirst($level).' risk', 'trend' => $trend, 'headline' => $level === 'low' ? 'Grades are currently stable.' : 'Early warning: academic intervention is recommended.', 'warnings' => $warnings ?: ['No critical early-warning signals right now.'], 'subjects' => $subjects];
    }

    /** @param Collection<int,array<string,mixed>> $students @return array<string,int> */
    private function summary(Collection $students): array
    {
        return ['high' => $students->where('risk_level', 'high')->count(), 'moderate' => $students->where('risk_level', 'moderate')->count(), 'low' => $students->where('risk_level', 'low')->count(), 'total' => $students->count()];
    }
}
