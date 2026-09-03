<?php

namespace App\Services\Ai\Contracts;

use App\Models\StudentDocument;

interface DocumentAnalyzer
{
    /**
     * @return array{
     *     detected_document_type: string|null,
     *     confidence: float|int|string|null,
     *     extracted_data: array<string, mixed>,
     *     checks: array<string, mixed>,
     *     issues: array<int|string, mixed>,
     *     recommendation: string|null,
     *     provider: string|null,
     *     model: string|null
     * }
     */
    public function analyze(StudentDocument $document, string $expectedDocumentType): array;
}
