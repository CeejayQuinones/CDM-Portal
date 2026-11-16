<?php

namespace App\Services\StudentRecords;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Reuses production importer rules, but never approves or creates Portal records. */
class LegacyMigrationPreflight
{
    public function __construct(
        private readonly StudentRosterImportService $roster,
        private readonly HistoricalGradeService $grades,
        private readonly LegacyMigrationMatcher $matcher,
    ) {}

    public function run(LegacyMigrationBatch $batch): array
    {
        if ($batch->status !== 'matched') {
            throw new InvalidArgumentException('Match the complete batch before preflight. A preflighted batch cannot be rerun.');
        }

        // A single-row roster check cannot see repeated emails within this export.
        $emails = [];
        if ($batch->entity === 'students') {
            $batch->rows()->orderBy('id')->chunkById(200, function ($rows) use (&$emails): void {
                foreach ($rows as $row) {
                    $email = mb_strtolower(trim($row->payload['enrollment_email'] ?? ''));
                    if ($email !== '') {
                        $emails[hash('sha256', $email)] = ($emails[hash('sha256', $email)] ?? 0) + 1;
                    }
                }
            });
        }

        $batch->rows()->orderBy('id')->chunkById(200, function ($rows) use ($batch, $emails): void {
            DB::transaction(function () use ($rows, $batch, $emails): void {
                foreach ($rows as $row) {
                    if ($row->status !== 'matched') {
                        continue;
                    }
                    $reason = $batch->entity === 'students'
                        ? $this->studentReason($row, $emails)
                        : $this->gradeReason($row);
                    $row->update([
                        'status' => $reason === null ? 'ready' : 'needs_review',
                        'reason_code' => $reason,
                    ]);
                }
            });
        });
        $batch->update(['status' => 'preflighted']);

        return $this->matcher->report($batch);
    }

    private function studentReason(LegacyMigrationRow $row, array $emails): ?string
    {
        $input = [];
        foreach (StudentRosterImportService::HEADERS as $field) {
            $input[$field] = (string) ($row->payload[$field] ?? '');
        }
        $email = mb_strtolower(trim($input['enrollment_email']));
        if ($email !== '' && ($emails[hash('sha256', $email)] ?? 0) > 1) {
            return 'duplicate_enrollment_email';
        }

        return $this->roster->inspect([$input])[0]['errors'] === [] ? null : 'roster_validation_failed';
    }

    private function gradeReason(LegacyMigrationRow $row): ?string
    {
        $input = $row->payload;
        if (trim($input['official_result'] ?? '') === '' || trim($input['source_reference'] ?? '') === '') {
            return 'missing_official_result_or_source';
        }
        if (! ($row->resolved_refs['student_id'] ?? null)) {
            return 'student_not_imported';
        }
        try {
            $this->grades->resolve($input);
        } catch (ValidationException) {
            return 'grade_validation_failed';
        }

        return null;
    }
}
