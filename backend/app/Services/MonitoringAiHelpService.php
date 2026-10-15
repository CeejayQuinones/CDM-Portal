<?php

namespace App\Services;

use App\Models\MonitoringPerformanceRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

                return $this->parseAiResponse($raw, $assessment, true, $latestQuestion, $history);
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
You are CDM Portal AI Help, a conversational academic tutor for Colegio de Montalban.
Reply like a real chat assistant: answer the latest message first, in the user's language (Filipino, English, or Taglish).
Use earlier user and assistant turns when the student follows up ("that", "example", "more", "paano yun", "sige").
Do not restart the conversation or repeat a previous answer.

Grade context is background only. Mention risk, average, or subjects when it changes the answer (grades, failing, what to study first, a named subject). Do not paste the same coaching paragraph, risk snapshot, or action list on every turn.
Never answer with a generic script that would fit any question. Suggested chips are optional; free-form text is the real conversation.
If the topic is outside academics, answer it briefly, then offer study help only if it fits.

Background (use only when relevant):
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
reply (string, the conversational answer to the latest message — not a reused template),
summary (string, one-line recap of THIS reply),
advice (string, optional extra detail, or the same reply),
actions (array of concrete next steps only when the user asked for a plan or checklist, otherwise []),
prevention_note (string, a short tip only when failure risk is the topic, otherwise "").
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
    private function parseAiResponse(string $raw, array $assessment, bool $live, ?string $question, array $history = []): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```json\s*|\s*```$/m', '', $cleaned) ?? $cleaned;
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            // Live models sometimes return plain text — still treat it as a real answer.
            if (mb_strlen($cleaned) >= 24 && ! str_starts_with(ltrim($cleaned), '{')) {
                return [
                    'source' => $live ? 'live-ai' : 'cdm-coach',
                    'provider' => $live ? (string) config('ai.provider') : 'cdm-coach',
                    'reply' => $cleaned,
                    'summary' => (string) ($assessment['headline'] ?? 'AI Help reply'),
                    'advice' => $cleaned,
                    'actions' => [],
                    'prevention_note' => '',
                ];
            }

            return $this->coachFallback($assessment, $question, $history);
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
            return $this->coachFallback($assessment, $question, $history);
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
        $subjects = collect($assessment['subjects'] ?? []);
        $risky = $subjects
            ->filter(fn (array $subject) => in_array($subject['risk_level'] ?? '', ['high', 'moderate'], true))
            ->values();
        $riskyCodes = $risky->pluck('subject_code')->filter()->all();
        $focus = $riskyCodes ? implode(', ', $riskyCodes) : 'your heaviest subjects';
        $name = (string) ($assessment['student_name'] ?? 'student');
        $risk = (string) ($assessment['risk_label'] ?? 'unknown risk');
        $trend = (string) ($assessment['trend_label'] ?? 'steady');
        $avg = (string) ($assessment['average_grade'] ?? 'n/a');
        $q = trim((string) $question);

        $actions = [];
        $prevention = '';

        if ($q === '') {
            $reply = "Hi {$name}! Ako ang CDM AI Help coach mo.\n\n"
                ."Ask anything in your own words. I will use your record ({$risk}, {$trend}, avg {$avg}) only when the question is about grades or what to study first.";
        } else {
            $composed = $this->composeFreeformCoachReply($q, $assessment, $risky, $focus, $name, $risk, $trend, $avg, $history);
            $reply = $composed['reply'];
            $actions = $composed['actions'];
            $prevention = $composed['prevention_note'];
        }

        return [
            'source' => 'cdm-coach',
            'provider' => 'cdm-coach',
            'reply' => $reply,
            'summary' => $support['summary'] ?? "Coaching for {$name}",
            'advice' => $reply,
            'actions' => array_values(array_filter(array_map('strval', $actions))),
            'prevention_note' => $prevention,
        ];
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $risky
     * @param  list<array{role: string, content: string}>  $history
     * @return array{reply: string, actions: list<string>, prevention_note: string}
     */
    private function composeFreeformCoachReply(
        string $q,
        array $assessment,
        $risky,
        string $focus,
        string $name,
        string $risk,
        string $trend,
        string $avg,
        array $history,
    ): array {
        [$priorUser, $priorAssistant] = $this->previousCoachTurns($history, $q);
        $followUp = $this->isCoachFollowUp($q, $priorUser);
        $anchor = $followUp && $priorUser !== '' ? $priorUser : $q;
        $topic = $this->topicPhrase($this->contentTerms($anchor), $this->clip($anchor, 90));
        $intent = $this->classifyCoachIntent($q, mb_strtolower($q."\n".$anchor));
        $filipino = $this->prefersFilipino($q) || ($followUp && $this->prefersFilipino($anchor));
        $matched = $this->detectSubjectMention(mb_strtolower($q."\n".$anchor), $assessment['subjects'] ?? []);
        $asked = $this->clip($q, 160);

        $parts = array_values(array_filter([
            in_array($intent, ['greet', 'thanks'], true) ? '' : $this->coachOpening($q, $topic, $followUp, $priorUser, $priorAssistant),
            $this->coachReplyBody($intent, $topic, $asked, $filipino, $name, $focus, $risky, $risk, $trend, $avg),
            $this->coachContextAside($intent, $matched, $risk, $trend, $avg),
        ]));

        return [
            'reply' => implode("\n\n", $parts),
            'actions' => $this->coachActions($intent, $topic),
            'prevention_note' => in_array($intent, ['fail', 'prep', 'plan'], true)
                ? 'Short review and sleep beat an all-night cram.'
                : '',
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{0: string, 1: string}
     */
    private function previousCoachTurns(array $history, string $question): array
    {
        $turns = [];
        foreach ($history as $message) {
            if (! is_array($message)) {
                continue;
            }
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '' || $content === 'Thinking…') {
                continue;
            }
            $turns[] = [
                'role' => ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        while ($turns !== [] && $turns[array_key_last($turns)]['role'] === 'user' && $turns[array_key_last($turns)]['content'] === $question) {
            array_pop($turns);
        }

        $priorUser = '';
        $priorAssistant = '';
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if ($priorAssistant === '' && $turns[$i]['role'] === 'assistant') {
                $priorAssistant = $turns[$i]['content'];
            }
            if ($priorUser === '' && $turns[$i]['role'] === 'user') {
                $priorUser = $turns[$i]['content'];
            }
            if ($priorUser !== '' && $priorAssistant !== '') {
                break;
            }
        }

        return [$priorUser, $priorAssistant];
    }

    private function isCoachFollowUp(string $question, string $priorUser): bool
    {
        if ($priorUser === '') {
            return false;
        }

        $terms = $this->contentTerms($question);
        if ($terms === []) {
            return true;
        }

        $prior = array_fill_keys($this->contentTerms($priorUser), true);
        foreach ($terms as $term) {
            if (! isset($prior[$term])) {
                return false;
            }
        }

        return true;
    }

    private function classifyCoachIntent(string $question, string $combinedLower): string
    {
        $current = mb_strtolower($question);
        if ($this->isGreetingOnly($question)) {
            return 'greet';
        }
        if ($this->matchesAny($current, ['salamat', 'thank', 'thanks'])) {
            return 'thanks';
        }
        if ($this->matchesAny($combinedLower, ['example', 'halimbawa', 'sample'])) {
            return 'example';
        }
        if ($this->matchesAny($combinedLower, ['difference', 'compare', 'versus', 'kaysa']) || str_contains($combinedLower, ' vs ')) {
            return 'compare';
        }
        if ($this->matchesAny($combinedLower, ['sleep', 'tulog', 'puyat', 'insomnia'])) {
            return 'sleep';
        }
        if ($this->matchesAny($combinedLower, ['study plan', '3-day', '3 day', '5-day', '5 day', 'iskedyul', 'schedule'])) {
            return 'plan';
        }
        if ($this->matchesAny($combinedLower, ['gawan']) && $this->matchesAny($combinedLower, ['plan', 'aral', 'review'])) {
            return 'plan';
        }
        if ($this->hasWord($combinedLower, 'plan') && $this->matchesAny($combinedLower, ['study', 'aral', 'week', 'araw', 'review'])) {
            return 'plan';
        }
        if ($this->matchesAny($combinedLower, ['quiz', 'exam', 'midterm', 'finals', 'prelim', 'magprepare', 'prepare'])
            || $this->hasWord($combinedLower, 'test')
            || $this->hasWord($combinedLower, 'final')) {
            return 'prep';
        }
        if ($this->matchesAny($combinedLower, ['fail', 'bagsak', 'maiiwasan', 'prevent'])) {
            return 'fail';
        }
        if ($this->matchesAny($combinedLower, ['unahin', 'priority', 'weakest', 'pinakamahina'])) {
            return 'priority';
        }
        if ($this->matchesAny($combinedLower, ['motivate', 'pagod', 'stress', 'anxious', 'overwhelm', 'ayoko'])) {
            return 'motivate';
        }
        if ($this->matchesAny($combinedLower, ['grade', 'grades', 'standing', 'average']) || $this->hasWord($combinedLower, 'risk')) {
            return 'status';
        }
        if ($this->matchesAny($combinedLower, ['explain', 'define', 'meaning', 'ibig sabihin', 'what is', 'ano ang', 'how does', 'paano gumagana', 'teach'])) {
            return 'explain';
        }

        return 'general';
    }

    private function coachOpening(string $question, string $topic, bool $followUp, string $priorUser, string $priorAssistant): string
    {
        if ($followUp && $priorUser !== '') {
            $line = 'Still on '.$topic.', following “'.$this->clip($priorUser, 110).'”.';
            if ($priorAssistant !== '') {
                $line .= ' Earlier: '.$this->clip($priorAssistant, 140);
            }

            return $line;
        }

        return $this->pickVariant($question, [
            'About '.$topic.'.',
            'Here is a direct answer on '.$topic.'.',
            'Let’s answer '.$topic.' directly.',
            'Working from your message on '.$topic.'.',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $risky
     */
    private function coachReplyBody(
        string $intent,
        string $topic,
        string $asked,
        bool $filipino,
        string $name,
        string $focus,
        $risky,
        string $risk,
        string $trend,
        string $avg,
    ): string {
        if ($intent === 'priority') {
            return $this->priorityCoachBody($topic, $asked, $filipino, $focus, $risky);
        }

        if ($filipino) {
            return match ($intent) {
                'thanks' => "Walang anuman, {$name}. “{$asked}” — i-type ang susunod na topic, correction, o example.",
                'greet' => "Hi {$name}. “{$asked}” — tanong ka ng topic o subject. Gagamitin ko ang grades lang kapag yan ang tanong.",
                'example' => "Example para sa {$topic}:\n\nSetup — isang maliit na input na gumagamit ng {$topic}.\nMiddle — i-apply ang rule nang isang beses.\nCheck — ikumpara sa ibig sabihin ng {$topic}.\n\nI-paste ang attempt mo at ituturo ko ang unang maling step.\n“{$asked}”.",
                'compare' => "Paghambing sa {$topic}:\n\nDalawang column mula sa “{$asked}”. Sa bawat idea: para saan, isang example, at kailan mali itong gamitin.",
                'sleep' => "Kasama sa aral ang tulog para sa {$topic}.\n\nMga 7 oras kung kaya. I-review ang {$topic} nang mas maaga, hindi all-nighter. Itigil ang screen mga 30 minuto bago matulog.\n“{$asked}”.",
                'plan' => "Plan para sa {$topic}:\n\nDay 1 — listahan ng alam mo at 3 gaps sa {$topic}.\nDay 2 — dalawang 40-minute practice sa gaps lang.\nDay 3 — i-explain ang {$topic} nang walang notes, tapos ayusin ang nalaktawan.\n“{$asked}”.",
                'prep' => "Prep para sa {$topic}:\n\nIlista ang pwedeng itanong tungkol sa {$topic}. Ulitin ang isang maling item at isulat ang rule na nakalimutan. Gabi bago: 20 minutong recall, tapos tulog.\n“{$asked}”.",
                'fail' => "Para hindi mag-fail, ilagay ang susunod na study blocks sa {$topic}, hindi sa lahat ng subject nang sabay.\n\n1. Isang session sa pinakamahinang parte ng {$topic}.\n2. Isang tanong na pwedeng itanong sa klase tungkol sa {$topic}.\n3. Tingnan ang susunod na graded date para hindi masurpresa sa {$topic}.\n“{$asked}”.",
                'motivate' => "Hindi kailangan perfect session, {$name}. 25 minutes lang sa {$topic}, tapos tigil.\n“{$asked}”.",
                'status' => "Standing para sa “{$asked}”:\n\nRisk: {$risk}. Trend: {$trend}. Average: {$avg}. Watch: {$focus}.\n\nMagtanong tungkol sa isang subject kung recovery step ang gusto mo.",
                'explain' => "Simpleng paliwanag ng {$topic}:\n\n1. Isang sentence: ano ang {$topic}?\n2. I-trace ang isang example ng {$topic} at bakit ganun ang bawat step.\n3. Ulitin nang walang notes. Ang nakalimutan, yun ang susunod.\n“{$asked}”.",
                default => "Direktang sagot sa {$topic}.\n\n1. Ano ang “tapos” ngayon para sa {$topic} — isang paliwanag, isang problem, o isang desisyon.\n2. Gawin nang isang beses at isulat ang exact na stuck point.\n3. I-send ang stuck point. Sasagutin ko yun, hindi ang buong grade record.\nTanong mo: “{$asked}”.",
            };
        }

        return match ($intent) {
            'thanks' => "You're welcome, {$name}. “{$asked}” — send the next topic, a correction, or say example.",
            'greet' => "Hi {$name}. You said “{$asked}”. Ask a topic, a subject, or a follow-up in your own words.",
            'example' => "Example for {$topic}:\n\nSetup — one small input that uses {$topic}.\nMiddle — apply the rule once, slowly.\nCheck — compare the result with what {$topic} means.\n\nPaste your attempt and I will mark the first wrong step.\n“{$asked}”.",
            'compare' => "Comparison for {$topic}:\n\nMake two columns from “{$asked}”. For each idea, write what it is for, one example, and when it is the wrong tool.",
            'sleep' => "Sleep is part of studying {$topic}.\n\nAim for about 7 hours when you can. Review {$topic} earlier in the evening instead of staying up. Stop screens about 30 minutes before bed.\n“{$asked}”.",
            'plan' => "Plan for {$topic}:\n\nDay 1 — list what you know and three gaps in {$topic}.\nDay 2 — two 40-minute practice blocks on those gaps only.\nDay 3 — explain {$topic} out loud, then fix the part you skipped.\n“{$asked}”.",
            'prep' => "Prep for {$topic}:\n\nList what a quiz on {$topic} can ask. Redo one missed problem and write the rule you forgot. The night before, 20 minutes of recall, then sleep.\n“{$asked}”.",
            'fail' => "To avoid failing, put the next study blocks on {$topic}, not on every subject at once.\n\n1. One focused session on the weakest part of {$topic}.\n2. Write one precise question you can ask in class about {$topic}.\n3. Check the next graded date so a missed {$topic} item is not a surprise.\n“{$asked}”.",
            'motivate' => "You do not need a perfect session, {$name}. Set a 25-minute timer for {$topic} and stop when it rings.\n“{$asked}”.",
            'status' => "Standing that answers “{$asked}”:\n\nRisk: {$risk}. Trend: {$trend}. Average: {$avg}. Watch: {$focus}.\n\nAsk about one subject if you want a recovery step for that class only.",
            'explain' => "Plain version of {$topic}:\n\n1. Define {$topic} in one sentence you could tell a classmate.\n2. Walk one example of {$topic} and note why each step exists.\n3. Close your notes and redo {$topic}. The step you forget is the next thing to ask.\n“{$asked}”.",
            default => $this->pickVariant($asked, [
                "Direct take on {$topic}.\n\n1. Say what “done” looks like today for {$topic} — one explanation, one problem, or one decision.\n2. Do that piece once and write the exact stuck point.\n3. Send the stuck point. I will answer that, not a general study speech.\nYou asked: “{$asked}”.",
                "On {$topic}, skip the overview.\n\nStart from a concrete piece of {$topic}: a number, a line of notes, or an example. Say what you already get, then the one gap.\nYou asked: “{$asked}”.",
            ]),
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $risky
     */
    private function priorityCoachBody(string $topic, string $asked, bool $filipino, string $focus, $risky): string
    {
        $lines = $risky->take(3)->map(function (array $subject) {
            return sprintf(
                '- %s (%s): avg %s · %s',
                $subject['subject_code'] ?? 'Subject',
                $subject['subject_name'] ?? '',
                $subject['average_grade'] ?? 'n/a',
                $subject['risk_label'] ?? 'watch',
            );
        })->all();
        $list = $lines ? implode("\n", $lines) : '- '.$focus;

        if ($filipino) {
            return "Sa tanong mo tungkol sa {$topic}, unahin ito:\n{$list}\n\nAng unang session, doon lang sa pinakataas.\n“{$asked}”.";
        }

        return "For {$topic}, start here:\n{$list}\n\nUse the next session only for the top item, then say which part is unclear.\n“{$asked}”.";
    }

    /**
     * @param  array<string, mixed>|null  $matched
     */
    private function coachContextAside(string $intent, ?array $matched, string $risk, string $trend, string $avg): string
    {
        if ($matched && in_array($intent, ['fail', 'priority', 'status', 'plan', 'prep', 'general'], true)) {
            $code = $matched['subject_code'] ?? 'that subject';
            $label = $matched['risk_label'] ?? 'on watch';
            $subAvg = $matched['average_grade'] ?? 'n/a';

            return "Grade background: {$code} is {$label} (avg {$subAvg}). I will bring this up again only if you ask.";
        }

        if (in_array($intent, ['fail', 'priority', 'status'], true)) {
            return "Background: {$risk}, trend {$trend}, average {$avg}.";
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function coachActions(string $intent, string $topic): array
    {
        return match ($intent) {
            'plan' => ["Day 1: list gaps in {$topic}", "Day 2: practice {$topic}", "Day 3: explain {$topic} without notes"],
            'prep' => ["List likely questions on {$topic}", "Redo one missed {$topic} item", "Short recall the night before"],
            'fail', 'priority' => ["One block today on {$topic}", "One question for the professor about {$topic}"],
            default => [],
        };
    }

    /**
     * @param  list<string>  $terms
     */
    private function topicPhrase(array $terms, string $fallback): string
    {
        if ($terms === []) {
            return $fallback;
        }

        return implode(' ', array_slice($terms, 0, 5));
    }

    /**
     * @return list<string>
     */
    private function contentTerms(string $text): array
    {
        $skip = $this->coachSkipWords();
        $clean = mb_strtolower($text);
        $clean = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $clean) ?? $clean;
        $terms = [];
        foreach (preg_split('/\s+/u', trim($clean)) ?: [] as $word) {
            $word = trim((string) $word, '-');
            if ($word === '' || mb_strlen($word) < 3 || isset($skip[$word])) {
                continue;
            }
            $terms[$word] = true;
        }

        return array_slice(array_keys($terms), 0, 8);
    }

    /**
     * @return array<string, bool>
     */
    private function coachSkipWords(): array
    {
        static $words = null;
        if ($words === null) {
            $words = array_fill_keys([
                'a', 'an', 'the', 'and', 'or', 'but', 'if', 'to', 'of', 'for', 'in', 'on', 'at', 'by', 'with', 'from', 'about',
                'is', 'are', 'was', 'were', 'be', 'been', 'being', 'do', 'does', 'did', 'can', 'could', 'should', 'would', 'will',
                'i', 'me', 'my', 'you', 'your', 'we', 'our', 'it', 'this', 'that', 'these', 'those', 'what', 'how', 'why', 'when',
                'where', 'who', 'which', 'please', 'help', 'just', 'also', 'more', 'some', 'any', 'into', 'than', 'then', 'them',
                'they', 'their', 'there', 'here', 'tell', 'give', 'make', 'explain', 'need', 'want', 'like', 'know', 'think',
                'really', 'very', 'much', 'something', 'anything', 'not', 'dont', 'have', 'has', 'had', 'let', 'get', 'got',
                'only', 'even', 'still', 'out', 'off', 'too', 'its', 'im', 'youre', 'us', 'before', 'after', 'between',
                'ako', 'ang', 'mga', 'ano', 'paano', 'pano', 'pwede', 'puede', 'gusto', 'kasi', 'para', 'kung', 'may', 'wala',
                'hindi', 'huwag', 'tayo', 'natin', 'tulungan', 'tulong', 'paki', 'pakiusap', 'kahit', 'suggestions', 'suggested',
                'question', 'questions', 'example', 'examples', 'halimbawa', 'short', 'again', 'detail', 'details', 'show',
                'tagalog', 'english', 'ulit', 'sige', 'okay', 'sample', 'your', 'yung', 'yun', 'iyan', 'ito', 'lang', 'naman',
                'po', 'opo', 'din', 'rin', 'nya', 'niyo', 'nyo',
            ], true);
        }

        return $words;
    }

    private function prefersFilipino(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach ([
            'ako', 'ang', 'mga', 'paano', 'pano', 'ano', 'po', 'naman', 'pwede', 'puede', 'aral',
            'yung', 'tulong', 'tulungan', 'maiiwasan', 'unahin', 'gawan', 'magprepare', 'salamat',
            'kumusta', 'kamusta', 'lang', 'hindi', 'gusto',
        ] as $marker) {
            if ($this->hasWord($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isGreetingOnly(string $question): bool
    {
        $stripped = trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($question)));
        $stripped = trim((string) preg_replace('/\s+/u', ' ', $stripped));

        return preg_match('/^(hi|hello|hey|kumusta|kamusta|good morning|good evening|good afternoon|magandang umaga|magandang gabi|magandang hapon|yo|sup)( (po|there|cdm|coach))?$/u', $stripped) === 1;
    }

    private function hasWord(string $haystack, string $word): bool
    {
        return preg_match('/(?:^|\s|[?.!,])'.preg_quote($word, '/').'(?:$|\s|[?.!,])/u', $haystack) === 1;
    }

    private function clip(string $text, int $limit = 140): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1).'…';
    }

    /**
     * @param  list<string>  $options
     */
    private function pickVariant(string $seed, array $options): string
    {
        if ($options === []) {
            return '';
        }

        return $options[abs(crc32($seed)) % count($options)];
    }

    /**
     * @param  list<string>  $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = mb_strtolower(trim((string) $needle));
            if ($needle === '') {
                continue;
            }

            if (mb_strlen($needle) <= 3) {
                if (preg_match('/(?:^|\s|[?.!,])'.preg_quote($needle, '/').'(?:$|\s|[?.!,])/u', $haystack)) {
                    return true;
                }
                continue;
            }

            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $subjects
     * @return array<string, mixed>|null
     */
    private function detectSubjectMention(string $lower, array $subjects): ?array
    {
        foreach ($subjects as $subject) {
            $code = mb_strtolower(trim((string) ($subject['subject_code'] ?? '')));
            $name = mb_strtolower(trim((string) ($subject['subject_name'] ?? '')));
            if ($code !== '' && str_contains($lower, $code)) {
                return $subject;
            }
            if ($name !== '' && mb_strlen($name) >= 4 && str_contains($lower, $name)) {
                return $subject;
            }
        }

        return null;
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
                'attachment_name' => $record->attachment_name,
                'has_attachment' => filled($record->attachment_path),
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
    public function generateFlashcards(int $studentId, int $recordId): array
    {
        $source = $this->uploadedSource($studentId, $recordId);
        $context = $this->studentStudyContext($studentId);
        $focus = $source['title'];
        $prompt = 'Create exactly 8 study flashcards as JSON only (no markdown) with this shape: '
            .'{"cards":[{"front":"term or question","back":"short clear answer"}]}. '
            ."Use only the uploaded notes below. Title: {$focus}. Do not use a different subject.\n\nNOTES:\n{$source['text']}";

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
            throw new \RuntimeException('The AI could not make flashcards from this file.');
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
    public function generateSampleQuiz(int $studentId, int $recordId): array
    {
        $source = $this->uploadedSource($studentId, $recordId);
        $context = $this->studentStudyContext($studentId);
        $focus = $source['title'];
        $prompt = 'Create exactly 5 multiple-choice practice questions as JSON only (no markdown) with this shape: '
            .'{"questions":[{"prompt":"...","choices":["A","B","C","D"],"answer_index":0,"explanation":"..."}]}. '
            ."Use only the uploaded notes below. Title: {$focus}. College level. answer_index is 0-based.\n\nNOTES:\n{$source['text']}";

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
            throw new \RuntimeException('The AI could not make a quiz from this file.');
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
    public function generateStudentStudioPlan(int $studentId, int $recordId): array
    {
        $source = $this->uploadedSource($studentId, $recordId);
        $context = $this->studentStudyContext($studentId);
        $focus = $source['title'];
        $assessment = $context['assessment'] ?: [
            'student_id' => $studentId,
            'student_name' => 'Student',
            'risk_level' => 'moderate',
            'risk_label' => 'Uploaded notes',
            'subjects' => [],
        ];

        if (! $this->isLiveAiConfigured()) {
            throw new \RuntimeException('AI is not configured, so a study guide cannot be generated from this file.');
        }

        $raw = $this->callProvider(
            $assessment,
            [],
            "Write a study guide from the uploaded notes only. Title: {$focus}. "
            .'Explain the main ideas, list key terms, and give a short practice plan. Plain text.'
            ."\n\nNOTES:\n{$source['text']}",
        );
        $parsed = $this->parseAiResponse($raw, $assessment, true, $focus);
        $plan = trim((string) ($parsed['reply'] ?? ''));
        if ($plan === '') {
            throw new \RuntimeException('The AI could not make a study guide from this file.');
        }

        return [
            'topic' => $focus,
            'title' => "Study guide · {$focus}",
            'plan' => $plan,
            'week' => [],
            'source' => ($parsed['provider'] ?? '') === 'cdm-coach' ? 'cdm-coach' : 'gemini',
        ];
    }

    /**
     * @return array{topic: string, title: string, plan: string, week: list<array{day: string, focus: string, minutes: int}>, source: string}
     */
    public function generateRequestedTopicPlan(int $studentId, string $topic): array
    {
        $focus = trim($topic);
        if ($focus === '') {
            throw new \RuntimeException('Enter a topic before making a study plan.');
        }
        $focus = mb_substr($focus, 0, 180);
        $context = $this->studentStudyContext($studentId);
        $assessment = $context['assessment'] ?: [
            'student_id' => $studentId,
            'student_name' => 'Student',
            'risk_level' => 'moderate',
            'risk_label' => 'Requested topic',
            'subjects' => [],
        ];

        if (! $this->isLiveAiConfigured()) {
            throw new \RuntimeException('AI is not configured, so a study plan cannot be generated.');
        }

        $raw = $this->callProvider(
            $assessment,
            [],
            "The student asked for a study plan on this topic only: {$focus}. "
            .'Write a practical study plan for that topic. Do not switch to a different subject. Plain text.',
        );
        $parsed = $this->parseAiResponse($raw, $assessment, true, $focus);
        $plan = trim((string) ($parsed['reply'] ?? ''));
        if ($plan === '') {
            throw new \RuntimeException('The AI could not make a study plan for that topic.');
        }

        return [
            'topic' => $focus,
            'title' => "Study plan · {$focus}",
            'plan' => $plan,
            'week' => [],
            'source' => ($parsed['provider'] ?? '') === 'cdm-coach' ? 'cdm-coach' : 'gemini',
        ];
    }

    /**
     * @return array{title: string, text: string}
     */
    private function uploadedSource(int $studentId, int $recordId): array
    {
        $record = MonitoringPerformanceRecord::query()
            ->whereKey($recordId)
            ->where('student_id', $studentId)
            ->first();

        if (! $record || ! filled($record->attachment_path)) {
            throw new \RuntimeException('Upload a file before generating a review.');
        }

        $text = $this->extractAttachmentText($record);
        if (mb_strlen($text) < 40) {
            throw new \RuntimeException('This file has no readable text. Upload a TXT, DOCX, or text-based PDF.');
        }

        $title = trim((string) ($record->attachment_name ?: $record->topic ?: 'Uploaded notes'));

        return [
            'title' => mb_substr($title, 0, 180),
            'text' => $text,
        ];
    }

    private function extractAttachmentText(MonitoringPerformanceRecord $record): string
    {
        if (! $record->attachment_path || ! Storage::disk('local')->exists($record->attachment_path)) {
            return '';
        }

        $path = Storage::disk('local')->path($record->attachment_path);
        $name = (string) ($record->attachment_name ?: $path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $text = match ($extension) {
            'txt', 'csv', 'md' => (string) file_get_contents($path),
            'docx' => $this->docxText($path),
            'pdf' => $this->pdfText($path),
            default => '',
        };
        $notes = trim((string) $record->notes);
        $combined = trim($text.($notes !== '' ? "\n".$notes : ''));
        $combined = trim((string) preg_replace('/[ \t]+/', ' ', $combined));

        return mb_substr($combined, 0, 12000);
    }

    private function docxText(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            return '';
        }
        $xml = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);

        return html_entity_decode(strip_tags($xml));
    }

    private function pdfText(string $path): string
    {
        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return '';
        }
        $parts = [];
        if (preg_match_all('/\(((?:\\\\.|[^\\\\)]){3,})\)/', $raw, $matches)) {
            foreach ($matches[1] as $chunk) {
                $text = stripcslashes($chunk);
                if (preg_match('/[A-Za-z]{3,}/', $text) === 1) {
                    $parts[] = $text;
                }
            }
        }

        return implode(' ', $parts);
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
        if ($this->isLiveAiConfigured()) {
            $assessment = $assessment !== [] ? $assessment : [
                'student_name' => 'Student',
                'risk_level' => 'moderate',
                'risk_label' => 'Uploaded notes',
                'subjects' => [],
            ];
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
