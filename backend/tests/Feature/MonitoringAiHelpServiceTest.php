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
    }
}
