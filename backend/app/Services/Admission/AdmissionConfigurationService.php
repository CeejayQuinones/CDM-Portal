<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamSessionQuestion;
use App\Models\Admission\AdmissionProgramSetting;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdmissionConfigurationService
{
    public function __construct(private AdmissionAuditWriter $audit) {}

    // All configuration writes use the existing Admin role row as a stable mutex,
    // including when there is no cycle or question yet. No auth data is changed.
    public function locked(User $user, callable $action): mixed
    {
        return DB::transaction(function () use ($user, $action) {
            $actor = AdmissionAccess::require($user, 'configure');
            Role::where('role_name', Role::ADMIN)->lockForUpdate()->firstOrFail();

            return $action($actor);
        }, 3);
    }

    public function cycle(User $user, array $data, ?int $id = null): AdmissionCycle
    {
        return $this->locked($user, function ($actor) use ($data, $id) {
            $cycle = $id ? AdmissionCycle::lockForUpdate()->findOrFail($id) : new AdmissionCycle;
            $data = Validator::make($data, [
                'code' => ['required', 'string', 'max:16', Rule::unique('admission_cycles')->ignore($id)],
                'name' => 'required|string|max:255', 'academic_year_id' => 'required|integer|exists:academic_years,id',
                'status' => ['required', Rule::in(AdmissionCycle::STATUSES)],
                'opens_at' => 'required|date', 'closes_at' => 'required|date|after:opens_at',
                'confirmation_closes_at' => 'required|date|after_or_equal:closes_at',
                'expected_updated_at' => $id ? 'required|string' : 'nullable',
            ])->validate();
            if ($id) {
                abort_unless($data['expected_updated_at'] === $cycle->updated_at->toISOString(), 409, 'Cycle changed. Reload before editing.');
            }
            unset($data['expected_updated_at']);
            foreach (['opens_at', 'closes_at', 'confirmation_closes_at'] as $field) {
                $data[$field] = Carbon::parse($data[$field])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            }

            if ($data['status'] === 'open') {
                $overlap = AdmissionCycle::where('status', 'open')->when($id, fn ($q) => $q->where('id', '!=', $id))
                    ->where('opens_at', '<', $data['closes_at'])->where('closes_at', '>', $data['opens_at'])->lockForUpdate()->exists();
                abort_if($overlap, 409, 'Another open admission cycle overlaps these dates.');
            }
            $old = $cycle->status;
            $cycle->fill($data);
            $cycle->updated_by_user_id = $actor->id;
            if (! $id) {
                $cycle->created_by_user_id = $actor->id;
            }
            // Existing timestamps are second precision; make every edit version distinct.
            if ($id && $cycle->updated_at->gte(now()->startOfSecond())) {
                $cycle->updated_at = $cycle->updated_at->addSecond();
            }
            $cycle->save();
            $action = ! $id ? 'created' : ($old !== $cycle->status && in_array($cycle->status, ['open', 'closed']) ? ($cycle->status === 'open' ? 'opened' : 'closed') : 'updated');
            $this->audit->workflow($actor, 'admission.cycle_'.$action, 'cycle', $cycle->id, metadata: ['status' => $cycle->status]);

            return $cycle;
        });
    }

    public function exam(User $user, int $id, array $data): array
    {
        return $this->locked($user, function ($actor) use ($id, $data) {
            $cycle = AdmissionCycle::lockForUpdate()->findOrFail($id);
            $rules = [
                'version' => 'required|integer|min:1', 'title' => 'required|string|max:255',
                'status' => 'required|in:draft,active,inactive',
                'duration_minutes' => 'required|integer|between:1,240',
                'passing_score' => 'required|integer|between:1,100',
                'max_attempts' => 'required|integer|between:1,2',
                'category_counts' => ['required', 'array:'.implode(',', ProgramMatcher::INTEREST_CATEGORIES)],
            ];
            foreach (ProgramMatcher::INTEREST_CATEGORIES as $topic) {
                $rules['category_counts.'.$topic] = 'required|integer|between:1,100';
            }
            $data = Validator::make($data, $rules)->validate();
            abort_unless($data['version'] === $cycle->policy_version, 409, 'Exam configuration changed. Reload.');
            unset($data['version']);
            $cycle->forceFill(['exam_policy' => AdmissionExamPolicy::normalize($data), 'policy_version' => $cycle->policy_version + 1,
                'updated_by_user_id' => $actor->id]);
            if ($cycle->updated_at->gte(now()->startOfSecond())) {
                $cycle->updated_at = $cycle->updated_at->addSecond();
            }
            $cycle->save();
            $this->audit->workflow($actor, 'admission.exam_configured', 'cycle', $cycle->id, metadata: ['version' => $cycle->policy_version]);

            return AdmissionExamPolicy::configuration($cycle);
        });
    }

    public function course(User $user, array $data, ?int $id = null): Course
    {
        return $this->locked($user, function ($actor) use ($id, $data) {
            $course = $id ? Course::lockForUpdate()->findOrFail($id) : new Course;
            $data = Validator::make($data, [
                'course_code' => ['required', 'string', 'max:20', Rule::unique('courses')->ignore($id)],
                'course_name' => 'required|string|max:150', 'department_id' => 'required|integer|exists:departments,id',
                'years' => 'required|integer|between:1,255', 'status' => 'required|in:active,inactive',
                'expected_updated_at' => $id ? 'required|string' : 'nullable',
            ])->validate();
            if ($id) {
                abort_unless($data['expected_updated_at'] === $course->updated_at->toISOString(), 409, 'Program changed. Reload.');
                if ($course->updated_at->gte(now()->startOfSecond())) {
                    $course->updated_at = $course->updated_at->addSecond();
                }
            }
            unset($data['expected_updated_at']);
            $course->fill($data)->save();
            $this->audit->workflow($actor, 'admission.course_'.($id ? 'updated' : 'created'), 'program', $course->id, metadata: ['status' => $course->status]);

            return $course;
        });
    }

    public function question(User $user, array $data, ?int $id = null): AdmissionExamQuestion
    {
        return $this->locked($user, function ($actor) use ($data, $id) {
            $question = $id ? AdmissionExamQuestion::lockForUpdate()->findOrFail($id) : new AdmissionExamQuestion;
            $data = Validator::make($data, [
                'question_code' => ['required', 'string', 'max:64', Rule::unique('admission_exam_questions')->ignore($id)],
                'topic' => ['required', Rule::in(ProgramMatcher::INTEREST_CATEGORIES)],
                'question_text' => 'required|string|max:10000',
                'option_a' => 'required|string|max:3000', 'option_b' => 'required|string|max:3000', 'option_c' => 'required|string|max:3000', 'option_d' => 'required|string|max:3000',
                'correct_answer' => 'required|in:A,B,C,D', 'difficulty' => 'required|in:easy,medium,hard', 'status' => 'required|in:draft,active,retired',
                'version' => $id ? 'required|integer|min:1' : 'nullable',
            ])->validate();
            if ($id) {
                abort_unless($question->version === $data['version'], 409, 'Question changed. Reload before editing.');
            }
            abort_if($id && str_starts_with($question->question_code, 'DEV-MVP-') && ! str_starts_with($data['question_code'], 'DEV-MVP-'),
                422, 'Development questions must retain their development marker.');
            unset($data['version']);
            $question->fill($data);
            $question->version = $id ? $question->version + 1 : 1;
            $question->updated_by_user_id = $actor->id;
            if (! $id) {
                $question->created_by_user_id = $actor->id;
            }
            $question->save();
            $this->audit->workflow($actor, 'admission.question_'.($id ? 'updated' : 'created'), 'question', $question->id, metadata: ['version' => $question->version]);

            return $question;
        });
    }

    public function deleteQuestion(User $user, int $id, int $version): void
    {
        $this->locked($user, function ($actor) use ($id, $version) {
            $question = AdmissionExamQuestion::lockForUpdate()->findOrFail($id);
            abort_unless($question->version === $version, 409, 'Question changed. Reload.');
            abort_if(AdmissionExamSessionQuestion::where('question_id', $id)->exists(), 409, 'This question is used by an exam. Retire it instead.');
            $question->delete();
            $this->audit->workflow($actor, 'admission.question_deleted', 'question', $id);
        });
    }

    public function program(User $user, int $courseId, array $data): AdmissionProgramSetting
    {
        return $this->locked($user, function ($actor) use ($courseId, $data) {
            Course::findOrFail($courseId);
            $setting = AdmissionProgramSetting::where('course_id', $courseId)->lockForUpdate()->first();
            $rules = [
                'status' => 'required|in:active,inactive', 'is_recommendable' => 'required|boolean',
                'program_type' => 'required|in:degree,certificate', 'description' => 'nullable|string|max:5000', 'duration' => 'nullable|string|max:100',
                'subjects' => 'present|array|max:30', 'subjects.*' => 'string|max:255', 'career_paths' => 'present|array|max:30', 'career_paths.*' => 'string|max:255',
                'display_order' => 'required|integer|min:0', 'version' => $setting ? 'required|integer|min:1' : 'nullable',
                'recommendation_profile' => ['required', 'array:'.implode(',', [...ProgramMatcher::INTEREST_CATEGORIES, 'Technical Aptitude'])],
                'recommendation_profile.*' => 'numeric|min:0|max:1',
            ];
            $data = Validator::make($data, $rules)->validate();
            abort_if($data['is_recommendable'] && array_sum($data['recommendation_profile']) <= 0, 422, 'Recommendable programs need positive category weights.');
            if ($setting) {
                abort_unless($data['version'] === $setting->version, 409, 'Program changed. Reload.');
            }
            $version = $setting ? $setting->version + 1 : 1;
            unset($data['version']);
            $setting ??= new AdmissionProgramSetting;
            $setting->fill($data + ['course_id' => $courseId]);
            $setting->version = $version;
            $setting->updated_by_user_id = $actor->id;
            $setting->save();
            $this->audit->workflow($actor, 'admission.program_updated', 'program', $setting->id, metadata: ['version' => $version]);

            return $setting;
        });
    }
}
