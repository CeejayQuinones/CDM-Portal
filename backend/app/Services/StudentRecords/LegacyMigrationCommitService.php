<?php

namespace App\Services\StudentRecords;

use App\Models\LegacyMigrationBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Developer-run, resumable commit. Every chunk reuses the same write paths as small imports. */
class LegacyMigrationCommitService
{
    public function __construct(
        private readonly StudentRosterImportService $roster,
        private readonly HistoricalGradeService $grades,
        private readonly LegacyMigrationMatcher $matcher,
    ) {}

    public function commit(LegacyMigrationBatch $batch): array
    {
        if (! in_array($batch->status, ['approved', 'importing'], true) || ! $batch->approved_by || ! $batch->approved_at) {
            throw new InvalidArgumentException('Batch must be approved by a Registrar after reconciliation.');
        }
        $actor = User::findOrFail($batch->approved_by);
        do {
            $processed = DB::transaction(function () use ($batch, $actor): int {
                $locked = LegacyMigrationBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'imported') {
                    return 0;
                }
                if (! in_array($locked->status, ['approved', 'importing'], true)) {
                    throw new InvalidArgumentException('Batch is not approved or importable.');
                }
                $total = $locked->rows()->count();
                $imported = $locked->rows()->where('status', 'imported')->count();
                $ready = $locked->rows()->where('status', 'ready')->count();
                if ($total !== $locked->row_count || $ready + $imported !== $total) {
                    throw new InvalidArgumentException('Batch row counts or statuses changed since approval. Stop for reconciliation.');
                }
                $rows = $locked->rows()->where('status', 'ready')->orderBy('id')->limit(100)->get();
                if ($rows->isEmpty()) {
                    $locked->update(['status' => 'imported']);

                    return 0;
                }
                $locked->update(['status' => 'importing']);
                foreach ($rows as $row) {
                    if ($locked->entity === 'students') {
                        $input = [];
                        foreach (StudentRosterImportService::HEADERS as $field) {
                            $input[$field] = (string) ($row->payload[$field] ?? '');
                        }
                        $input['enrollment_email'] = mb_strtolower(trim($input['enrollment_email']));
                        $record = $this->roster->importRow($input, $actor, [
                            'legacy_batch_id' => $locked->id, 'legacy_source_row' => $row->source_row,
                            'legacy_source_sha256' => $locked->source_sha256,
                        ]);
                    } elseif ($locked->entity === 'grades') {
                        $record = $this->grades->create($row->payload, $actor, 'legacy_import');
                    } else {
                        throw new InvalidArgumentException('Unsupported legacy entity.');
                    }
                    $row->update(['status' => 'imported', 'resolved_refs' => [
                        ...($row->resolved_refs ?? []), 'imported_record_id' => $record->id,
                    ]]);
                }
                if ($locked->rows()->where('status', 'imported')->count() === $total) {
                    $locked->update(['status' => 'imported']);
                }

                return $rows->count();
            }, 3);
        } while ($processed > 0);

        return $this->matcher->report($batch->fresh());
    }
}
