<?php

namespace App\Services\Admission;

class ProgramMatcher
{
    public const VERSION = '1';

    public const INTEREST_CATEGORIES = ['General Mathematics', 'Science', 'Reading Comprehension', 'Logical Reasoning', 'Digital Literacy'];

    public function match(array $scores, array $maximums, array $interests, array $courses): array
    {
        $evidence = [];
        foreach ($maximums as $category => $maximum) {
            $correct = $scores[$category] ?? null;
            if (! is_numeric($maximum) || $maximum <= 0 || ! is_numeric($correct) || $correct < 0 || $correct > $maximum) {
                return $this->emptyResult('The saved exam evidence is incomplete or inconsistent. Please contact the Registrar.');
            }
            $evidence[$category] = ['correct' => (int) $correct, 'total' => (int) $maximum, 'percentage' => round($correct / $maximum * 100, 2)];
        }
        if (! $evidence || array_sum(array_column($evidence, 'correct')) <= 0) {
            return $this->emptyResult('There is not enough verified exam evidence to rank programs. Please discuss your interests and assessment with a school adviser.');
        }
        $ranked = [];
        foreach ($courses as $course) {
            $exam = $interest = $weights = 0;
            $complete = true;
            foreach ($course['recommendation_profile'] ?? [] as $category => $weight) {
                if (! is_numeric($weight) || $weight <= 0) {
                    continue;
                }
                $category = $category === 'Technical Aptitude' ? 'Digital Literacy' : $category;
                if (! isset($evidence[$category])) {
                    $complete = false;
                    break;
                }
                $exam += $evidence[$category]['percentage'] * $weight;
                $interest += (($interests[$category] ?? 1) - 1) / 4 * 100 * $weight;
                $weights += $weight;
            }
            if (! $complete || ! $weights) {
                continue;
            }
            $exam /= $weights;
            $interest /= $weights;
            $ranked[] = ['course_id' => $course['id'], 'course_code' => $course['code'], 'course_name' => $course['name'],
                'score' => round($interests ? $exam * .8 + $interest * .2 : $exam, 2),
                'exam_match' => round($exam, 2), 'interest_match' => $interests ? round($interest, 2) : null];
        }
        usort($ranked, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['course_code'], $b['course_code']));
        if (! $ranked || $ranked[0]['score'] <= 0) {
            return $this->emptyResult('No active program has sufficient matching evidence. Please contact a school adviser.');
        }

        return ['status' => 'ready', 'ranked_programs' => $ranked, 'evidence' => $evidence,
            'tied_top_codes' => array_column(array_values(array_filter($ranked, fn ($p) => $p['score'] === $ranked[0]['score'])), 'course_code'),
            'method' => $interests ? '80% exam alignment + 20% stated interests' : 'Exam alignment only',
            'limitation' => 'Preliminary guidance, not admission eligibility or a probability of success. Short assessments provide limited evidence. The weighting has not been validated against student outcomes.'];
    }

    private function emptyResult(string $message): array
    {
        return ['status' => 'insufficient_evidence', 'message' => $message, 'ranked_programs' => [], 'evidence' => []];
    }
}
