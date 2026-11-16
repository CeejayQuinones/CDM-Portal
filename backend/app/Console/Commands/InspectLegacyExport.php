<?php

namespace App\Console\Commands;

use App\Services\StudentRecords\LegacyExportInspector;
use Illuminate\Console\Command;
use InvalidArgumentException;

class InspectLegacyExport extends Command
{
    protected $signature = 'legacy:inspect {file : Local CSV export path} {--entity= : students or grades}';

    protected $description = 'Read-only structural inspection of an Access CSV export; does not import data';

    public function handle(LegacyExportInspector $inspector): int
    {
        try {
            $result = $inspector->inspect((string) $this->argument('file'), (string) $this->option('entity'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result['missing_required_columns'] === [] && $result['counts']['malformed_rows'] === 0
            ? self::SUCCESS : self::FAILURE;
    }
}
