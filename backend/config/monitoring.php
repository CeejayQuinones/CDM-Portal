<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Early warning bands (0–100 risk score)
    |--------------------------------------------------------------------------
    */
    'bands' => [
        'high' => (int) env('MONITORING_HIGH_SCORE', 70),
        'moderate' => (int) env('MONITORING_MODERATE_SCORE', 40),
    ],

    'failing_average' => (float) env('MONITORING_FAILING_AVERAGE', 75),
    'watch_average' => (float) env('MONITORING_WATCH_AVERAGE', 82),
    'decline_points' => (float) env('MONITORING_DECLINE_POINTS', 3),
    'quiz_weak_percent' => (float) env('MONITORING_QUIZ_WEAK_PERCENT', 75),
    'quiz_critical_percent' => (float) env('MONITORING_QUIZ_CRITICAL_PERCENT', 60),

    /*
    | Grade risk, period drop, weak quizzes, incomplete/missing work.
    | Weights should sum to 1.
    */
    'weights' => [
        'grade' => 0.40,
        'trend' => 0.25,
        'quizzes' => 0.20,
        'incomplete' => 0.15,
    ],
];
