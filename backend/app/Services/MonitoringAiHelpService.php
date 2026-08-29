<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitoringAiHelpService
{
    public function __construct(
        private readonly EarlyWarningService $earlyWarningService,
    ) {
    }

    public function isLiveAiConfigured(): bool
    {
        $provider = config('ai.provider');

        if ($provider === 'ollama') {
            return true;
        }

        return filled(config('ai.api_key'));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{source: string, provider: string, reply: string, summary: string, advice: string, actions: list<string>, prevention_note: string}
     */
    public function generateHelp(int $studentId, ?string $question = null, array $history = []): array
    {
        $assessment = $this->earlyWarningService->assessByStudentId($studentId);

        if (! $assessment) {
            return [
                'source' => 'none',
                'provider' => 'none',
                'reply' => 'Wala pang grade record para masuri. Maghintay muna ng grade release, tapos buksan ulit ang AI Help.',
                'summary' => 'No grade record found.',
                'advice' => 'This student has no approved grades to analyze yet.',
                'actions' => ['Wait for grade releases, then reopen AI Help.'],
                'prevention_note' => 'AI Help needs grade data before it can coach a student.',
            ];
        }

        $history = $this->normalizeHistory($history);
        $latestQuestion = $question ?: (collect($history)->reverse()->firstWhere('role', 'user')['content'] ?? null);

        if ($this->isLiveAiConfigured()) {
            try {
                $raw = $this->callProvider($assessment, $history, $latestQuestion);

                return $this->parseAiResponse($raw, $assessment, true, $latestQuestion);
            } catch (Throwable $exception) {
                Log::warning('Monitoring AI provider failed; using CDM coach fallback.', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $this->coachFallback($assessment, $latestQuestion, $history);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array{role: string, content: string}>
     */
    private function normalizeHistory(array $history): array
    {
        $normalized = [];

        foreach (array_slice($history, -12) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 2000),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $assessment
     */
    private function systemPrompt(array $assessment): string
    {
        $subjects = collect($assessment['subjects'] ?? [])->map(function (array $subject) {
            return sprintf(
                '%s (%s): Prelim=%s, Midterm=%s, Final=%s, avg=%s, risk=%s',
                $subject['subject_code'],
                $subject['subject_name'],
                $subject['periods']['Prelim'] ?? 'n/a',
                $subject['periods']['Midterm'] ?? 'n/a',
                $subject['periods']['Final'] ?? 'n/a',
                $subject['average_grade'] ?? 'n/a',
                $subject['risk_label'],
            );
        })->implode("\n");

        return <<<PROMPT
You are CDM Portal AI Help, a friendly academic chatbot coach for Colegio de Montalban.
Chat naturally like a helpful tutor/adviser. Be practical, kind, and specific.
Focus on preventing failing grades. You may reply in Filipino, English, or Taglish to match the user.

Student context (always use this):
Student: {$assessment['student_name']} ({$assessment['student_number']})
Course: {$assessment['course_code']}
Overall risk: {$assessment['risk_label']}
Trend: {$assessment['trend_label']}
Average grade: {$assessment['average_grade']}
Warnings:
- {$this->joinLines($assessment['warnings'] ?? [])}

Subjects:
{$subjects}

Respond in JSON only with keys:
reply (string, main chatbot answer — conversational, clear, 2-6 short paragraphs or bullets inside the string),
summary (string, one-line recap),
advice (string, optional deeper coaching),
actions (array of 3-6 concrete next steps when useful, else empty array),
prevention_note (string, short tip to avoid failing).
PROMPT;
    }

    /**
     * @param  list<string>  $lines
     */
    private function joinLines(array $lines): string
    {
        return implode("\n- ", $lines ?: ['None']);
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @param  list<array{role: string, content: string}>  $history
     */
    private function callProvider(array $assessment, array $history, ?string $question): string
    {
        return match (config('ai.provider')) {
            'openai' => $this->callOpenAiCompatible($assessment, $history, $question),
            'ollama' => $this->callOllama($assessment, $history, $question),
            default => $this->callGemini($assessment, $history, $question),
        };
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @param  list<array{role: string, content: string}>  $history
     */
    private function callGemini(array $assessment, array $history, ?string $question): string
    {
        $model = config('ai.model', 'gemini-2.5-flash');
        $key = config('ai.api_key');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

        $contents = [];
        foreach ($history as $message) {
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ];
        }

        $latest = trim((string) $question);
        $last = end($history) ?: null;
        if ($latest !== '' && (! $last || $last['role'] !== 'user' || $last['content'] !== $latest)) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $latest]],
            ];
        }

        if ($contents === []) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Please greet me and give an opening coaching tip based on my grade risk.']],
            ];
        }

        $response = $this->http()
            ->acceptJson()
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemPrompt($assessment)]],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.55,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Gemini request failed: '.$response->body());
        }

        return (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @param  list<array{role: string, content: string}>  $history
     */
    private function callOpenAiCompatible(array $assessment, array $history, ?string $question): string
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($assessment)],
        ];

        foreach ($history as $message) {
            $messages[] = [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $message['content'],
            ];
        }

        $latest = trim((string) $question);
        $last = end($history) ?: null;
        if ($latest !== '' && (! $last || $last['role'] !== 'user' || $last['content'] !== $latest)) {
            $messages[] = ['role' => 'user', 'content' => $latest];
        }

        if (count($messages) === 1) {
            $messages[] = ['role' => 'user', 'content' => 'Please greet me and give an opening coaching tip based on my grade risk.'];
        }

        $response = $this->http()
            ->withToken((string) config('ai.api_key'))
            ->acceptJson()
            ->post(rtrim((string) config('ai.openai_base_url'), '/').'/chat/completions', [
                'model' => config('ai.model', 'gpt-4o-mini'),
                'temperature' => 0.55,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI request failed: '.$response->body());
        }

        return (string) data_get($response->json(), 'choices.0.message.content', '');
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @param  list<array{role: string, content: string}>  $history
     */
    private function callOllama(array $assessment, array $history, ?string $question): string
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($assessment)],
        ];

        foreach ($history as $message) {
            $messages[] = [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $message['content'],
            ];
        }

        $latest = trim((string) $question);
        $last = end($history) ?: null;
        if ($latest !== '' && (! $last || $last['role'] !== 'user' || $last['content'] !== $latest)) {
            $messages[] = ['role' => 'user', 'content' => $latest];
        }

        if (count($messages) === 1) {
            $messages[] = ['role' => 'user', 'content' => 'Please greet me and give an opening coaching tip based on my grade risk.'];
        }

        $response = $this->http()
            ->acceptJson()
            ->post(rtrim((string) config('ai.ollama_base_url'), '/').'/api/chat', [
                'model' => config('ai.model', 'llama3.2'),
                'stream' => false,
                'format' => 'json',
                'messages' => $messages,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Ollama request failed: '.$response->body());
        }

        return (string) data_get($response->json(), 'message.content', '');
    }

    private function http()
    {
        return Http::timeout(config('ai.timeout', 45))
            ->withOptions([
                'verify' => (bool) config('ai.verify_ssl', true),
            ]);
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array{source: string, provider: string, reply: string, summary: string, advice: string, actions: list<string>, prevention_note: string}
     */
    private function parseAiResponse(string $raw, array $assessment, bool $live, ?string $question): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```json\s*|\s*```$/', '', $cleaned) ?? $cleaned;
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            return $this->coachFallback($assessment, $question, []);
        }

        $actions = $decoded['actions'] ?? [];
        if (! is_array($actions)) {
            $actions = [];
        }

        $reply = trim((string) ($decoded['reply'] ?? ''));
        if ($reply === '') {
            $reply = trim((string) ($decoded['advice'] ?? $decoded['summary'] ?? ''));
        }

        if ($reply === '') {
            return $this->coachFallback($assessment, $question, []);
        }

        return [
            'source' => $live ? 'live-ai' : 'cdm-coach',
            'provider' => $live ? (string) config('ai.provider') : 'cdm-coach',
            'reply' => $reply,
            'summary' => (string) ($decoded['summary'] ?? $assessment['headline']),
            'advice' => (string) ($decoded['advice'] ?? $reply),
            'actions' => array_values(array_filter(array_map('strval', $actions))),
            'prevention_note' => (string) ($decoded['prevention_note'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @param  list<array{role: string, content: string}>  $history
     * @return array{source: string, provider: string, reply: string, summary: string, advice: string, actions: list<string>, prevention_note: string}
     */
    private function coachFallback(array $assessment, ?string $question, array $history): array
    {
        $support = $this->earlyWarningService->generateSupportPlan($assessment);
        $risky = collect($assessment['subjects'] ?? [])
            ->filter(fn (array $subject) => in_array($subject['risk_level'], ['high', 'moderate'], true))
            ->pluck('subject_code')
            ->all();

        $focus = $risky ? implode(', ', $risky) : 'your heaviest subjects';
        $turn = count($history) + ($question ? 1 : 0);

        if (! $question) {
            $reply = "Hi! I'm your CDM AI Help coach for {$assessment['student_name']}.\n\n"
                ."Right now the signal is {$assessment['risk_label']} with a {$assessment['trend_label']} trend (avg {$assessment['average_grade']}). "
                ."Best focus first: {$focus}.\n\n"
                .'Ask me anything — study plan for this week, how to recover a subject, or what to do before the next exam.';
        } elseif ($turn > 2 && str_contains(mb_strtolower($question), 'salamat')) {
            $reply = "Walang anuman! Keep checking your grades weekly and message me again if a subject dips. You've got this.";
        } else {
            $reply = "Got it — about “{$question}”.\n\n"
                ."Based on the current {$assessment['risk_label']} risk, prioritize {$focus}. "
                ."Use short daily blocks (45–90 mins), clarify one topic with your professor this week, and track every quiz so the next graded activity isn't a surprise.\n\n"
                .'Tell me which subject you want to tackle next and I’ll break it down.';
        }

        return [
            'source' => 'cdm-coach',
            'provider' => 'cdm-coach',
            'reply' => $reply,
            'summary' => $support['summary'],
            'advice' => $reply,
            'actions' => $support['actions'],
            'prevention_note' => $support['prevention_note'],
        ];
    }
}
