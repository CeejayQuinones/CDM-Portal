<?php

namespace App\Jobs;

use App\Models\StudentDocumentAiAnalysis;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\DocumentAnalyzerException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeStudentDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $analysisId,
        public readonly string $filePath,
    ) {
        $this->onQueue('document-analysis');
    }

    public function handle(DocumentAnalysisService $documentAnalysisService): void
    {
        $analysis = StudentDocumentAiAnalysis::query()
            ->with('studentDocument.documentType')
            ->find($this->analysisId);
        $document = $analysis?->studentDocument;

        if (! $analysis || ! $document || $document->file_path !== $this->filePath) {
            return;
        }

        $analysis->update([
            'status' => StudentDocumentAiAnalysis::STATUS_PROCESSING,
            'analyzed_at' => null,
            'error_message' => null,
        ]);

        try {
            $result = $documentAnalysisService->analyze(
                $document,
                $document->documentType?->document_name ?? 'Unknown document type',
            );

            if ($document->fresh()?->file_path !== $this->filePath) {
                return;
            }

            $analysis->update([
                ...$result,
                'status' => StudentDocumentAiAnalysis::STATUS_COMPLETED,
                'analyzed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            if ($document->fresh()?->file_path !== $this->filePath) {
                return;
            }

            $analysis->update([
                'status' => StudentDocumentAiAnalysis::STATUS_FAILED,
                'provider' => $exception instanceof DocumentAnalyzerException ? $exception->provider : null,
                'model' => $exception instanceof DocumentAnalyzerException ? $exception->model : null,
                'analyzed_at' => null,
                'error_message' => 'Document analysis could not be completed.',
            ]);
        }
    }
}
