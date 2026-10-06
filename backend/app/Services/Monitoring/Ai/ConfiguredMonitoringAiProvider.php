<?php

namespace App\Services\Monitoring\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ConfiguredMonitoringAiProvider implements MonitoringAiProvider
{
    private const SUPPORTED = ['openai', 'gemini', 'ollama'];

    public function configured(): bool
    {
        if (! (bool) config('services.monitoring_ai.enabled', false)) {
            return false;
        }

        $provider = $this->name();

        return in_array($provider, self::SUPPORTED, true)
            && filled(config('services.monitoring_ai.model'))
            && filled(config('services.monitoring_ai.base_url'))
            && ($provider === 'ollama' || filled(config('services.monitoring_ai.api_key')));
    }

    public function name(): string
    {
        return strtolower(trim((string) config('services.monitoring_ai.provider', 'disabled')));
    }

    public function generate(array $messages): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Monitoring AI provider is not configured.');
        }

        return match ($this->name()) {
            'openai' => $this->openAi($messages),
            'gemini' => $this->gemini($messages),
            'ollama' => $this->ollama($messages),
            default => throw new RuntimeException('Unsupported Monitoring AI provider.'),
        };
    }

    private function openAi(array $messages): string
    {
        $response = $this->request()
            ->withToken((string) config('services.monitoring_ai.api_key'))
            ->post($this->url('/chat/completions'), [
                'model' => config('services.monitoring_ai.model'),
                'messages' => $messages,
                'temperature' => 0.25,
                'max_tokens' => 700,
            ])->throw();

        return $this->reply(data_get($response->json(), 'choices.0.message.content'));
    }

    private function gemini(array $messages): string
    {
        $prompt = collect($messages)->map(fn (array $message): string => strtoupper($message['role']).":\n".$message['content'])->implode("\n\n");
        $model = rawurlencode((string) config('services.monitoring_ai.model'));
        $response = $this->request()
            ->withHeaders(['x-goog-api-key' => (string) config('services.monitoring_ai.api_key')])
            ->post($this->url("/v1beta/models/{$model}:generateContent"), [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.25, 'maxOutputTokens' => 700],
            ])->throw();

        return $this->reply(data_get($response->json(), 'candidates.0.content.parts.0.text'));
    }

    private function ollama(array $messages): string
    {
        $response = $this->request()->post($this->url('/api/chat'), [
            'model' => config('services.monitoring_ai.model'),
            'messages' => $messages,
            'stream' => false,
            'options' => ['temperature' => 0.25],
        ])->throw();

        return $this->reply(data_get($response->json(), 'message.content'));
    }

    private function request()
    {
        $timeout = min(60, max(1, (int) config('services.monitoring_ai.timeout', 15)));

        return Http::acceptJson()->asJson()->connectTimeout(min(10, $timeout))->timeout($timeout);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.monitoring_ai.base_url'), '/').$path;
    }

    private function reply(mixed $reply): string
    {
        if (! is_string($reply) || trim($reply) === '') {
            throw new RuntimeException('Monitoring AI provider returned an invalid response.');
        }

        return trim($reply);
    }
}
