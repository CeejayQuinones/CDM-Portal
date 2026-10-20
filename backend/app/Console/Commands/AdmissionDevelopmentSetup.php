<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamSessionQuestion;
use App\Models\Admission\AdmissionProgramSetting;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionConfigurationService;
use App\Services\Admission\ProgramMatcher;
use Illuminate\Console\Command;

class AdmissionDevelopmentSetup extends Command
{
    protected $signature = 'admission:dev-setup {--dummy-bank : Explicitly create 100 labeled dummy questions for LOCAL testing} {--cleanup : Remove unused DEV-MVP questions, retire referenced ones and close the DEV-MVP cycle}';

    protected $description = 'Explicit local-only Admission fixture setup; never changes existing real questions or academic records';

    public function handle(AdmissionConfigurationService $configuration): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Development Admission setup is forbidden outside local/testing.');

            return self::FAILURE;
        }
        if (! $this->option('dummy-bank') && ! $this->option('cleanup')) {
            $this->error('Choose --dummy-bank or --cleanup explicitly.');

            return self::FAILURE;
        }
        $actor = User::where('status', 'active')->whereHas('role', fn ($q) => $q->where('role_name', Role::ADMIN))->first();
        $year = AcademicYear::where('status', 'active')->first();
        if (! $actor || ! $year) {
            $this->error('An existing active Admin and academic year are required. No accounts or academic data were created.');

            return self::FAILURE;
        }
        try {
            $configuration->locked($actor, function () use ($configuration, $actor, $year) {
                if ($this->option('cleanup')) {
                    foreach (AdmissionExamQuestion::where('question_code', 'like', 'DEV-MVP-%')->get() as $question) {
                        if (AdmissionExamSessionQuestion::where('question_id', $question->id)->exists()) {
                            $configuration->question($actor, array_merge($question->toArray(), ['status' => 'retired']), $question->id);
                        } else {
                            $configuration->deleteQuestion($actor, $question->id, $question->version);
                        }
                    }
                    $cycle = AdmissionCycle::where('code', 'DEV-MVP')->first();
                    if ($cycle) {
                        $configuration->cycle($actor, array_merge($cycle->toArray(), ['status' => 'closed', 'expected_updated_at' => $cycle->updated_at->toISOString()]), $cycle->id);
                    }

                    return;
                }
                if (AdmissionExamQuestion::where('question_code', 'not like', 'DEV-MVP-%')->exists()) {
                    throw new \RuntimeException('Existing non-development question bank detected. No dummy questions added.');
                }
                $cycle = AdmissionCycle::where('code', 'DEV-MVP')->first();
                if (! $cycle) {
                    $configuration->cycle($actor, [
                        'code' => 'DEV-MVP', 'name' => 'DEVELOPMENT ONLY — Admission browser testing', 'academic_year_id' => $year->id,
                        'status' => 'open', 'opens_at' => now()->subDay()->toIso8601String(), 'closes_at' => now()->addDays(30)->toIso8601String(), 'confirmation_closes_at' => now()->addDays(31)->toIso8601String(),
                    ]);
                }
                foreach (ProgramMatcher::INTEREST_CATEGORIES as $topicIndex => $topic) {
                    for ($i = 1; $i <= 20; $i++) {
                        $code = 'DEV-MVP-'.($topicIndex + 1).'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                        if (AdmissionExamQuestion::where('question_code', $code)->exists()) {
                            continue;
                        }
                        $configuration->question($actor, [
                            'question_code' => $code, 'topic' => $topic, 'question_text' => '[DEVELOPMENT TEST ONLY] '.$topic.' fixture '.$i.': select option A. This is not a school examination question.',
                            'option_a' => 'Test answer A', 'option_b' => 'Test answer B', 'option_c' => 'Test answer C', 'option_d' => 'Test answer D',
                            'correct_answer' => 'A', 'difficulty' => 'easy', 'status' => 'active',
                        ]);
                    }
                }
                // Exact source code matches only. Never rename, create, or fuzzy-map academic courses.
                $catalog = json_decode(file_get_contents(resource_path('admission/reference-programs.json')), true, flags: JSON_THROW_ON_ERROR);
                foreach ($catalog as $source) {
                    $course = Course::where('course_code', $source['code'])->where('status', 'active')->first();
                    if (! $course || AdmissionProgramSetting::where('course_id', $course->id)->exists()) {
                        continue;
                    }
                    if (! $source['recommendation_profile']) {
                        continue;
                    }
                    $configuration->program($actor, $course->id, [
                        'status' => 'active', 'is_recommendable' => $source['is_recommendable'], 'program_type' => $source['program_type'],
                        'description' => $source['description'], 'duration' => $source['duration'], 'subjects' => $source['subjects'], 'career_paths' => $source['career_paths'],
                        'recommendation_profile' => $source['recommendation_profile'], 'display_order' => $source['display_order'],
                    ]);
                }
            });
            $this->info($this->option('cleanup') ? 'DEV-MVP unused questions removed; referenced questions retired; test cycle closed. Exam/audit evidence and approved program settings retained.' : 'DEV-MVP local fixture ready. 100 dummy questions, answer A. Existing academic identities were not changed.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
