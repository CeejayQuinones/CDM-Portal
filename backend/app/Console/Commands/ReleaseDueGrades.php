<?php

namespace App\Console\Commands;

use App\Services\Grading\GradingWorkflowService;
use Illuminate\Console\Command;

class ReleaseDueGrades extends Command
{
    protected $signature = 'grading:release-due';

    protected $description = 'Publish approved grade sheets whose release schedule is due';

    public function handle(GradingWorkflowService $workflow): int
    {
        $count = $workflow->processDue();
        $this->info("Processed {$count} due grade release schedule(s).");

        return self::SUCCESS;
    }
}
