<?php

namespace App\Services\StudentRecords;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRow;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LegacyMigrationStagingService
{
    public function __construct(private readonly LegacyExportInspector $inspector) {}

    public function stage(string $path, string $entity, string $operator): LegacyMigrationBatch
    {
        $operator = trim($operator);
        if ($operator === '' || mb_strlen($operator) > 100) {
            throw new InvalidArgumentException('Provide an operator reference of 1 to 100 characters.');
        }
        $report = $this->inspector->inspect($path, $entity);
        if ($report['missing_required_columns'] !== [] || $report['counts']['malformed_rows'] !== 0 || $report['counts']['rows'] === 0) {
            throw new InvalidArgumentException('Export is empty or structurally invalid. Run legacy:inspect and correct the export first.');
        }
        $batch = LegacyMigrationBatch::where('entity', $entity)->where('source_sha256', $report['sha256'])->first();
        if ($batch && $batch->status !== 'loading') {
            throw new InvalidArgumentException('This exact export has already been staged. Inspect the existing batch instead.');
        }
        if (! $batch) {
            $batch = LegacyMigrationBatch::create([
                'entity' => $entity,
                'source_sha256' => $report['sha256'],
                'operator_reference' => $operator,
                'status' => 'loading',
            ]);
        }
        $lastSourceRow = (int) $batch->rows()->max('source_row');
        $pending = [];
        $count = 0;
        foreach ($this->inspector->rows($path) as $sourceRow => $payload) {
            $count++;
            if ($sourceRow <= $lastSourceRow) {
                continue;
            }
            $pending[] = [$sourceRow, $payload];
            if (count($pending) >= 200) {
                $this->persistRows($batch, $entity, $pending);
                $pending = [];
            }
        }
        if ($pending !== []) {
            $this->persistRows($batch, $entity, $pending);
        }
        if ($count !== $report['counts']['rows'] || $batch->rows()->count() !== $count || hash_file('sha256', $path) !== $report['sha256']) {
            throw new InvalidArgumentException('Export changed or staging is incomplete. The batch remains loading and cannot be matched.');
        }
        $batch->update(['row_count' => $count, 'status' => 'staged']);

        return $batch;
    }

    private function persistRows(LegacyMigrationBatch $batch, string $entity, array $rows): void
    {
        DB::transaction(function () use ($batch, $entity, $rows): void {
            foreach ($rows as [$sourceRow, $payload]) {
                $keyParts = [mb_strtolower(trim($payload['student_number'] ?? ''))];
                if ($entity === 'grades') {
                    foreach (['academic_year', 'semester', 'subject_code'] as $field) {
                        $keyParts[] = mb_strtolower(trim($payload[$field] ?? ''));
                    }
                }
                LegacyMigrationRow::create([
                    'batch_id' => $batch->id,
                    'source_row' => $sourceRow,
                    'source_key_sha256' => $keyParts[0] === '' ? null : hash('sha256', implode("\0", $keyParts)),
                    'payload' => $payload,
                    'status' => 'staged',
                ]);
            }
        });
    }
}
