<?php

namespace App\Console\Commands;

use App\Models\LegacyMigrationBatch;
use App\Services\StudentRecords\LegacyMigrationCommitService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CommitLegacyBatch extends Command
{
    protected $signature = 'legacy:commit {batch : Approved batch ID} {--checksum= : Exact source SHA-256 checksum}';

    protected $description = 'Commit an approved legacy batch in resumable transactions; creates official records';

    public function handle(LegacyMigrationCommitService $committer): int
    {
        $batch = LegacyMigrationBatch::find((int) $this->argument('batch'));
        if (! $batch || ! hash_equals((string) $batch->source_sha256, (string) $this->option('checksum'))) {
            $this->error('Batch not found or source checksum does not match.');

            return self::FAILURE;
        }
        try {
            $report = $committer->commit($batch);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ValidationException) {
            $this->error('Commit stopped: a row no longer passes current validation. No partial chunk was saved; inspect the batch and source records.');

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
