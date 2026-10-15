<?php

namespace App\Observers;

use App\Jobs\AnalyzeStudentDocument;
use App\Models\StudentDocument;

class StudentDocumentObserver
{
    public function created(StudentDocument $studentDocument): void
    {
        $this->queueAnalysis($studentDocument);
    }

    public function updated(StudentDocument $studentDocument): void
    {
        if ($studentDocument->wasChanged('file_path')) {
            $this->queueAnalysis($studentDocument);
        }
    }

    private function queueAnalysis(StudentDocument $studentDocument): void
    {
        if (! $studentDocument->file_path) {
            $studentDocument->aiAnalysis()->delete();

            return;
        }

        if (! $studentDocument->supportsAiAnalysis()) {
            return;
        }

        $analysis = $studentDocument->resetAiAnalysisToPending();

        AnalyzeStudentDocument::dispatch($analysis->id, $studentDocument->file_path)->afterCommit();
    }
}
