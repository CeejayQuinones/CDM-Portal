<?php

namespace App\Services;

use App\Models\MonitoringPerformanceRecord;
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

    /**
     * @param  array<string, mixed>  $assessment
     * @param  array<string, mixed>  $record
     * @return array{title: string, reply: string, source: string}
     */
    public function generateTopicStudyPlan(array $assessment, array $record): array
    {
        $topic = trim((string) ($record['topic'] ?? 'Focus topic'));
        $assessmentName = trim((string) ($record['assessment_name'] ?? 'Assessment'));
        $question = "Create a focused study plan for {$assessmentName} on the weak topic: {$topic}. "
            .'Score: '.($record['score'] ?? 'n/a').'/'.($record['max_score'] ?? 'n/a').'. '
            .'Instructor notes: '.trim((string) ($record['notes'] ?? 'None')).'. '
            .'Include why it matters, a 5-day plan, practice checklist, and what to ask the instructor.';

        if ($this->isLiveAiConfigured()) {
            try {
                $raw = $this->callProvider($assessment, [], $question);
                $parsed = $this->parseAiResponse($raw, $assessment, true, $question);

                return [
                    'title' => "Study plan: {$assessmentName} · {$topic}",
                    'reply' => $parsed['reply'],
                    'source' => $parsed['provider'] === 'cdm-coach' ? 'cdm-coach' : 'gemini',
                ];
            } catch (Throwable $exception) {
                Log::warning('Topic study plan AI failed; using fallback.', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $subject = trim((string) ($record['subject_code'] ?? 'your subject'));
        $fallback = "Focus topic: {$topic} ({$subject})\n\n"
            ."Day 1: Review class notes on {$topic}.\n"
            ."Day 2: Rework missed {$assessmentName} items.\n"
            ."Day 3: Practice 5 similar problems.\n"
            ."Day 4: Explain the topic out loud or to a classmate.\n"
            ."Day 5: Short self-quiz and list questions for your professor.\n\n"
            .'Ask your instructor which concept from this topic matters most for the next graded activity.';

        return [
            'title' => "Study plan: {$assessmentName} · {$topic}",
            'reply' => $fallback,
            'source' => 'cdm-coach',
        ];
    }

    /**
     * @return array{assessment: ?array<string, mixed>, topics: list<array<string, mixed>>, records: list<array<string, mixed>>}
     */
    public function studentStudyContext(int $studentId): array
    {
        $assessment = $this->earlyWarningService->assessByStudentId($studentId);
        $records = MonitoringPerformanceRecord::query()
            ->where('student_id', $studentId)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (MonitoringPerformanceRecord $record) => [
                'id' => $record->id,
                'subject_code' => $record->subject_code,
                'subject_name' => $record->subject_name,
                'assessment_name' => $record->assessment_name,
                'topic' => $record->topic,
                'score' => $record->score,
                'max_score' => $record->max_score,
                'notes' => $record->notes,
                'percent' => $record->max_score > 0 ? round(($record->score / $record->max_score) * 100, 1) : null,
            ])
            ->all();

        $topics = [];
        foreach ($records as $record) {
            $key = mb_strtolower(trim(($record['subject_code'] ?? '').'|'.($record['topic'] ?? '')));
            if ($key === '|' || isset($topics[$key])) {
                continue;
            }
            $topics[$key] = [
                'topic' => $record['topic'],
                'subject_code' => $record['subject_code'],
                'subject_name' => $record['subject_name'],
                'assessment_name' => $record['assessment_name'],
                'score' => $record['score'],
                'max_score' => $record['max_score'],
                'percent' => $record['percent'],
                'source' => 'professor',
            ];
        }

        foreach (($assessment['subjects'] ?? []) as $subject) {
            if (! in_array($subject['risk_level'] ?? '', ['high', 'moderate'], true)) {
                continue;
            }
            $key = mb_strtolower(trim(($subject['subject_code'] ?? '').'|grade-focus'));
            if (isset($topics[$key])) {
                continue;
            }
            $topics[$key] = [
                'topic' => ($subject['subject_name'] ?? $subject['subject_code'] ?? 'Subject').' fundamentals',
                'subject_code' => $subject['subject_code'] ?? null,
                'subject_name' => $subject['subject_name'] ?? null,
                'assessment_name' => 'Grade trend',
                'score' => $subject['average_grade'] ?? null,
                'max_score' => 100,
                'percent' => $subject['average_grade'] ?? null,
                'source' => 'grades',
            ];
        }

        return [
            'assessment' => $assessment,
            'topics' => array_values($topics),
            'records' => $records,
        ];
    }

    /**
     * @return array{topic: string, cards: list<array{front: string, back: string}>, source: string}
     */
    public function generateFlashcards(int $studentId, ?string $topic = null): array
    {
        $context = $this->studentStudyContext($studentId);
        $focus = $this->resolveFocusTopic($context, $topic);
        $prompt = 'Create exactly 8 study flashcards as JSON only (no markdown) with this shape: '
            .'{"cards":[{"front":"term or question","back":"short clear answer"}]}. '
            ."Focus topic: {$focus}. Help a college student recover weak mastery. Keep answers concise.";

        $parsed = $this->askJsonStudyTools($context['assessment'] ?? [], $prompt, 'flashcards');
        $cards = collect($parsed['cards'] ?? [])
            ->filter(fn ($card) => is_array($card) && filled($card['front'] ?? null) && filled($card['back'] ?? null))
            ->map(fn ($card) => [
                'front' => trim((string) $card['front']),
                'back' => trim((string) $card['back']),
            ])
            ->take(10)
            ->values()
            ->all();

        if ($cards === []) {
            $cards = $this->fallbackFlashcards($focus);
        }

        return [
            'topic' => $focus,
            'cards' => $cards,
            'source' => $parsed['source'] ?? 'cdm-coach',
        ];
    }

    /**
     * @return array{topic: string, questions: list<array{prompt: string, choices: list<string>, answer_index: int, explanation: string}>, source: string}
     */
    public function generateSampleQuiz(int $studentId, ?string $topic = null): array
    {
        $context = $this->studentStudyContext($studentId);
        $focus = $this->resolveFocusTopic($context, $topic);
        $prompt = 'Create exactly 5 multiple-choice practice questions as JSON only (no markdown) with this shape: '
            .'{"questions":[{"prompt":"...","choices":["A","B","C","D"],"answer_index":0,"explanation":"..."}]}. '
            ."Focus topic: {$focus}. College level. answer_index is 0-based.";

        $parsed = $this->askJsonStudyTools($context['assessment'] ?? [], $prompt, 'quiz');
        $questions = collect($parsed['questions'] ?? [])
            ->filter(fn ($q) => is_array($q) && filled($q['prompt'] ?? null) && is_array($q['choices'] ?? null))
            ->map(function (array $q) {
                $choices = array_values(array_map('strval', array_slice($q['choices'], 0, 4)));
                while (count($choices) < 4) {
                    $choices[] = 'None of the above';
                }
                $answer = (int) ($q['answer_index'] ?? 0);
                if ($answer < 0 || $answer > 3) {
                    $answer = 0;
                }

                return [
                    'prompt' => trim((string) $q['prompt']),
                    'choices' => $choices,
                    'answer_index' => $answer,
                    'explanation' => trim((string) ($q['explanation'] ?? 'Review class notes on this topic.')),
                ];
            })
            ->take(5)
            ->values()
            ->all();

        if ($questions === []) {
            $questions = $this->fallbackQuiz($focus);
        }

        return [
            'topic' => $focus,
            'questions' => $questions,
            'source' => $parsed['source'] ?? 'cdm-coach',
        ];
    }

    /**
     * @return array{topic: string, title: string, plan: string, week: list<array{day: string, focus: string, minutes: int}>, source: string}
     */
    public function generateStudentStudioPlan(int $studentId, ?string $topic = null): array
    {
        $context = $this->studentStudyContext($studentId);
        $focus = $this->resolveFocusTopic($context, $topic);
        $base = $this->earlyWarningService->generateStudyPlan($context['assessment'] ?? [
            'student_id' => $studentId,
            'risk_level' => 'moderate',
            'subjects' => [],
        ]);

        if ($this->isLiveAiConfigured() && ($context['assessment'] ?? null)) {
            try {
                $raw = $this->callProvider(
                    $context['assessment'],
                    [],
                    "Write a friendly 5-day recovery study plan for the weak topic: {$focus}. "
                    .'Include daily goals and practice tips. Plain text, no markdown tables.',
                );
                $parsed = $this->parseAiResponse($raw, $context['assessment'], true, $focus);

                return [
                    'topic' => $focus,
                    'title' => "Study plan · {$focus}",
                    'plan' => $parsed['reply'],
                    'week' => $base['week'] ?? [],
                    'source' => $parsed['provider'] === 'cdm-coach' ? 'cdm-coach' : 'gemini',
                ];
            } catch (Throwable $exception) {
                Log::warning('Student studio plan AI failed; using fallback.', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $plan = "Focus: {$focus}\n\n"
            ."Day 1: Review notes and mark confusing parts.\n"
            ."Day 2: Make 8 flashcards and practice them twice.\n"
            ."Day 3: Answer a short practice quiz and check explanations.\n"
            ."Day 4: Rework the weakest items from your instructor feedback.\n"
            ."Day 5: Teach the topic out loud and list questions for your professor.";

        return [
            'topic' => $focus,
            'title' => "Study plan · {$focus}",
            'plan' => $plan,
            'week' => $base['week'] ?? [],
            'source' => 'cdm-coach',
        ];
    }

    /**
     * @param  array{assessment: ?array<string, mixed>, topics: list<array<string, mixed>>, records: list<array<string, mixed>>}  $context
     */
    private function resolveFocusTopic(array $context, ?string $topic): string
    {
        $topic = trim((string) $topic);
        if ($topic !== '') {
            return mb_substr($topic, 0, 180);
        }

        $first = $context['topics'][0]['topic'] ?? null;
        if (filled($first)) {
            return (string) $first;
        }

        return 'General academic recovery';
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function askJsonStudyTools(array $assessment, string $prompt, string $mode): array
    {
        if ($assessment !== [] && $this->isLiveAiConfigured()) {
            try {
                $raw = $this->callProvider($assessment, [], $prompt."\nReturn JSON only.");
                $json = $this->extractJsonObject($raw);
                if (is_array($json)) {
                    $json['source'] = 'gemini';

                    return $json;
                }
            } catch (Throwable $exception) {
                Log::warning("Student {$mode} AI failed; using fallback.", [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return ['source' => 'cdm-coach'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJsonObject(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/\{.*\}/s', $raw, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<array{front: string, back: string}>
     */
    private function fallbackFlashcards(string $topic): array
    {
        return [
            ['front' => "What is the core idea of {$topic}?", 'back' => "State the main definition/rule in one sentence, then give one example."],
            ['front' => "Common mistake in {$topic}", 'back' => 'Skipping prerequisites or mixing similar terms. Recheck definitions first.'],
            ['front' => "How do I practice {$topic} today?", 'back' => 'Do 3 short problems, then explain your solution out loud.'],
            ['front' => "When is {$topic} used?", 'back' => 'In class activities, quizzes, and follow-up graded work for this subject.'],
            ['front' => "Quick check", 'back' => "If you cannot explain {$topic} without notes, review flashcards again."],
            ['front' => 'Ask your professor', 'back' => "Which part of {$topic} matters most for the next assessment?"],
            ['front' => 'Memory tip', 'back' => 'Link the idea to a real campus example so it sticks longer.'],
            ['front' => 'Next step', 'back' => 'Take a 5-question practice quiz on this topic after one review pass.'],
        ];
    }

    /**
     * @return list<array{prompt: string, choices: list<string>, answer_index: int, explanation: string}>
     */
    private function fallbackQuiz(string $topic): array
    {
        return [
            [
                'prompt' => "Best first step when studying {$topic}?",
                'choices' => ['Memorize random facts', 'Review key definitions', 'Skip to the hardest item', 'Ignore instructor notes'],
                'answer_index' => 1,
                'explanation' => 'Start with clear definitions before harder practice.',
            ],
            [
                'prompt' => "Why did your instructor flag {$topic}?",
                'choices' => ['It is already mastered', 'It is a weak area to recover', 'It is optional forever', 'It replaces all grades'],
                'answer_index' => 1,
                'explanation' => 'Professor topic logs highlight where support is needed.',
            ],
            [
                'prompt' => 'Most effective practice loop?',
                'choices' => ['Read once only', 'Flashcards → quiz → review misses', 'Only watch videos', 'Avoid practice questions'],
                'answer_index' => 1,
                'explanation' => 'Active recall plus checking mistakes builds mastery.',
            ],
            [
                'prompt' => 'What should you bring to consultation?',
                'choices' => ['No questions', 'Specific confusing steps', 'Only final answers', 'Unrelated topics'],
                'answer_index' => 1,
                'explanation' => 'Specific questions help instructors coach faster.',
            ],
            [
                'prompt' => 'When is a topic ready?',
                'choices' => ['You can explain and solve without notes', 'You recognized the title', 'A friend said it is easy', 'You opened the file once'],
                'answer_index' => 0,
                'explanation' => 'True readiness means you can teach and apply it.',
            ],
        ];
    }
}
