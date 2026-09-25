<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionDecision;
use App\Models\Admission\AdmissionExamResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdmissionResultService
{
    public function __construct(private AdmissionAuditWriter $audit, private AdmissionExamService $exams) {}

    public function act(User $actor, array $items, string $action, ?int $score = null, ?string $reason = null): array
    {
        AdmissionAccess::require($actor, 'review');

        return DB::transaction(function () use ($actor, $items, $action, $score, $reason) {
            $actor = AdmissionAccess::require($actor, 'review');
            $ids = array_column($items, 'id');
            $initial = AdmissionExamResult::with('session.applicant')->whereIn('id', $ids)->get();
            abort_unless($initial->count() === count($ids), 404, 'Result not found.');
            $userIds = $initial->map(fn ($r) => $r->session->applicant->user_id)->unique()->sort()->values();
            // Same person lock as starts, saves, expiry and recommendation commits.
            User::whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get();
            $results = AdmissionExamResult::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $versions = array_column($items, 'version', 'id');
            foreach ($results as $result) {
                abort_unless($result->version === $versions[$result->id], 409, 'A result changed. Reload before reviewing.');
                $session = $result->session;
                $application = AdmissionApplicant::whereKey($session->applicant_id)->lockForUpdate()->firstOrFail();
                abort_if($application->status === 'converted', 409, 'A converted Student result requires separate academic exception review.');
                $owner = $application->user;
                $latest = $this->exams->sessions($owner)->orderByDesc('attempt_number')->first();
                abort_unless($latest?->id === $session->id && ! $this->exams->sessions($owner)->where('status', 'active')->exists(), 409, 'Only the latest completed attempt can be changed; no retake may be active.');
                $before = $this->projection($result);
                if ($action === 'approve') {
                    abort_if($result->official_status === 'published' || $result->registrar_pass, 409, 'Published results require correction.');
                    $result->official_score = $score ?? (int) round($result->system_percentage);
                    $result->official_status = 'approved';
                    $result->approved_by_user_id = $actor->id;
                    $result->approved_at = now();
                } elseif ($action === 'publish') {
                    abort_unless($result->official_status === 'approved' && $result->official_score !== null, 409, 'Approve this result before publishing.');
                    $result->official_status = 'published';
                    $result->published_by_user_id = $actor->id;
                    $result->published_at = now();
                } elseif ($action === 'correct') {
                    abort_unless($result->official_status === 'published' && $score !== null && trim($reason ?? '') !== '', 422, 'A published result, official score and correction reason are required.');
                    $result->official_score = $score;
                    $result->registrar_pass = false;
                    $result->internal_reason = null;
                    $result->approved_by_user_id = $actor->id;
                    $result->approved_at = now();
                    $result->published_by_user_id = $actor->id;
                    $result->published_at = now();
                } elseif ($action === 'override') {
                    $first = $this->exams->sessions($owner)->where('attempt_number', 1)->with('result')->first()?->result;
                    abort_unless($session->attempt_number === 2 && ! $result->system_passed && ! $result->registrar_pass
                        && ($result->official_score === null || $result->official_score < 75) && $first?->outcome() === 'RETAKE'
                        && trim($reason ?? '') !== '', 422, 'Registrar pass requires an eligible second failure and an internal reason.');
                    $result->registrar_pass = true;
                    $result->internal_reason = $reason;
                    $result->official_status = 'published';
                    $result->approved_by_user_id = $actor->id;
                    $result->approved_at = now();
                    $result->published_by_user_id = $actor->id;
                    $result->published_at = now();
                } else {
                    abort(422, 'Unknown result action.');
                }
                $result->version++;
                $result->save();
                $event = match ($action) {
                    'approve' => 'result_approved','publish' => 'result_published','correct' => 'result_corrected','override' => 'registrar_pass'
                };
                AdmissionDecision::create([
                    'applicant_id' => $session->applicant_id, 'result_id' => $result->id, 'actor_user_id' => $actor->id, 'action' => $event,
                    'operation_key' => (string) Str::uuid(), 'application_version' => $session->applicant->version, 'result_version' => $result->version,
                    'before' => $before, 'after' => $this->projection($result), 'internal_reason' => $reason, 'created_at' => now(),
                ]);
                $this->audit->workflow($actor, 'admission.'.$event, 'result', $result->id, $session->applicant_id, ['version' => $result->version, 'status' => $result->official_status]);
            }

            return ['updated' => $results->count()];
        }, 3);
    }

    private function projection(AdmissionExamResult $result): array
    {
        return $result->only(['official_score', 'official_status', 'registrar_pass', 'version', 'approved_by_user_id', 'published_by_user_id', 'approved_at', 'published_at']);
    }
}
