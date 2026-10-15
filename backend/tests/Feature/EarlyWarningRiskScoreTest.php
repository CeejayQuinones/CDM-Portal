<?php

namespace Tests\Feature;

use App\Services\EarlyWarningService;
use Tests\TestCase;

class EarlyWarningRiskScoreTest extends TestCase
{
    public function test_weighted_score_marks_high_when_grades_and_quizzes_are_weak(): void
    {
        $result = app(EarlyWarningService::class)->composeRisk([
            'grade_risk' => 90,
            'trend_drop' => 70,
            'weak_quizzes' => 80,
            'incomplete' => 40,
        ]);

        $this->assertGreaterThanOrEqual(70, $result['risk_score']);
        $this->assertSame('high', $result['risk_level']);
    }

    public function test_stable_signals_stay_low_risk(): void
    {
        $result = app(EarlyWarningService::class)->composeRisk([
            'grade_risk' => 5,
            'trend_drop' => 0,
            'weak_quizzes' => 0,
            'incomplete' => 0,
        ]);

        $this->assertLessThan(40, $result['risk_score']);
        $this->assertSame('low', $result['risk_level']);
    }

    public function test_weak_quizzes_alone_can_raise_a_floor_to_moderate(): void
    {
        $result = app(EarlyWarningService::class)->composeRisk([
            'grade_risk' => 10,
            'trend_drop' => 0,
            'weak_quizzes' => 20,
            'incomplete' => 0,
            'force_moderate' => true,
        ]);

        $this->assertGreaterThanOrEqual(40, $result['risk_score']);
        $this->assertSame('moderate', $result['risk_level']);
    }
}
