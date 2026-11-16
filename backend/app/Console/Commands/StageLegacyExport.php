<?php

namespace App\Console\Commands;

use App\Services\StudentRecords\LegacyMigrationStagingService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class StageLegacyExport extends Command
{
    protected $signature = 'legacy:stage {file : Local CSV export path} {--entity= : students or grades} {--operator= : Authorized operator reference}';

    protected $description = 'Stage an Access CSV export for later matching; does not import student records';

    public function handle(LegacyMigrationStagingService $staging): int
    {
        try {
            $batch = $staging->stage((string) $this->argument('file'), (string) $this->option('entity'), (string) $this->option('operator'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Staged batch {$batch->id}: {$batch->row_count} {$batch->entity} rows. No student or grade records were created.");

        return self::SUCCESS;
    }
}
