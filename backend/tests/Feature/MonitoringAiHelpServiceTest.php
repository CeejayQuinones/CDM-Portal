<?php

namespace Tests\Feature;

use App\Services\Monitoring\Ai\ConfiguredMonitoringAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringAiHelpServiceTest extends TestCase
{
    public function test_openai_compatible_provider_uses_configured_endpoint_without_leaking_key_into_body(): void
    {
        config()->set('services.monitoring_ai', [
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'offline-test-model',
            'base_url' => 'https://ai.invalid/v1',
            'api_key' => 'offline-secret-key',
            'timeout' => 5,
        ]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Structured review guidance.']]]], 200)]);

        $provider = app(ConfiguredMonitoringAiProvider::class);
        $this->assertTrue($provider->configured());
        $this->assertSame('Structured review guidance.', $provider->generate([
            ['role' => 'system', 'content' => 'Academic guidance only.'],
            ['role' => 'user', 'content' => 'How should I study?'],
        ]));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ai.invalid/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer offline-secret-key')
            && ! str_contains($request->body(), 'offline-secret-key'));
    }

    public function test_disabled_or_incomplete_provider_is_reported_as_unconfigured(): void
    {
        config()->set('services.monitoring_ai.enabled', false);
        config()->set('services.monitoring_ai.provider', 'openai');
        config()->set('services.monitoring_ai.model', 'model');
        config()->set('services.monitoring_ai.base_url', 'https://ai.invalid');
        config()->set('services.monitoring_ai.api_key', 'key');

        $this->assertFalse(app(ConfiguredMonitoringAiProvider::class)->configured());
    }
}
