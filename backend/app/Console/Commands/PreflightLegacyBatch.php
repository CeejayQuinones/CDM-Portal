<?php

namespace App\Console\Commands;

use App\Models\LegacyMigrationBatch;
use App\Services\StudentRecords\LegacyMigrationPreflight;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PreflightLegacyBatch extends Command
{
    protected $signature = 'legacy:preflight {batch : Matched batch ID}';

    protected $description = 'Run existing import validation on a matched legacy batch without creating records';

    public function handle(LegacyMigrationPreflight $preflight): int
    {
        $batch = LegacyMigrationBatch::find((int) $this->argument('batch'));
        if (! $batch) {
            $this->error('Batch not found.');

            return self::FAILURE;
        }
        try {
            $report = $preflight->run($batch);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
