<?php

namespace Tests\Feature;

use App\Services\EarlyWarningService;
use App\Services\MonitoringAiHelpService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MonitoringAiHelpServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_live_gemini_response_is_parsed(): void
    {
        config()->set('ai.provider', 'gemini');
        config()->set('ai.api_key', 'test-secret-key');
        config()->set('ai.model', 'gemini-test');
        config()->set('ai.verify_ssl', false);

        $assessment = [
            'student_name' => 'Test Student',
            'student_number' => '2026-0001',
            'course_code' => 'BSCS',
            'risk_label' => 'Moderate risk',
            'trend_label' => 'steady',
            'average_grade' => 80,
            'headline' => 'Watch closely.',
            'warnings' => ['CS101 needs attention'],
            'subjects' => [[
                'subject_code' => 'CS101',
                'subject_name' => 'Programming',
                'periods' => ['Prelim' => 78, 'Midterm' => 80, 'Final' => null],
                'average_grade' => 79,
                'risk_label' => 'Moderate risk',
            ]],
        ];

        $early = Mockery::mock(EarlyWarningService::class);
        $early->shouldReceive('assessByStudentId')->once()->with(11)->andReturn($assessment);
        $this->app->instance(EarlyWarningService::class, $early);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'reply' => 'Focus on nested loops this week.',
                                'summary' => 'Study loops',
                                'advice' => 'Practice tracing.',
                                'actions' => ['Review notes', 'Ask professor'],
                                'prevention_note' => 'Short daily drills help.',
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $reply = app(MonitoringAiHelpService::class)->generateHelp(11, 'How should I study?');

        $this->assertSame('live-ai', $reply['source']);
        $this->assertSame('Focus on nested loops this week.', $reply['reply']);
    }

    public function test_fallback_when_ai_not_configured(): void
    {
        config()->set('ai.provider', 'gemini');
        config()->set('ai.api_key', null);

        $assessment = [
            'student_name' => 'Test Student',
            'student_number' => '2026-0001',
            'course_code' => 'BSCS',
            'risk_label' => 'Low risk',
            'trend_label' => 'steady',
            'average_grade' => 90,
            'headline' => 'Stable',
            'warnings' => [],
            'subjects' => [],
            'risk_level' => 'low',
        ];

        $early = Mockery::mock(EarlyWarningService::class);
        $early->shouldReceive('assessByStudentId')->once()->with(12)->andReturn($assessment);
        $early->shouldReceive('generateSupportPlan')->once()->andReturn([
            'summary' => 'Stable',
            'actions' => ['Keep reviewing weekly.'],
            'prevention_note' => 'Stay consistent.',
        ]);
        $this->app->instance(EarlyWarningService::class, $early);

        $reply = app(MonitoringAiHelpService::class)->generateHelp(12, null);

        $this->assertSame('cdm-coach', $reply['source']);
        $this->assertStringContainsString('CDM AI Help coach', $reply['reply']);
    }

    public function test_fallback_answers_freeform_questions(): void
    {
        config()->set('ai.provider', 'gemini');
        config()->set('ai.api_key', null);

        $assessment = [
            'student_name' => 'Freeform Student',
            'student_number' => '2026-0099',
            'course_code' => 'BSIT',
            'risk_label' => 'Moderate risk',
            'trend_label' => 'declining',
            'average_grade' => 78,
            'headline' => 'Needs attention',
            'warnings' => ['CS101 dipping'],
            'subjects' => [[
                'subject_code' => 'CS101',
                'subject_name' => 'Programming',
                'periods' => ['Prelim' => 75, 'Midterm' => 70, 'Final' => null],
                'average_grade' => 72.5,
                'risk_level' => 'high',
                'risk_label' => 'High risk',
            ]],
            'risk_level' => 'moderate',
        ];

        $early = Mockery::mock(EarlyWarningService::class);
        $early->shouldReceive('assessByStudentId')->once()->with(99)->andReturn($assessment);
        $early->shouldReceive('generateSupportPlan')->once()->andReturn([
            'summary' => 'Needs attention',
            'actions' => ['Review CS101 daily.'],
            'prevention_note' => 'Stay consistent.',
        ]);
        $this->app->instance(EarlyWarningService::class, $early);

        $reply = app(MonitoringAiHelpService::class)->generateHelp(
            99,
            'Pwede mo ba ako tulungan mag-review ng nested loops kahit wala sa suggestions?',
        );

        $this->assertSame('cdm-coach', $reply['source']);
        $this->assertStringContainsString('nested loops', mb_strtolower($reply['reply']));
        $this->assertStringNotContainsString('Use short daily blocks (45–90 mins), clarify one topic', $reply['reply']);
        $this->assertStringNotContainsString('Not limited to suggested questions', $reply['reply']);
    }

    public function test_fallback_replies_differ_for_different_questions(): void
    {
        config()->set('ai.provider', 'gemini');
        config()->set('ai.api_key', null);

        $assessment = [
            'student_name' => 'Case Student',
            'student_number' => '2026-0101',
            'course_code' => 'BSCS',
            'risk_label' => 'Moderate risk',
            'trend_label' => 'declining',
            'average_grade' => 76,
            'headline' => 'Needs attention',
            'warnings' => [],
            'subjects' => [[
                'subject_code' => 'CS101',
                'subject_name' => 'Programming',
                'periods' => ['Prelim' => 74, 'Midterm' => 70, 'Final' => null],
                'average_grade' => 72,
                'risk_level' => 'high',
                'risk_label' => 'High risk',
            ]],
            'risk_level' => 'moderate',
        ];

        $early = Mockery::mock(EarlyWarningService::class);
        $early->shouldReceive('assessByStudentId')->twice()->with(101)->andReturn($assessment);
        $early->shouldReceive('generateSupportPlan')->twice()->andReturn([
            'summary' => 'Needs attention',
            'actions' => ['Review CS101 daily.'],
            'prevention_note' => 'Stay consistent.',
        ]);
        $this->app->instance(EarlyWarningService::class, $early);

        $service = app(MonitoringAiHelpService::class);
        $programming = $service->generateHelp(101, 'How do I avoid fail this semester in programming?');
        $calculus = $service->generateHelp(101, 'What should I do so I do not fail calculus?');

        $this->assertSame('cdm-coach', $programming['source']);
        $this->assertNotSame($programming['reply'], $calculus['reply']);
        $this->assertStringContainsString('programming', mb_strtolower($programming['reply']));
        $this->assertStringContainsString('calculus', mb_strtolower($calculus['reply']));
        $this->assertStringNotContainsString('calculus', mb_strtolower($programming['reply']));
        $this->assertStringNotContainsString('Here’s practical coaching using your current record', $programming['reply']);
    }

    public function test_fallback_follow_up_uses_recent_messages(): void
    {
        config()->set('ai.provider', 'gemini');
        config()->set('ai.api_key', null);

        $assessment = [
            'student_name' => 'Case Student',
            'student_number' => '2026-0102',
            'course_code' => 'BSCS',
            'risk_label' => 'Low risk',
            'trend_label' => 'steady',
            'average_grade' => 88,
            'headline' => 'Stable',
            'warnings' => [],
            'subjects' => [],
            'risk_level' => 'low',
        ];

        $early = Mockery::mock(EarlyWarningService::class);
        $early->shouldReceive('assessByStudentId')->twice()->with(102)->andReturn($assessment);
        $early->shouldReceive('generateSupportPlan')->twice()->andReturn([
            'summary' => 'Stable',
            'actions' => ['Keep reviewing weekly.'],
            'prevention_note' => 'Stay consistent.',
        ]);
        $this->app->instance(EarlyWarningService::class, $early);

        $service = app(MonitoringAiHelpService::class);
        $loops = $service->generateHelp(102, 'Can you give a short example?', [
            ['role' => 'user', 'content' => 'Explain nested loops'],
            ['role' => 'assistant', 'content' => 'A nested loop is a loop inside another loop.'],
        ]);
        $search = $service->generateHelp(102, 'Can you give a short example?', [
            ['role' => 'user', 'content' => 'What is binary search?'],
            ['role' => 'assistant', 'content' => 'Binary search halves a sorted list.'],
        ]);

        $this->assertNotSame($loops['reply'], $search['reply']);
        $this->assertStringContainsString('nested loop', mb_strtolower($loops['reply']));
        $this->assertStringContainsString('binary search', mb_strtolower($search['reply']));
        $this->assertStringContainsString('Earlier:', $loops['reply']);
    }

    public function test_provider_request_includes_chat_history(): void
    {
        config()->set('ai.provider', 'gemini');
        config()->set('ai.api_key', 'test-secret-key');
        config()->set('ai.model', 'gemini-test');
        config()->set('ai.verify_ssl', false);

        $assessment = [
            'student_name' => 'Test Student',
            'student_number' => '2026-0001',
            'course_code' => 'BSCS',
            'risk_label' => 'Moderate risk',
            'trend_label' => 'steady',
            'average_grade' => 80,
            'headline' => 'Watch closely.',
            'warnings' => [],
            'subjects' => [],
        ];

        $early = Mockery::mock(EarlyWarningService::class);
        $early->shouldReceive('assessByStudentId')->once()->with(11)->andReturn($assessment);
        $this->app->instance(EarlyWarningService::class, $early);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'reply' => 'Here is a short nested-loop example.',
                                'summary' => 'Example',
                                'advice' => 'Trace the inner loop.',
                                'actions' => [],
                                'prevention_note' => '',
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $reply = app(MonitoringAiHelpService::class)->generateHelp(11, 'Give me an example', [
            ['role' => 'user', 'content' => 'Explain nested loops'],
            ['role' => 'assistant', 'content' => 'A loop inside a loop.'],
        ]);

        $this->assertSame('live-ai', $reply['source']);
        $this->assertSame('Here is a short nested-loop example.', $reply['reply']);

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains((string) $payload, 'Explain nested loops')
                && str_contains((string) $payload, 'A loop inside a loop.')
                && str_contains((string) $payload, 'Give me an example');
        });
    }
}
