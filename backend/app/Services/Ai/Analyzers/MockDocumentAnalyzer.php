<?php

namespace App\Services\Ai\Analyzers;

use App\Models\StudentDocument;
use App\Services\Ai\Contracts\DocumentAnalyzer;
use App\Services\Ai\DocumentAnalysisTypeRegistry;

class MockDocumentAnalyzer implements DocumentAnalyzer
{
    public function analyze(StudentDocument $document, string $expectedDocumentType): array
    {
        return [
            'detected_document_type' => DocumentAnalysisTypeRegistry::canonicalKey($expectedDocumentType),
            'confidence' => 0.9900,
            'extracted_data' => [],
            'checks' => [
                'document_type_match' => true,
                'development_mock' => true,
            ],
            'issues' => [
                'Development placeholder only. No document content was analyzed.',
            ],
            'recommendation' => 'needs_review',
            'provider' => 'mock',
            'model' => 'deterministic-development-v1',
        ];
    }
}
