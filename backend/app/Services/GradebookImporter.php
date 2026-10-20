<?php

namespace App\Services;

use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\GradingPeriod;
use App\Models\MonitoringPerformanceRecord;
use App\Models\Professor;
use App\Support\SimpleXlsx;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GradebookImporter
{
    /**
     * @return array{saved: int, submitted: int, skipped: list<string>}
     */
    public function import(int $professorUserId, string $path, ?string $fallbackSubject): array
    {
        $rows = SimpleXlsx::read($path);
        $headerIndex = $this->headerIndex($rows);
        if ($headerIndex === null) {
            throw new RuntimeException('The spreadsheet needs a header row with a Student number column.');
        }

        $header = $rows[$headerIndex];
        $columns = $this->columns($header);
        if ($columns['student'] === null) {
            throw new RuntimeException('Add a Student number column so each row can be matched to a student.');
        }
        if ($columns['midterm'] === null && $columns['final'] === null && $columns['class_scores'] === null && $columns['scores'] === []) {
            throw new RuntimeException('This spreadsheet does not look like a class gradebook. Include Student number plus Quiz, Activity, Midterm, or Final columns.');
        }

        $assignments = $this->assignments($professorUserId);
        if ($assignments === []) {
            throw new RuntimeException('You have no assigned students to import grades for.');
        }

        $saved = 0;
        $submitted = 0;
        $skipped = [];
        $periods = GradingPeriod::query()->whereIn('period_name', ['Midterm', 'Final'])->get()->keyBy('period_name');

        DB::transaction(function () use ($rows, $header, $headerIndex, $columns, $assignments, $fallbackSubject, $professorUserId, $periods, &$saved, &$submitted, &$skipped): void {
            foreach (array_slice($rows, $headerIndex + 1) as $row) {
                if ($this->blank($row)) {
                    continue;
                }

                $studentNumber = trim((string) ($row[$columns['student']] ?? ''));
                if ($studentNumber === '') {
                    $this->note($skipped, 'A row has no student number, so it was skipped.');
                    continue;
                }

                $subjectCode = $columns['subject'] !== null
                    ? trim((string) ($row[$columns['subject']] ?? ''))
                    : '';
                if ($subjectCode === '') {
                    $subjectCode = trim((string) $fallbackSubject);
                }
                $assignment = $assignments[$this->pair($studentNumber, $subjectCode)] ?? null;
                if (! $assignment) {
                    $this->note($skipped, $subjectCode === ''
                        ? "{$studentNumber} needs a subject code."
                        : "{$studentNumber} is not in your {$subjectCode} class.");
                    continue;
                }

                foreach ($this->scores($columns, $header, $row) as $name => $score) {
                    $existing = MonitoringPerformanceRecord::query()
                        ->where('student_id', $assignment['student_id'])
                        ->where('professor_user_id', $professorUserId)
                        ->whereRaw('upper(subject_code) = ?', [strtoupper($assignment['subject_code'])])
                        ->whereRaw('lower(assessment_name) = ?', [strtolower($name)])
                        ->first();
                    if ($existing && (float) $existing->score === $score['score'] && (float) $existing->max_score === $score['max']) {
                        continue;
                    }
                    if ($existing) {
                        $existing->update(['score' => $score['score'], 'max_score' => $score['max']]);
                    } else {
                        MonitoringPerformanceRecord::query()->create([
                            'student_id' => $assignment['student_id'],
                            'professor_user_id' => $professorUserId,
                            'subject_code' => $assignment['subject_code'],
                            'subject_name' => $assignment['subject_name'],
                            'assessment_name' => $name,
                            'topic' => $name,
                            'score' => $score['score'],
                            'max_score' => $score['max'],
                        ]);
                    }
                    $saved++;
                }

                foreach (['Midterm', 'Final'] as $periodName) {
                    $column = $columns[strtolower($periodName)];
                    if ($column === null) {
                        continue;
                    }
                    $raw = trim((string) ($row[$column] ?? ''));
                    if ($raw === '') {
                        continue;
                    }
                    $grade = $this->periodGrade($raw);
                    if ($grade === null) {
                        $this->note($skipped, "{$periodName} for {$studentNumber} is not a number from 0 to 100.");
                        continue;
                    }
                    $period = $periods->get($periodName);
                    if (! $period) {
                        $this->note($skipped, "{$periodName} is not set up yet.");
                        continue;
                    }
                    $existing = Grade::query()
                        ->where('enrollment_subject_id', $assignment['enrollment_subject_id'])
                        ->where('grading_period_id', $period->id)
                        ->first();
                    if ($existing && $existing->status === 'approved') {
                        $this->note($skipped, "{$periodName} for {$studentNumber} is already approved, so it was left unchanged.");
                        continue;
                    }
                    if ($existing && (float) $existing->grade === $grade) {
                        continue;
                    }
                    $payload = [
                        'professor_id' => $assignment['professor_id'],
                        'grade' => $grade,
                        'remarks' => $grade >= 75 ? 'Passed' : 'Failed',
                        'status' => 'submitted',
                        'submitted_at' => now(),
                    ];
                    if ($existing) {
                        $existing->update($payload);
                    } else {
                        Grade::query()->create([
                            'enrollment_subject_id' => $assignment['enrollment_subject_id'],
                            'grading_period_id' => $period->id,
                            ...$payload,
                        ]);
                    }
                    $submitted++;
                }

                if ($columns['prelim'] !== null && trim((string) ($row[$columns['prelim']] ?? '')) !== '') {
                    $this->note($skipped, 'Prelim is not imported. Only Midterm and Final are sent to the registrar.');
                }
            }
        });

        return [
            'saved' => $saved,
            'submitted' => $submitted,
            'skipped' => $skipped,
        ];
    }

    /** @param  list<list<string>>  $rows */
    private function headerIndex(array $rows): ?int
    {
        $checked = 0;
        foreach ($rows as $index => $row) {
            if ($this->blank($row)) {
                continue;
            }
            $checked++;
            foreach ($row as $cell) {
                if ($this->kind($cell) === 'student') {
                    return $index;
                }
            }
            if ($checked >= 5) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $header
     * @return array{student: ?int, subject: ?int, midterm: ?int, final: ?int, prelim: ?int, class_scores: ?int, scores: list<int>}
     */
    private function columns(array $header): array
    {
        $columns = [
            'student' => null,
            'subject' => null,
            'midterm' => null,
            'final' => null,
            'prelim' => null,
            'class_scores' => null,
            'scores' => [],
        ];
        foreach ($header as $index => $label) {
            $kind = $this->kind($label);
            if ($kind === 'ignore' || $kind === null) {
                continue;
            }
            if ($kind === 'score') {
                $columns['scores'][] = $index;
                continue;
            }
            $columns[$kind] ??= $index;
        }

        return $columns;
    }

    private function kind(string $label): ?string
    {
        $key = strtolower(trim($label));
        $key = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $key));
        if ($key === '') {
            return null;
        }

        return match (true) {
            in_array($key, ['student number', 'student no', 'stud no', 'student id', 'id number', 'learner id', 'lrn'], true) => 'student',
            in_array($key, ['subject', 'subject code'], true) => 'subject',
            in_array($key, ['midterm', 'mid term'], true) => 'midterm',
            in_array($key, ['final', 'finals'], true) => 'final',
            in_array($key, ['prelim', 'preliminary'], true) => 'prelim',
            in_array($key, ['class scores', 'scores'], true) => 'class_scores',
            in_array($key, [
                'name', 'student name', 'full name', 'year', 'year level', 'section', 'section name',
                'course', 'course code', 'course name', 'subject name', 'midterm status', 'final status',
                'status', 'remarks', 'email',
            ], true) => 'ignore',
            default => 'score',
        };
    }

    /**
     * @param  array{class_scores: ?int, scores: list<int>}  $columns
     * @param  list<string>  $header
     * @param  list<string>  $row
     * @return array<string, array{score: float, max: float}>
     */
    private function scores(array $columns, array $header, array $row): array
    {
        $scores = [];
        foreach ($columns['scores'] as $index) {
            $name = trim((string) ($header[$index] ?? ''));
            $parsed = $this->score((string) ($row[$index] ?? ''));
            if ($name !== '' && $parsed) {
                $scores[mb_substr($name, 0, 120)] = $parsed;
            }
        }
        if ($columns['class_scores'] !== null) {
            $text = (string) ($row[$columns['class_scores']] ?? '');
            if (preg_match_all('/([^:;]+):\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)/', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $parsed = $this->pairScore((float) $match[2], (float) $match[3]);
                    $name = mb_substr(trim($match[1]), 0, 120);
                    if ($parsed && $name !== '') {
                        $scores[$name] = $parsed;
                    }
                }
            }
        }

        return $scores;
    }

    /** @return array{score: float, max: float}|null */
    private function score(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $raw, $match) === 1) {
            return $this->pairScore((float) $match[1], (float) $match[2]);
        }
        if (! is_numeric($raw)) {
            return null;
        }

        return $this->pairScore((float) $raw, 100);
    }

    /** @return array{score: float, max: float}|null */
    private function pairScore(float $score, float $max): ?array
    {
        if ($score < 0 || $score > 9999 || $max <= 0 || $max > 9999) {
            return null;
        }

        return ['score' => round($score, 2), 'max' => round($max, 2)];
    }

    private function periodGrade(string $raw): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(?:\s*\/\s*\d+(?:\.\d+)?)?$/', $raw, $match) !== 1) {
            return null;
        }
        $grade = round((float) $match[1], 2);

        return $grade >= 0 && $grade <= 100 ? $grade : null;
    }

    /** @return array<string, array{student_id: int, subject_code: string, subject_name: ?string, enrollment_subject_id: int, professor_id: int}> */
    private function assignments(int $professorUserId): array
    {
        $professorId = (int) Professor::query()->where('user_id', $professorUserId)->value('id');
        if (! $professorId) {
            return [];
        }

        $map = [];
        $rows = EnrollmentSubject::query()
            ->with(['subject:id,subject_code,subject_name', 'enrollment.student:id,student_number'])
            ->where('professor_id', $professorId)
            ->get();
        foreach ($rows as $row) {
            $number = (string) ($row->enrollment?->student?->student_number ?? '');
            $code = (string) ($row->subject?->subject_code ?? '');
            if ($number === '' || $code === '') {
                continue;
            }
            $map[$this->pair($number, $code)] = [
                'student_id' => (int) $row->enrollment->student_id,
                'subject_code' => $code,
                'subject_name' => $row->subject?->subject_name,
                'enrollment_subject_id' => $row->id,
                'professor_id' => $professorId,
            ];
        }

        return $map;
    }

    private function pair(string $studentNumber, string $subjectCode): string
    {
        return strtoupper(trim($studentNumber)).'|'.strtoupper(trim($subjectCode));
    }

    /** @param  list<string>  $row */
    private function blank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $skipped */
    private function note(array &$skipped, string $message): void
    {
        if (! in_array($message, $skipped, true) && count($skipped) < 8) {
            $skipped[] = $message;
        }
    }
}
