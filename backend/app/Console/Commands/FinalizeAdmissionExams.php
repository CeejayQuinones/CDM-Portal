<?php

namespace App\Console\Commands;

use App\Services\Admission\AdmissionExamService;
use Illuminate\Console\Command;

class FinalizeAdmissionExams extends Command
{
    protected $signature = 'admission:finalize-expired';

    protected $description = 'Grade expired Admission sessions using only server-saved answers';

    public function handle(AdmissionExamService $exams): int
    {
        $this->info($exams->expire().' expired Admission exams finalized.');

        return self::SUCCESS;
    }
}
