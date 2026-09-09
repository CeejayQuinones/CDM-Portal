<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MonitoringAiHelpService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** @param array<string, mixed> $assessment @return array{reply: string, source: string} */
    public function generateHelp(array $assessment, string $prompt): array
    {
        $prompt = trim($prompt);
        abort_if($prompt === '' || mb_strlen($prompt) > 1_500, 422, 'Enter a question of up to 1,500 characters.');

        $key = trim((string) config('services.document_analysis.gemini.api_key'));
        $model = trim((string) config('services.document_analysis.gemini.model'));
        $timeout = (int) config('services.document_analysis.gemini.timeout', 60);
        if ($key === '' || $model === '') {
            throw new RuntimeException('AI help is temporarily unavailable. Please try again.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['x-goog-api-key' => $key])
                ->connectTimeout(10)
                ->timeout($timeout)
                ->post(sprintf(self::ENDPOINT, rawurlencode($model)), [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $this->prompt($assessment, $prompt)]]]],
                    'generationConfig' => ['temperature' => 0.45, 'maxOutputTokens' => 700],
                ]);
        } catch (ConnectionException $exception) {
            $this->logFailure('timeout', null, true, $exception::class);
            throw new RuntimeException('The AI service took too long to respond. Please try again.');
        }

        if (! $response->successful()) {
            $this->logFailure('provider_error', $response->status(), false, 'HttpResponse');
            throw new RuntimeException('AI help is temporarily unavailable. Please try again.');
        }

        $reply = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($reply) || trim($reply) === '') {
            $this->logFailure('invalid_response', $response->status(), false, 'MalformedResponse');
            throw new RuntimeException('AI help is temporarily unavailable. Please try again.');
        }

        return ['reply' => trim(mb_substr($reply, 0, 6_000)), 'source' => 'gemini'];
    }

    /** @param array<string, mixed> $assessment */
    private function prompt(array $assessment, string $question): string
    {
        $subjects = collect($assessment['subjects'] ?? [])->take(12)->map(fn ($subject) => sprintf('%s: %s (%s)', $subject['subject_code'] ?? 'Subject', $subject['average_grade'] ?? 'no grade', $subject['risk_level'] ?? 'unknown'))->implode("\n");

        return "You are a concise academic coach. Use only this academic summary. Do not request personal data.\nRisk: ".($assessment['risk_level'] ?? 'unknown')."\nSubjects:\n{$subjects}\n\nStudent question: {$question}";
    }

    private function logFailure(string $type, ?int $status, bool $timeout, string $errorClass): void
    {
        Log::warning('Monitoring AI request failed', ['service' => 'monitoring_ai', 'provider' => 'gemini', 'status_code' => $status, 'error_type' => $type, 'timeout' => $timeout, 'error_class' => $errorClass]);
    }
}
