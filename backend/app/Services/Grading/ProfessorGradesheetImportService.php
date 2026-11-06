<?php

namespace App\Services\Grading;

use App\Models\GradeAssessment;
use App\Models\GradePeriodSchedule;
use App\Models\GradeScore;
use App\Models\GradeSheet;
use App\Models\SectionSubject;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfessorGradesheetImportService
{
    public const PROPOSAL_TTL_SECONDS = 600;

    public function __construct(private GradingService $grading) {}

    /** @param array<string, mixed> $context @param array<int, array<string, mixed>> $changes */
    public function issue(User $actor, array $context, array $changes, string $workbookHash): array
    {
        $id = (string) Str::uuid();
        $sheet = $context['sheet'];
        $token = $this->token($id, $actor->id, $sheet->id);
        $expiresAt = now()->addSeconds(self::PROPOSAL_TTL_SECONDS);
        $schedule = $context['schedule'] ?? null;
        $payload = [
            'proposal_id' => $id,
            'actor_user_id' => (int) $actor->id,
            'professor_id' => (int) $context['professor_id'],
            'grade_sheet_id' => (int) $sheet->id,
            'grade_sheet_version' => (int) $sheet->version,
            'section_subject_id' => (int) $sheet->section_subject_id,
            'academic_year_id' => (int) $context['academic_year_id'],
            'semester_id' => (int) $context['semester_id'],
            'period' => $context['period'],
            'workbook_sha256' => $workbookHash,
            'roster_ids' => collect($context['students'])->pluck('enrollment_subject_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'assessments' => collect($context['assessments'])->map(fn (array $assessment) => [
                'id' => (int) $assessment['id'],
                'version' => (int) $assessment['version'],
                'label' => $assessment['label'],
                'category' => $assessment['category'],
                'max_score' => (float) $assessment['max_score'],
                'status' => $assessment['status'],
            ])->sortBy('id')->values()->all(),
            'schedule' => $schedule ? [
                'id' => (int) $schedule['id'],
                'version' => (int) $schedule['version'],
                'status' => $schedule['status'],
                'opens_at' => $schedule[$context['period'].'_opens_at'],
                'deadline' => $schedule[$context['period'].'_deadline'],
            ] : null,
            'changes' => collect($changes)->map(fn (array $change) => [
                'assessment_id' => (int) $change['assessment_id'],
                'enrollment_subject_id' => (int) $change['enrollment_subject_id'],
                'baseline_value' => $change['baseline_value'],
                'baseline_version' => $change['baseline_version'],
                'imported' => (float) $change['imported'],
            ])->values()->all(),
            'issued_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Cache::put($this->proposalKey($token), $payload, $expiresAt);

        return ['proposal_token' => $token, 'proposal_expires_at' => $payload['expires_at']];
    }

    /** @return array<string, mixed> */
    public function apply(User $actor, GradeSheet $requestedSheet, string $period, string $token): array
    {
        $this->grading->assertOwnedSheetAccess($actor, $requestedSheet);
        abort_unless($this->validToken($token, $actor->id, $requestedSheet->id), 422, 'The import proposal is invalid or was modified. Preview the workbook again.');

        try {
            return Cache::lock($this->lockKey($token), 30)->block(3, function () use ($actor, $requestedSheet, $period, $token): array {
                $completed = Cache::get($this->resultKey($token));
                if (is_array($completed)) {
                    return $completed + ['replayed' => true];
                }

                $proposal = Cache::get($this->proposalKey($token));
                abort_unless(is_array($proposal), 409, 'This import proposal expired or is no longer available. Upload and preview the workbook again.');
                abort_unless((int) ($proposal['actor_user_id'] ?? 0) === (int) $actor->id
                    && (int) ($proposal['grade_sheet_id'] ?? 0) === (int) $requestedSheet->id
                    && ($proposal['period'] ?? null) === $period, 422, 'The import proposal does not match this Professor, grade sheet, or grading period.');

                $result = DB::transaction(fn (): array => $this->applyTransaction($actor, $proposal));
                Cache::put($this->resultKey($token), $result, now()->addSeconds(self::PROPOSAL_TTL_SECONDS));
                Cache::forget($this->proposalKey($token));

                return $result + ['replayed' => false];
            });
        } catch (LockTimeoutException) {
            abort(409, 'This import proposal is already being applied. Wait for the current request to finish.');
        }
    }

    /** @param array<string, mixed> $proposal @return array<string, mixed> */
    private function applyTransaction(User $actor, array $proposal): array
    {
        $sheet = GradeSheet::query()->lockForUpdate()->findOrFail($proposal['grade_sheet_id']);
        $this->grading->assertEditableSheet($actor, $sheet, $proposal['period']);
        abort_unless((int) $sheet->version === (int) $proposal['grade_sheet_version'], 409, 'The grade sheet changed after preview. Refresh and preview the workbook again.');

        $assignment = SectionSubject::query()->lockForUpdate()->findOrFail($sheet->section_subject_id);
        abort_unless((int) $assignment->id === (int) $proposal['section_subject_id']
            && (int) $assignment->professor_id === (int) $proposal['professor_id'], 409, 'The class assignment changed after preview.');
        $assignment->loadMissing('section');
        abort_unless((int) $assignment->section->academic_year_id === (int) $proposal['academic_year_id']
            && (int) $assignment->section->semester_id === (int) $proposal['semester_id'], 409, 'The academic term changed after preview.');

        $this->assertScheduleUnchanged($proposal, $assignment);

        $rosterIds = $this->grading->rosterIds($sheet, true);
        abort_unless($rosterIds === $proposal['roster_ids'], 409, 'The class roster changed after preview. Upload and preview a newly exported workbook.');

        $assessments = GradeAssessment::query()
            ->where('grade_sheet_id', $sheet->id)
            ->where('period', $proposal['period'])
            ->lockForUpdate()
            ->get();
        $activeAssessments = $assessments->where('status', 'active')->keyBy('id');
        $expectedAssessments = collect($proposal['assessments'])->keyBy('id');
        abort_unless($activeAssessments->count() === $expectedAssessments->count(), 409, 'Assessment definitions changed after preview. Upload and preview the workbook again.');
        foreach ($expectedAssessments as $id => $expected) {
            $assessment = $activeAssessments->get($id);
            abort_unless($assessment
                && (int) $assessment->version === (int) $expected['version']
                && $assessment->label === $expected['label']
                && $assessment->category === $expected['category']
                && abs((float) $assessment->max_score - (float) $expected['max_score']) < 0.00001,
                409,
                'Assessment definitions changed after preview. Upload and preview the workbook again.'
            );
        }

        $changes = collect($proposal['changes']);
        abort_unless($changes->isNotEmpty(), 422, 'The import proposal contains no score changes.');
        $pairs = $changes->map(fn (array $change) => $change['assessment_id'].':'.$change['enrollment_subject_id']);
        abort_if($pairs->duplicates()->isNotEmpty(), 422, 'The import proposal contains duplicate score changes.');

        $assessmentIds = $expectedAssessments->keys()->map(fn ($id) => (int) $id)->all();
        $scores = GradeScore::query()->whereIn('grade_assessment_id', $assessmentIds)->lockForUpdate()->get()
            ->keyBy(fn (GradeScore $score) => $score->grade_assessment_id.':'.$score->enrollment_subject_id);

        foreach ($changes as $change) {
            $assessment = $activeAssessments->get($change['assessment_id']);
            abort_unless($assessment && in_array((int) $change['enrollment_subject_id'], $rosterIds, true), 422, 'The import proposal contains an unauthorized Student or assessment.');
            $imported = $change['imported'];
            abort_unless(is_int($imported) || is_float($imported), 422, 'Every imported score must be numeric.');
            $imported = (float) $imported;
            abort_unless(is_finite($imported) && $imported >= 0 && $imported <= (float) $assessment->max_score, 422, 'An imported score is outside the valid assessment range.');

            $current = $scores->get($change['assessment_id'].':'.$change['enrollment_subject_id']);
            $currentVersion = $current?->version;
            $currentValue = $current?->score;
            abort_unless($this->sameNullableNumber($currentValue, $change['baseline_value'])
                && $this->sameNullableInteger($currentVersion, $change['baseline_version']), 409, 'A score changed after preview. Refresh the grade sheet and preview the workbook again.');
        }

        foreach ($changes as $change) {
            $key = $change['assessment_id'].':'.$change['enrollment_subject_id'];
            $score = $scores->get($key);
            $old = $score?->score;
            if ($score) {
                $score->score = $change['imported'];
                $score->updated_by = $actor->id;
                $score->version++;
                $score->save();
                $action = 'score.updated';
            } else {
                $score = GradeScore::create([
                    'grade_assessment_id' => $change['assessment_id'],
                    'enrollment_subject_id' => $change['enrollment_subject_id'],
                    'score' => $change['imported'],
                    'updated_by' => $actor->id,
                ]);
                $action = 'score.created';
            }
            $this->grading->audit($actor, $action, $sheet, null, [
                'assessment_id' => (int) $change['assessment_id'],
                'enrollment_subject_id' => (int) $change['enrollment_subject_id'],
                'old' => $old,
                'new' => $score->score,
                'source' => 'excel_import',
                'operation_id' => $proposal['proposal_id'],
            ]);
        }

        $affectedStudents = $changes->pluck('enrollment_subject_id')->unique()->count();
        $this->grading->audit($actor, 'scores.excel_import_applied', $sheet, null, [
            'operation_id' => $proposal['proposal_id'],
            'source' => 'excel_import',
            'section_subject_id' => (int) $sheet->section_subject_id,
            'academic_year_id' => (int) $proposal['academic_year_id'],
            'semester_id' => (int) $proposal['semester_id'],
            'period' => $proposal['period'],
            'changed_score_count' => $changes->count(),
            'affected_student_count' => $affectedStudents,
            'assessment_ids' => $changes->pluck('assessment_id')->unique()->values()->all(),
            'workbook_sha256' => $proposal['workbook_sha256'],
        ]);

        return [
            'operation_id' => $proposal['proposal_id'],
            'applied_scores' => $changes->count(),
            'affected_students' => $affectedStudents,
            'period' => $proposal['period'],
            'grade_sheet_status' => $sheet->status,
            'message' => $changes->count().' imported '.Str::plural('score', $changes->count()).' saved as grading drafts. Grades were not submitted to the Registrar.',
        ];
    }

    /** @param array<string, mixed> $proposal */
    private function assertScheduleUnchanged(array $proposal, SectionSubject $assignment): void
    {
        $expected = $proposal['schedule'];
        if ($expected === null) {
            abort_unless(GradePeriodSchedule::query()
                ->where('academic_year_id', $assignment->section->academic_year_id)
                ->where('semester_id', $assignment->section->semester_id)
                ->lockForUpdate()->doesntExist(), 409, 'The grading schedule changed after preview.');

            return;
        }
        $schedule = GradePeriodSchedule::query()->lockForUpdate()->find($expected['id']);
        abort_unless($schedule
            && (int) $schedule->academic_year_id === (int) $proposal['academic_year_id']
            && (int) $schedule->semester_id === (int) $proposal['semester_id']
            && (int) $schedule->version === (int) $expected['version']
            && $schedule->status === $expected['status']
            && $schedule->{$proposal['period'].'_opens_at'}?->toJSON() === $expected['opens_at']
            && $schedule->{$proposal['period'].'_deadline'}?->toJSON() === $expected['deadline'], 409, 'The grading schedule or deadline changed after preview. Upload and preview the workbook again.');
    }

    private function token(string $id, int $actorId, int $sheetId): string
    {
        $signature = hash_hmac('sha256', $id.'|'.$actorId.'|'.$sheetId, (string) config('app.key'));

        return $id.'.'.$signature;
    }

    private function validToken(string $token, int $actorId, int $sheetId): bool
    {
        [$id, $signature] = array_pad(explode('.', $token, 2), 2, null);

        return is_string($id) && Str::isUuid($id) && is_string($signature)
            && hash_equals($this->token($id, $actorId, $sheetId), $token);
    }

    private function proposalKey(string $token): string
    {
        return 'professor-gradesheet-import:proposal:'.hash('sha256', $token);
    }

    private function resultKey(string $token): string
    {
        return 'professor-gradesheet-import:result:'.hash('sha256', $token);
    }

    private function lockKey(string $token): string
    {
        return 'professor-gradesheet-import:lock:'.hash('sha256', $token);
    }

    private function sameNullableNumber(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return abs((float) $left - (float) $right) < 0.00001;
    }

    private function sameNullableInteger(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }
}
