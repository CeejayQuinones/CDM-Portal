<?php

namespace App\Console\Commands;

use App\Models\LegacyMigrationBatch;
use App\Services\StudentRecords\LegacyMigrationMatcher;
use Illuminate\Console\Command;
use InvalidArgumentException;

class MatchLegacyBatch extends Command
{
    protected $signature = 'legacy:match {batch : Staged batch ID}';

    protected $description = 'Provisionally match staged identifiers; never import Portal records';

    public function handle(LegacyMigrationMatcher $matcher): int
    {
        $batch = LegacyMigrationBatch::find((int) $this->argument('batch'));
        if (! $batch) {
            $this->error('Batch not found.');

            return self::FAILURE;
        }
        try {
            $report = $matcher->match($batch);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
