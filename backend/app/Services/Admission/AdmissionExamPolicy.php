<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionExamQuestion;

class AdmissionExamPolicy
{
    // Null policies and legacy session snapshots retain the original MVP meaning.
    public static function normalize(?array $policy): array
    {
        $policy ??= [];
        $counts = $policy['category_counts'] ?? array_fill_keys(ProgramMatcher::INTEREST_CATEGORIES, $policy['questions_per_topic'] ?? 20);

        return [
            'title' => $policy['title'] ?? 'General Entrance Exam',
            'status' => $policy['status'] ?? 'active',
            'duration_minutes' => $policy['duration_minutes'] ?? 120,
            'passing_score' => $policy['passing_score'] ?? 75,
            'max_attempts' => $policy['max_attempts'] ?? 2,
            'categories' => ProgramMatcher::INTEREST_CATEGORIES,
            'category_counts' => $counts,
            'question_count' => array_sum($counts),
        ];
    }

    public static function bank()
    {
        return AdmissionExamQuestion::where('status', 'active')
            ->when(app()->environment('production'), fn ($q) => $q->where('question_code', 'not like', 'DEV-MVP-%'));
    }

    public static function readiness(array $policy): array
    {
        $counts = self::bank()->selectRaw('topic, count(*) as total')->groupBy('topic')->pluck('total', 'topic')->all();
        $ready = true;
        foreach ($policy['category_counts'] as $topic => $required) {
            $ready = $ready && ($counts[$topic] ?? 0) >= $required;
        }

        return ['counts' => $counts, 'requirements' => $policy['category_counts'], 'ready' => $ready, 'enabled' => $policy['status'] === 'active'];
    }

    public static function configuration(AdmissionCycle $cycle): array
    {
        $policy = self::normalize($cycle->exam_policy);

        return ['cycle_id' => $cycle->id, 'cycle' => $cycle->name, 'cycle_status' => $cycle->status,
            'version' => $cycle->policy_version, 'policy' => $policy, 'readiness' => self::readiness($policy)];
    }
}
