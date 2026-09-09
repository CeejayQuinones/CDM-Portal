<?php

namespace Tests\Feature;

use App\Services\MonitoringAiHelpService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MonitoringAiHelpServiceTest extends TestCase
{
    private array $assessment = [
        'risk_level' => 'moderate',
        'student_name' => 'Must Not Be Sent',
        'address' => 'Must Not Be Sent',
        'subjects' => [['subject_code' => 'CS101', 'subject_name' => 'Programming', 'average_grade' => 78, 'risk_level' => 'moderate']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.document_analysis.gemini.api_key', 'test-secret-key');
        config()->set('services.document_analysis.gemini.model', 'gemini-test');
        config()->set('services.document_analysis.gemini.timeout', 15);
    }

    public function test_valid_response_uses_minimized_context(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Try a structured review plan.']]]]]], 200)]);
        $reply = app(MonitoringAiHelpService::class)->generateHelp($this->assessment, 'How should I study?');
        $this->assertSame('Try a structured review plan.', $reply['reply']);
        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($body, 'CS101') && ! str_contains($body, 'Must Not Be Sent') && ! str_contains($body, 'test-secret-key');
        });
    }

    #[DataProvider('failedResponses')]
    public function test_provider_errors_are_sanitized(int $status): void
    {
        Log::spy();
        Http::fake(['*' => Http::response('RAW_PROVIDER_BODY secret question', $status)]);
        try {
            app(MonitoringAiHelpService::class)->generateHelp($this->assessment, 'secret question');
            $this->fail('Expected exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI help is temporarily unavailable. Please try again.', $e->getMessage());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Monitoring AI request failed' && $context['status_code'] === $status && ! str_contains(json_encode($context), 'RAW_PROVIDER_BODY') && ! str_contains(json_encode($context), 'secret question'))->once();
    }

    public static function failedResponses(): array
    {
        return [[400], [500]];
    }

    public function test_timeout_is_sanitized(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('RAW_PROVIDER_BODY'));
        try {
            app(MonitoringAiHelpService::class)->generateHelp($this->assessment, 'secret question');
            $this->fail('Expected exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('The AI service took too long to respond. Please try again.', $e->getMessage());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $c['error_type'] === 'timeout' && ! str_contains(json_encode($c), 'RAW_PROVIDER_BODY'))->once();
    }

    public function test_malformed_response_is_sanitized(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['candidates' => []], 200)]);
        try {
            app(MonitoringAiHelpService::class)->generateHelp($this->assessment, 'secret question');
            $this->fail('Expected exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI help is temporarily unavailable. Please try again.', $e->getMessage());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $c['error_type'] === 'invalid_response' && ! str_contains(json_encode($c), 'secret question'))->once();
    }
}
