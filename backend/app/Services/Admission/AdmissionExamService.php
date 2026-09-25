<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionExamAnswer;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Admission\AdmissionExamSessionQuestion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdmissionExamService
{
    public function __construct(private AdmissionAuditWriter $audit) {}

    public function sessions(User $user)
    {
        return AdmissionExamSession::whereHas('applicant', fn ($q) => $q->where('user_id', $user->id));
    }

    public function locked(User $user, callable $action): mixed
    {
        return DB::transaction(function () use ($user, $action) {
            $actor = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $action($actor);
        }, 3);
    }

    public function status(User $user): array
    {
        AdmissionAccess::require($user, 'read');

        return $this->locked($user, function ($actor) {
            AdmissionAccess::require($actor, 'read');
            $active = $this->sessions($actor)->where('status', 'active')->lockForUpdate()->first();
            if ($active && $actor->role->role_name === Role::GUEST && now()->gte($active->deadline_at)) {
                $this->finish($active, 'deadline', null);
            }
            $latest = $this->sessions($actor)->orderByDesc('started_at')->orderByDesc('id')->with('result')->first();
            $applicant = $actor->admissionApplications()->latest('id')->first();
            $reason = $this->eligibility($actor, $applicant);

            return [
                'eligible' => $reason === null, 'reason' => $reason,
                'attempts_submitted' => $this->sessions($actor)->where('status', 'finalized')->count(),
                'max_attempts' => 2, 'time_limit' => 120, 'categories' => ProgramMatcher::INTEREST_CATEGORIES, 'question_count' => 100,
                'session' => $active ? $this->payload($active) : null,
                'latest_completed' => $latest?->status === 'finalized',
            ];
        });
    }

    private function eligibility(User $actor, ?AdmissionApplicant $applicant): ?string
    {
        if ($actor->role->role_name !== Role::GUEST || $actor->student()->exists()) {
            return 'historical_read_only';
        }
        if (! $applicant || ! in_array($applicant->status, AdmissionApplicant::ACTIVE_STATUSES, true)) {
            return 'application_required';
        }
        if ($this->sessions($actor)->where('status', 'active')->exists()) {
            return 'active_session';
        }
        // Source policy counts attempts per person. Cross-cycle reset is not enabled.
        $sessions = $this->sessions($actor)->with('result')->orderByDesc('attempt_number')->get();
        if ($sessions->count() >= 2) {
            return 'attempts_exhausted';
        }
        if ($sessions->isNotEmpty()) {
            $result = $sessions->first()->result;
            if (! $result || $result->official_status !== 'published') {
                return 'awaiting_publication';
            }
            if ($result->outcome() !== 'RETAKE') {
                return 'retake_unavailable';
            }
        }
        $counts = AdmissionExamQuestion::where('status', 'active')->when(app()->environment('production'), fn ($q) => $q->where('question_code', 'not like', 'DEV-MVP-%'))->selectRaw('topic, count(*) as total')->groupBy('topic')->pluck('total', 'topic');
        foreach (ProgramMatcher::INTEREST_CATEGORIES as $topic) {
            if (($counts[$topic] ?? 0) < 20) {
                return 'bank_incomplete';
            }
        }

        return null;
    }

    public function start(User $user): array
    {
        return $this->locked($user, function ($actor) {
            $actor = AdmissionAccess::require($actor, 'write');
            $active = $this->sessions($actor)->where('status', 'active')->lockForUpdate()->first();
            if ($active) {
                if (now()->gte($active->deadline_at)) {
                    $this->finish($active, 'deadline', null);
                }

                return $this->payload($active);
            }
            $applicant = $actor->admissionApplications()->latest('id')->lockForUpdate()->first();
            // Match configuration lock order before reading the bank.
            Role::where('role_name', Role::ADMIN)->lockForUpdate()->firstOrFail();
            $reason = $this->eligibility($actor, $applicant);
            abort_if($reason !== null, 409, 'Exam unavailable: '.$reason);
            $bank = AdmissionExamQuestion::where('status', 'active')->when(app()->environment('production'), fn ($q) => $q->where('question_code', 'not like', 'DEV-MVP-%'))->orderBy('id')->lockForUpdate()->get()->groupBy('topic');
            $questions = collect(ProgramMatcher::INTEREST_CATEGORIES)->shuffle()->flatMap(fn ($topic) => $bank[$topic]->shuffle()->take(20))->values();
            abort_unless($questions->count() === 100, 409, 'The exam bank is incomplete.');
            $session = new AdmissionExamSession;
            $session->id = (string) Str::uuid();
            $session->fill(['applicant_id' => $applicant->id, 'attempt_number' => $this->sessions($actor)->count() + 1,
                'status' => 'active', 'bank_version' => hash('sha256', $questions->toJson()),
                'policy_snapshot' => ['duration_minutes' => 120, 'questions_per_topic' => 20, 'max_attempts' => 2, 'passing_score' => 75, 'categories' => ProgramMatcher::INTEREST_CATEGORIES],
                'started_at' => now(), 'deadline_at' => now()->addMinutes(120), 'revision' => 0, 'position' => 0]);
            $session->save();
            foreach ($questions as $position => $question) {
                $snapshot = AdmissionExamSessionQuestion::create([
                    'session_id' => $session->id, 'question_id' => $question->id, 'position' => $position, 'topic' => $question->topic,
                    'question_version' => $question->version, 'question_text' => $question->question_text,
                    'options' => ['A' => $question->option_a, 'B' => $question->option_b, 'C' => $question->option_c, 'D' => $question->option_d],
                    'correct_answer' => $question->correct_answer, 'created_at' => now(),
                ]);
                AdmissionExamAnswer::create(['session_question_id' => $snapshot->id, 'selected_option' => null, 'accepted_revision' => 0]);
            }
            $this->audit->workflow($actor, 'admission.exam_started', 'session', $session->id, $applicant->id, ['attempt_number' => $session->attempt_number]);

            return $this->payload($session);
        });
    }

    public function write(User $user, string $id, array $data, bool $submit): array
    {
        return $this->locked($user, function ($actor) use ($id, $data, $submit) {
            $actor = AdmissionAccess::require($actor, 'write');
            $session = $this->sessions($actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'active' && now()->gte($session->deadline_at)) {
                $this->finish($session, 'deadline', null);
            }
            // Expiry commits even if the caller has unsent late answers; never apply them.
            if ($session->status === 'finalized') {
                return $this->payload($session);
            }
            abort_unless($session->revision === $data['revision'], 409, 'This exam changed in another tab or device. Reload saved answers.');
            $ids = $session->questions()->pluck('id')->map(fn ($id) => (string) $id)->all();
            abort_if(count($data['answers']) !== count($ids) || array_diff(array_keys($data['answers']), $ids), 422, 'Answers must match this assigned exam.');
            foreach ($data['answers'] as $questionId => $answer) {
                AdmissionExamAnswer::where('session_question_id', $questionId)->update([
                    'selected_option' => $answer, 'accepted_revision' => $session->revision + 1, 'saved_at' => now(), 'updated_at' => now(),
                ]);
            }
            $session->update(['revision' => $session->revision + 1, 'position' => $data['position'] ?? $session->position]);
            if ($submit) {
                $this->finish($session, 'submitted', $actor);
            }

            return $this->payload($session);
        });
    }

    public function finish(AdmissionExamSession $session, string $cause, ?User $actor): AdmissionExamResult
    {
        if ($session->status === 'finalized') {
            return $session->result()->firstOrFail();
        }
        $scores = $maximums = [];
        $correct = 0;
        $questions = $session->questions()->get();
        $answers = AdmissionExamAnswer::whereIn('session_question_id', $questions->pluck('id'))->get()->keyBy('session_question_id');
        foreach ($questions as $question) {
            $answer = $answers[$question->id];
            $right = $answer->selected_option === $question->correct_answer;
            $answer->update(['is_correct' => $right]);
            $scores[$question->topic] = ($scores[$question->topic] ?? 0) + (int) $right;
            $maximums[$question->topic] = ($maximums[$question->topic] ?? 0) + 1;
            $correct += (int) $right;
        }
        $percentage = round($correct / $questions->count() * 100, 2);
        $result = AdmissionExamResult::create([
            'session_id' => $session->id, 'raw_correct_count' => $correct, 'question_count' => $questions->count(),
            'system_percentage' => $percentage, 'system_passed' => $percentage >= 75, 'category_scores' => $scores, 'category_maximums' => $maximums,
            'time_spent_seconds' => min(7200, max(0, now()->timestamp - $session->started_at->timestamp)),
            'finalized_at' => now(), 'finalization_cause' => $cause, 'official_status' => 'pending', 'version' => 1,
        ]);
        $session->update(['status' => 'finalized', 'finalized_at' => now()]);
        $this->audit->workflow($actor, $cause === 'deadline' ? 'admission.exam_expired' : 'admission.exam_submitted', 'session', $session->id, $session->applicant_id, ['attempt_number' => $session->attempt_number]);

        return $result;
    }

    public function expire(): int
    {
        $count = 0;
        AdmissionExamSession::where('status', 'active')->where('deadline_at', '<=', now())->orderBy('id')->chunkById(100, function ($sessions) use (&$count) {
            foreach ($sessions as $session) {
                $this->locked($session->applicant->user, function () use ($session, &$count) {
                    $current = AdmissionExamSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                    if ($current->status === 'active' && now()->gte($current->deadline_at)) {
                        $this->finish($current, 'deadline', null);
                        $count++;
                    }
                });
            }
        });

        return $count;
    }

    public function payload(AdmissionExamSession $session): array
    {
        $completed = $session->status === 'finalized';
        $questions = $completed ? collect() : $session->questions()->get();

        return [
            'session_id' => $session->id, 'attempt_number' => $session->attempt_number, 'revision' => $session->revision, 'position' => $session->position,
            'exam_completed' => $completed, 'expired' => $completed && $session->result()->value('finalization_cause') === 'deadline',
            'deadline' => $session->deadline_at->toIso8601String(), 'server_now' => now()->toIso8601String(),
            'questions' => $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'question_text' => $q->question_text, 'options' => $q->options])->all(),
            'answers' => $completed ? (object) [] : AdmissionExamAnswer::whereIn('session_question_id', $questions->pluck('id'))->pluck('selected_option', 'session_question_id')->all(),
        ];
    }

    public function published(User $user): array
    {
        AdmissionAccess::require($user, 'read');
        $latest = $this->sessions($user)->orderByDesc('attempt_number')->with('result')->first();
        $result = $latest?->result;
        if (! $result || $result->official_status !== 'published') {
            return ['published' => false, 'result' => null];
        }

        return ['published' => true, 'result' => [
            'id' => $result->id, 'version' => $result->version, 'attempt_number' => $latest->attempt_number, 'outcome' => $result->outcome(),
            'score' => $result->registrar_pass ? null : $result->official_score,
            'published_at' => $result->published_at->toIso8601String(),
            'retake_eligible' => $user->fresh('role')->role->role_name === Role::GUEST && $result->outcome() === 'RETAKE',
        ]];
    }
}
