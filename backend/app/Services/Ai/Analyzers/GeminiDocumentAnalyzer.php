<?php

namespace App\Services\Ai\Analyzers;

use App\Models\StudentDocument;
use App\Models\UserProfile;
use App\Services\Ai\Contracts\DocumentAnalyzer;
use App\Services\Ai\DocumentAnalysisTypeRegistry;
use App\Services\Ai\Exceptions\DocumentAnalyzerException;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class GeminiDocumentAnalyzer implements DocumentAnalyzer
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const SUPPORTED_MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function analyze(StudentDocument $document, string $expectedDocumentType): array
    {
        $stage = 'document_type_validation';
        $mimeType = null;

        try {
            $analysisType = DocumentAnalysisTypeRegistry::resolve($expectedDocumentType);

            if ($analysisType === null) {
                throw new UnexpectedValueException('The requested document type is not supported for Gemini analysis.');
            }

            [$mimeType, $contents] = $this->readPrivateFile($document);
            $stage = 'http_request';
            $response = $this->request($mimeType, $contents, $analysisType);
            $stage = 'candidate_extraction';
            $extraction = $this->parseExtraction($response, $mimeType, $analysisType);
            $stage = 'normalization';

            return $this->normalizeResult($document, $analysisType, $extraction);
        } catch (DocumentAnalyzerException $exception) {
            $this->logLocalFailure($exception);

            throw $exception;
        } catch (Throwable $exception) {
            $failure = $this->failure(
                message: 'Gemini document analysis failed during '.$stage.'.',
                stage: $stage,
                mimeType: $mimeType,
                failureClass: $exception::class,
                previous: $exception,
            );
            $this->logLocalFailure($failure);

            throw $failure;
        }
    }

    /** @return array{string, string} */
    private function readPrivateFile(StudentDocument $document): array
    {
        $path = $document->file_path;

        if (! is_string($path)
            || ! str_starts_with($path, 'student-documents/'.$document->student_id.'/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || ! Storage::disk('local')->exists($path)) {
            throw $this->failure(
                'The private student document file is unavailable for analysis.',
                'file_lookup',
            );
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = self::SUPPORTED_MIME_TYPES[$extension] ?? null;

        if (! $mimeType) {
            throw $this->failure(
                'The student document file type is not supported for Gemini analysis.',
                'mime_type_validation',
            );
        }

        try {
            $contents = Storage::disk('local')->get($path);
        } catch (Throwable $exception) {
            throw $this->failure(
                'The private student document file could not be read for analysis.',
                'file_read',
                $mimeType,
                failureClass: $exception::class,
                previous: $exception,
            );
        }

        if (! is_string($contents) || $contents === '') {
            throw $this->failure(
                'The student document file is empty.',
                'file_read',
                $mimeType,
            );
        }

        return [$mimeType, $contents];
    }

    /** @param array<string, mixed> $analysisType */
    private function request(string $mimeType, string $contents, array $analysisType): Response
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->connectTimeout(min(10, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->post(sprintf(self::ENDPOINT, rawurlencode($this->model)), [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => $mimeType,
                                    'data' => base64_encode($contents),
                                ],
                            ],
                            ['text' => $this->prompt($analysisType)],
                        ],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $this->responseSchema($analysisType),
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw $this->failure(
                'Gemini document analysis timed out or could not connect.',
                'http_request',
                $mimeType,
                failureClass: $exception::class,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->failure(
            message: match ($response->status()) {
                401, 403 => 'Gemini rejected the configured credentials.',
                429 => 'Gemini rate limit was reached.',
                408 => 'Gemini document analysis timed out.',
                default => $response->serverError()
                    ? 'Gemini document analysis service is unavailable.'
                    : 'Gemini rejected the document analysis request.',
            },
            stage: 'provider_response',
            mimeType: $mimeType,
            httpStatus: $response->status(),
            providerCode: $this->safeProviderScalar($response->json('error.code')),
            providerStatus: $this->safeProviderString($response->json('error.status'), 100),
            providerMessage: $this->safeProviderMessage($response, $contents),
        );
    }

    private function failure(
        string $message,
        string $stage,
        ?string $mimeType = null,
        ?int $httpStatus = null,
        int|string|null $providerCode = null,
        ?string $providerStatus = null,
        ?string $providerMessage = null,
        ?string $failureClass = null,
        ?Throwable $previous = null,
    ): DocumentAnalyzerException {
        return new DocumentAnalyzerException(
            message: $message,
            provider: 'gemini',
            model: $this->model,
            stage: $stage,
            mimeType: $mimeType,
            httpStatus: $httpStatus,
            providerCode: $providerCode,
            providerStatus: $providerStatus,
            providerMessage: $providerMessage,
            failureClass: $failureClass,
            previous: $previous,
        );
    }

    private function logLocalFailure(DocumentAnalyzerException $exception): void
    {
        if (app()->environment('local')) {
            Log::warning('Gemini document analysis failed.', $exception->diagnosticContext());
        }
    }

    private function safeProviderMessage(Response $response, string $documentContents): ?string
    {
        $message = $this->safeProviderString($response->json('error.message'), 1000);

        if ($message === null) {
            return null;
        }

        $message = str_replace($this->apiKey, '[redacted-api-key]', $message);

        if (strlen($documentContents) <= 1000) {
            $message = str_replace(
                [$documentContents, base64_encode($documentContents)],
                '[redacted-document-data]',
                $message,
            );
        }

        $message = preg_replace('/[A-Za-z0-9+\/_=-]{32,}/', '[redacted-token]', $message) ?? '';

        return Str::limit($message, 500, '...');
    }

    private function safeProviderString(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return Str::limit($value, $limit, '...');
    }

    private function safeProviderScalar(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        return $this->safeProviderString($value, 100);
    }

    /** @param array<string, mixed> $analysisType */
    private function prompt(array $analysisType): string
    {
        $fieldInstructions = collect($analysisType['extraction_fields'] ?? [])
            ->map(fn (array $field, string $name): string => sprintf(
                '- %s: %s Use null when absent or illegible.',
                $name,
                $field['description'],
            ))
            ->implode("\n");
        $expectedDocumentType = $analysisType['label'];

        return <<<PROMPT
Analyze the attached document only as an extraction task.
Expected document type: {$expectedDocumentType}.

Extract the common classification and readability fields plus these document-specific fields:
{$fieldInstructions}

Classify the document from its visible content, even when that differs from the expected type.
Do not decide legal authenticity, identity matches, approval, or verification.
Do not repeat personal data in issues or indicators.
Missing optional fields are not proof that the document is incorrect.
Return only the required structured JSON.
PROMPT;
    }

    /** @param array<string, mixed> $analysisType
     * @return array<string, mixed>
     */
    private function responseSchema(array $analysisType): array
    {
        $specificProperties = collect($analysisType['extraction_fields'] ?? [])
            ->mapWithKeys(fn (array $field, string $name): array => [
                $name => [
                    'type' => $field['type'],
                    'nullable' => true,
                    'description' => $field['description'],
                ],
            ])
            ->all();

        return [
            'type' => 'OBJECT',
            'properties' => [
                'detected_document_type' => [
                    'type' => 'STRING',
                    'description' => 'Detected document category based on visible content, or unknown.',
                ],
                'confidence' => [
                    'type' => 'NUMBER',
                    'minimum' => 0,
                    'maximum' => 1,
                    'description' => 'Confidence in extraction and document categorization.',
                ],
                'readable' => [
                    'type' => 'BOOLEAN',
                    'description' => 'Whether key document fields can be read.',
                ],
                'quality' => [
                    'type' => 'STRING',
                    'enum' => ['clear', 'acceptable', 'poor', 'unreadable'],
                ],
                'document_type_indicators' => [
                    'type' => 'ARRAY',
                    'maxItems' => 10,
                    'items' => ['type' => 'STRING'],
                ],
                'issues' => [
                    'type' => 'ARRAY',
                    'maxItems' => 10,
                    'items' => ['type' => 'STRING'],
                ],
                ...$specificProperties,
            ],
            'required' => [
                'detected_document_type',
                'confidence',
                'readable',
                'quality',
                'document_type_indicators',
                'issues',
            ],
        ];
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $analysisType
     * @return array<string, mixed>
     */
    private function parseExtraction(Response $response, string $mimeType, array $analysisType): array
    {
        $parts = $response->json('candidates.0.content.parts');

        if (! is_array($parts)) {
            throw $this->failure(
                'Gemini returned no document analysis candidate.',
                'candidate_extraction',
                $mimeType,
            );
        }

        $text = collect($parts)
            ->filter(fn (mixed $part): bool => is_array($part) && ($part['thought'] ?? false) !== true)
            ->pluck('text')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->implode('');

        if ($text === '') {
            throw $this->failure(
                'Gemini returned no structured document analysis.',
                'candidate_extraction',
                $mimeType,
            );
        }

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->failure(
                'Gemini returned malformed structured document analysis.',
                'structured_output_parsing',
                $mimeType,
                failureClass: $exception::class,
                previous: $exception,
            );
        }

        if (! is_array($data)) {
            throw $this->failure(
                'Gemini returned an invalid document analysis object.',
                'structured_output_validation',
                $mimeType,
            );
        }

        try {
            $this->validateExtraction($data, $analysisType);
        } catch (UnexpectedValueException $exception) {
            throw $this->failure(
                $exception->getMessage(),
                'structured_output_validation',
                $mimeType,
                failureClass: $exception::class,
                previous: $exception,
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $analysisType
     */
    private function validateExtraction(array $data, array $analysisType): void
    {
        $valid = is_string($data['detected_document_type'] ?? null)
            && is_numeric($data['confidence'] ?? null)
            && (float) $data['confidence'] >= 0
            && (float) $data['confidence'] <= 1
            && is_bool($data['readable'] ?? null)
            && in_array($data['quality'] ?? null, ['clear', 'acceptable', 'poor', 'unreadable'], true)
            && $this->isStringList($data['document_type_indicators'] ?? null)
            && $this->isStringList($data['issues'] ?? null)
            && collect($analysisType['extraction_fields'] ?? [])->every(
                fn (array $field, string $name): bool => $this->validOptionalField(
                    $data,
                    $name,
                    $field['type'],
                ),
            );

        if (! $valid) {
            throw new UnexpectedValueException('Gemini returned an invalid structured document analysis.');
        }
    }

    /** @param array<string, mixed> $data */
    private function validOptionalField(array $data, string $name, string $type): bool
    {
        if (! array_key_exists($name, $data) || $data[$name] === null) {
            return true;
        }

        return match ($type) {
            'STRING' => is_string($data[$name]),
            'BOOLEAN' => is_bool($data[$name]),
            'NUMBER' => is_numeric($data[$name]),
            default => false,
        };
    }

    private function isStringList(mixed $value): bool
    {
        return is_array($value)
            && count($value) <= 10
            && collect($value)->every(fn (mixed $item): bool => is_string($item));
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @return array<string, mixed>
     */
    private function normalizeResult(
        StudentDocument $document,
        array $analysisType,
        array $extraction,
    ): array {
        $document->loadMissing(['student.userProfile', 'student.course']);
        $student = $document->student;
        $profile = $document->student?->userProfile;

        if (! $profile instanceof UserProfile) {
            throw new RuntimeException('The student profile is unavailable for document comparison.');
        }

        $expectedType = $analysisType['key'];
        $detectedType = DocumentAnalysisTypeRegistry::canonicalKey($extraction['detected_document_type']);
        $specificData = collect($analysisType['extraction_fields'] ?? [])
            ->mapWithKeys(fn (array $field, string $name): array => [
                $name => $this->normalizeExtractedField($name, $field['type'], $extraction[$name] ?? null),
            ])
            ->all();
        $extractedName = $specificData['student_full_name'] ?? null;
        $nameExtracted = $extractedName !== null;
        $typeMatches = $detectedType === $expectedType;
        $nameMatches = $nameExtracted && $this->namesMatch($profile, $extractedName);
        $readable = $extraction['readable'] === true && $extraction['quality'] !== 'unreadable';
        $confidence = (float) $extraction['confidence'];
        $checks = [
            'document_type_match' => $typeMatches,
            'name_match' => $nameMatches,
        ];
        $issues = $this->commonIssues(
            $extraction['issues'],
            $typeMatches,
            $nameExtracted,
            $nameMatches,
            $readable,
            $confidence,
            $extraction['quality'],
        );
        $strongIdentityMismatch = $nameExtracted && ! $nameMatches;
        $typeChecksComplete = true;

        if ($expectedType === 'birth_certificate') {
            $profileDob = $profile->birth_date?->toDateString();
            $extractedDob = $specificData['date_of_birth'] ?? null;
            $dobExtracted = $extractedDob !== null;
            $dobMatches = $dobExtracted && $profileDob !== null && hash_equals($profileDob, $extractedDob);
            $checks['date_of_birth_match'] = $dobMatches;
            $typeChecksComplete = $dobMatches;

            if ($profileDob === null) {
                $issues[] = 'Student profile has no date of birth for comparison.';
            } elseif (! $dobExtracted) {
                $issues[] = 'Date of birth could not be extracted or normalized.';
            } elseif (! $dobMatches) {
                $issues[] = 'Extracted date of birth does not match the student profile.';
                $strongIdentityMismatch = true;
            }
        }

        if ($expectedType === 'certificate_of_enrollment') {
            $studentNumber = $specificData['student_number'] ?? null;
            $course = $specificData['course_program'] ?? null;
            $yearLevel = $specificData['year_level'] ?? null;
            $studentNumberMatch = $studentNumber === null
                ? null
                : $this->identifiersMatch($student?->student_number, $studentNumber);
            $courseMatch = $course === null ? null : $this->courseMatches($student?->course, $course);
            $yearLevelMatch = $yearLevel === null ? null : $this->yearLevelMatches($student?->year_level, $yearLevel);
            $checks['student_number_match'] = $studentNumberMatch;
            $checks['course_match'] = $courseMatch;
            $checks['year_level_match'] = $yearLevelMatch;
            $typeChecksComplete = $studentNumberMatch === true && $courseMatch === true && $yearLevelMatch === true;

            $this->addOptionalComparisonIssue($issues, 'Student number', $studentNumberMatch);
            $this->addOptionalComparisonIssue($issues, 'Course or program', $courseMatch);
            $this->addOptionalComparisonIssue($issues, 'Year level', $yearLevelMatch);
            $this->addMissingFieldIssue($issues, 'Academic year', $specificData['academic_year'] ?? null);
            $this->addMissingFieldIssue($issues, 'Semester', $specificData['semester'] ?? null);
            $this->addMissingFieldIssue($issues, 'Issuing institution', $specificData['institution'] ?? null);

            if ($studentNumberMatch === false) {
                $strongIdentityMismatch = true;
            }
        }

        if ($expectedType === 'form_137') {
            $structurePresent = $specificData['subjects_grades_present'] ?? null;
            $checks['school_document_structure_present'] = $structurePresent;
            $typeChecksComplete = $structurePresent === true;

            if ($structurePresent !== true) {
                $issues[] = $structurePresent === null
                    ? 'Academic record structure could not be determined.'
                    : 'Expected subjects or grades were not detected.';
            }

            $this->addMissingFieldIssue($issues, 'School name', $specificData['school_name'] ?? null);
            $this->addMissingFieldIssue($issues, 'Grade or year information', $specificData['grade_year_information'] ?? null);
            $this->addMissingFieldIssue($issues, 'School year', $specificData['school_year'] ?? null);
        }

        if ($expectedType === 'good_moral') {
            $issuerPresent = ($specificData['issuing_school'] ?? null) !== null;
            $checks['issuer_present'] = $issuerPresent;
            $typeChecksComplete = $issuerPresent;

            if (! $issuerPresent) {
                $issues[] = 'Issuing school could not be extracted.';
            }

            $this->addMissingFieldIssue($issues, 'Issue date', $specificData['date_issued'] ?? null);
            $this->addMissingFieldIssue($issues, 'Signatory name', $specificData['signatory_name'] ?? null);
            $this->addMissingFieldIssue($issues, 'Signatory position', $specificData['signatory_position'] ?? null);
        }

        $checks['readable'] = $readable;
        $issues = array_values(array_unique($issues));
        $strongMismatch = ! $typeMatches || $strongIdentityMismatch || ! $readable;
        $canApprove = $typeMatches
            && $nameMatches
            && $readable
            && $typeChecksComplete
            && $confidence >= 0.8
            && in_array($extraction['quality'], ['clear', 'acceptable'], true)
            && $issues === [];

        unset($specificData['student_full_name']);

        return [
            'detected_document_type' => $detectedType,
            'confidence' => $confidence,
            'extracted_data' => [
                'name' => $extractedName,
                ...$specificData,
                'quality' => $extraction['quality'],
                'document_type_indicators' => $this->cleanStringList($extraction['document_type_indicators']),
            ],
            'checks' => $checks,
            'issues' => $issues,
            'recommendation' => $strongMismatch
                ? 'likely_incorrect'
                : ($canApprove ? 'review_and_approve' : 'needs_review'),
            'provider' => 'gemini',
            'model' => $this->model,
        ];
    }

    /** @param array<int, string> $providerIssues
     * @return array<int, string>
     */
    private function commonIssues(
        array $providerIssues,
        bool $typeMatches,
        bool $nameExtracted,
        bool $nameMatches,
        bool $readable,
        float $confidence,
        string $quality,
    ): array {
        $issues = $this->cleanStringList($providerIssues);

        if (! $typeMatches) {
            $issues[] = 'Detected document type does not match the expected document type.';
        }
        if (! $nameExtracted) {
            $issues[] = 'Student name could not be extracted.';
        } elseif (! $nameMatches) {
            $issues[] = 'Extracted student name does not match the student profile.';
        }
        if (! $readable) {
            $issues[] = 'Document is not sufficiently readable.';
        } elseif ($quality === 'poor') {
            $issues[] = 'Document quality requires manual review.';
        }
        if ($confidence < 0.8) {
            $issues[] = 'Document classification confidence is below the review threshold.';
        }

        return array_values(array_unique($issues));
    }

    /** @param array<int, string> $issues */
    private function addOptionalComparisonIssue(array &$issues, string $field, ?bool $matches): void
    {
        if ($matches === null) {
            $issues[] = $field.' could not be extracted for comparison.';
        } elseif (! $matches) {
            $issues[] = $field.' does not match the student profile.';
        }
    }

    /** @param array<int, string> $issues */
    private function addMissingFieldIssue(array &$issues, string $field, mixed $value): void
    {
        if ($value === null) {
            $issues[] = $field.' could not be extracted.';
        }
    }

    private function normalizeExtractedField(string $name, string $type, mixed $value): string|bool|float|null
    {
        if ($type === 'BOOLEAN') {
            return is_bool($value) ? $value : null;
        }

        if ($type === 'NUMBER') {
            return is_numeric($value) ? (float) $value : null;
        }

        $value = $this->nullableTrimmedString($value);

        if ($value !== null && in_array($name, ['date_of_birth', 'date_issued'], true)) {
            return $this->normalizeDate($value);
        }

        return $value;
    }

    /** @param array<int, string> $values
     * @return array<int, string>
     */
    private function cleanStringList(array $values): array
    {
        return collect($values)
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    private function namesMatch(UserProfile $profile, string $extractedName): bool
    {
        $coreTokens = $this->nameTokens(collect([$profile->first_name, $profile->last_name])->filter()->join(' '));
        $optionalTokens = $this->nameTokens(collect([$profile->middle_name, $profile->suffix])->filter()->join(' '));
        $extractedTokens = $this->nameTokens($extractedName);

        if ($coreTokens === [] || $extractedTokens === []) {
            return false;
        }

        foreach ($coreTokens as $token) {
            if (! in_array($token, $extractedTokens, true)) {
                return false;
            }
        }

        foreach ($extractedTokens as $token) {
            if (in_array($token, $coreTokens, true) || in_array($token, $optionalTokens, true)) {
                continue;
            }

            if (strlen($token) === 1 && collect($optionalTokens)->contains(
                fn (string $optional): bool => str_starts_with($optional, $token),
            )) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function identifiersMatch(?string $expected, string $extracted): bool
    {
        $expected = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $expected))) ?? '';
        $extracted = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($extracted))) ?? '';

        return $expected !== '' && $extracted !== '' && hash_equals($expected, $extracted);
    }

    private function courseMatches(mixed $course, string $extracted): bool
    {
        if (! $course) {
            return false;
        }

        $extractedTokens = $this->comparableTokens($extracted);
        $courseCode = strtolower(Str::ascii((string) $course->course_code));

        if ($courseCode !== '' && in_array($courseCode, $extractedTokens, true)) {
            return true;
        }

        $ignored = ['bachelor', 'degree', 'of', 'science', 'in', 'the', 'program'];
        $courseTokens = collect($this->comparableTokens((string) $course->course_name))
            ->reject(fn (string $token): bool => in_array($token, $ignored, true))
            ->values()
            ->all();

        return $courseTokens !== [] && collect($courseTokens)->every(
            fn (string $token): bool => in_array($token, $extractedTokens, true),
        );
    }

    private function yearLevelMatches(?int $expected, string $extracted): bool
    {
        if ($expected === null) {
            return false;
        }

        $normalized = strtolower(Str::ascii($extracted));
        $words = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            'sixth' => 6,
        ];

        foreach ($words as $word => $value) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $normalized) === 1) {
                return $expected === $value;
            }
        }

        if (preg_match('/\b([1-6])(?:st|nd|rd|th)?\b/', $normalized, $matches) === 1) {
            return $expected === (int) $matches[1];
        }

        return false;
    }

    /** @return array<int, string> */
    private function comparableTokens(string $value): array
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))) ?? '';

        return collect(preg_split('/\s+/', trim($normalized)) ?: [])
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function nameTokens(string $value): array
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))) ?? '';

        return collect(preg_split('/\s+/', trim($normalized)) ?: [])
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'm-d-Y', 'F j, Y', 'j F Y', 'M j, Y', 'j M Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
