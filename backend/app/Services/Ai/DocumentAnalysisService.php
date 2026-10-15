<?php

namespace App\Services\Ai;

use App\Models\StudentDocument;
use App\Services\Ai\Contracts\DocumentAnalyzer;
use UnexpectedValueException;

class DocumentAnalysisService
{
    public function __construct(private readonly DocumentAnalyzer $analyzer) {}

    /**
     * @return array{
     *     detected_document_type: string|null,
     *     confidence: float|null,
     *     extracted_data: array<string, mixed>,
     *     checks: array<string, mixed>,
     *     issues: array<int|string, mixed>,
     *     recommendation: string|null,
     *     provider: string|null,
     *     model: string|null
     * }
     */
    public function analyze(StudentDocument $document, string $expectedDocumentType): array
    {
        $result = $this->analyzer->analyze($document, $expectedDocumentType);
        $confidence = $result['confidence'] ?? null;

        if ($confidence !== null && (! is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1)) {
            throw new UnexpectedValueException('Document analyzer confidence must be between 0 and 1.');
        }

        foreach (['extracted_data', 'checks', 'issues'] as $arrayField) {
            if (isset($result[$arrayField]) && ! is_array($result[$arrayField])) {
                throw new UnexpectedValueException("Document analyzer {$arrayField} must be an array.");
            }
        }

        return [
            'detected_document_type' => $this->nullableString($result['detected_document_type'] ?? null),
            'confidence' => $confidence === null ? null : (float) $confidence,
            'extracted_data' => $result['extracted_data'] ?? [],
            'checks' => $result['checks'] ?? [],
            'issues' => $result['issues'] ?? [],
            'recommendation' => $this->nullableString($result['recommendation'] ?? null),
            'provider' => $this->nullableString($result['provider'] ?? null),
            'model' => $this->nullableString($result['model'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
