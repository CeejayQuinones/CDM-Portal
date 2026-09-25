<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionProgramSetting;
use App\Models\Admission\AdmissionRecommendation;
use App\Models\Role;
use App\Models\User;

class AdmissionRecommendationService
{
    public function __construct(private AdmissionExamService $exams, private ProgramMatcher $matcher, private ProgramRecommendationAI $ai) {}

    public function recommend(User $user, ?array $interests = null): array
    {
        AdmissionAccess::require($user, $interests === null ? 'read' : 'write');
        $published = $this->exams->published($user)['result'];
        abort_unless($published, 403, 'A published result is required for program guidance.');
        $result = AdmissionExamResult::findOrFail($published['id']);
        $saved = AdmissionRecommendation::where('result_id', $result->id)->where('result_version', $result->version)->latest('id')->first();
        if ($interests === null && $saved) {
            return $this->safe($result, $saved->explanations, $saved->interests);
        }
        $ratings = $interests ?? [];
        $catalog = AdmissionProgramSetting::with('course')->where('status', 'active')->where('is_recommendable', true)
            ->whereHas('course', fn ($q) => $q->where('status', 'active'))->orderBy('course_id')->get()->map(fn ($s) => [
                'id' => $s->course_id, 'code' => $s->course->course_code, 'name' => $s->course->course_name,
                'description' => $s->description, 'subjects' => $s->subjects, 'career_paths' => $s->career_paths, 'recommendation_profile' => $s->recommendation_profile,
            ])->sortBy('code')->values()->all();
        $fingerprint = hash('sha256', json_encode([ProgramMatcher::VERSION, $result->id, $result->version, $result->category_scores, $result->category_maximums, $ratings, $catalog, config('admission.gemini.model')]));
        $existing = AdmissionRecommendation::where('result_id', $result->id)->where('input_fingerprint', $fingerprint)->first();
        if ($existing) {
            return $this->safe($result, $existing->explanations, $existing->interests);
        }
        $matches = $this->matcher->match($result->category_scores, $result->category_maximums, $ratings, $catalog);
        $matches['ai_status'] = 'not_requested';
        if ($interests !== null && $matches['status'] === 'ready') {
            try {
                $matches['ai_explanations'] = $this->ai->explain($matches, $ratings, $catalog);
                $matches['ai_status'] = 'generated';
            } catch (\Throwable $e) {
                // Do not log evidence, provider responses or credentials.
                $matches['ai_status'] = 'unavailable';
            }
        }

        // Provider runs outside locks. Recheck publication/version/latest attempt before saving.
        return $this->exams->locked($user, function ($actor) use ($result, $fingerprint, $catalog, $ratings, $matches, $interests) {
            AdmissionAccess::require($actor, $interests === null ? 'read' : 'write');
            $current = $this->exams->published($actor)['result'];
            abort_unless($current && $current['id'] === $result->id && $current['version'] === $result->version, 409, 'Result changed. Reload guidance.');
            // Student history reads must not create or regenerate evidence.
            if ($actor->role->role_name === Role::STUDENT) {
                return $this->safe($result, $matches, $ratings);
            }
            $row = AdmissionRecommendation::firstOrCreate(['result_id' => $result->id, 'input_fingerprint' => $fingerprint], [
                'result_version' => $result->version, 'interests' => $ratings, 'catalog_snapshot' => $catalog, 'matcher_version' => ProgramMatcher::VERSION,
                'model_name' => $matches['ai_status'] === 'generated' ? config('admission.gemini.model') : null,
                'generation_status' => $matches['status'] === 'ready' ? 'ready' : 'unavailable', 'ai_status' => $matches['ai_status'],
                'ranked_programs' => $matches['ranked_programs'], 'evidence' => $matches['evidence'], 'explanations' => $matches,
                'top_course_id' => count($matches['tied_top_codes'] ?? []) === 1 ? $matches['ranked_programs'][0]['course_id'] : null, 'generated_at' => now(),
            ]);

            return $this->safe($result, $row->explanations, $ratings);
        });
    }

    private function safe(AdmissionExamResult $result, array $matches, array $interests): array
    {
        if ($result->registrar_pass) {
            unset($matches['evidence'],$matches['ai_explanations']);
            $matches['ranked_programs'] = array_map(fn ($p) => array_intersect_key($p, array_flip(['course_id', 'course_code', 'course_name'])), $matches['ranked_programs']);
        }

        return ['recommendation' => $matches, 'interests' => $interests, 'result_id' => $result->id, 'result_version' => $result->version];
    }
}
