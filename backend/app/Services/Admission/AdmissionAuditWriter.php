<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionAuditEvent;
use App\Models\Admission\AdmissionWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class AdmissionAuditWriter
{
    public function workflow(?User $actor, string $action, string $type, string|int $id, ?int $applicantId = null, array $metadata = []): void
    {
        $allowed = [
            'admission.application_accepted', 'admission.student_converted',
            'admission.cycle_created', 'admission.cycle_updated', 'admission.cycle_opened', 'admission.cycle_closed',
            'admission.question_created', 'admission.question_updated', 'admission.question_deleted',
            'admission.program_updated', 'admission.exam_started', 'admission.exam_submitted', 'admission.exam_expired',
            'admission.result_approved', 'admission.result_published', 'admission.result_corrected', 'admission.registrar_pass',
        ];
        if (DB::transactionLevel() < 1 || ! in_array($action, $allowed, true)
            || ! in_array($type, ['cycle', 'question', 'program', 'session', 'result', 'applicant'], true)
            || array_diff(array_keys($metadata), ['version', 'status', 'attempt_number', 'revision'])) {
            throw new LogicException('Invalid Admission workflow audit.');
        }
        AdmissionWorkflowEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'actor_user_id' => $actor?->id, 'actor_role' => $actor?->role?->role_name ?? 'system',
            'applicant_id' => $applicantId, 'subject_type' => $type, 'subject_id' => (string) $id,
            'action' => $action, 'metadata' => $metadata, 'created_at' => now(),
        ]);
    }

    public function identityCreated(User $actor, AdmissionApplicant $applicant): AdmissionAuditEvent
    {
        if ($applicant->getConnection()->transactionLevel() < 1 || ! $applicant->wasRecentlyCreated) {
            throw new LogicException('Identity audit requires the applicant creation transaction.');
        }

        // Read persisted values, never caller-provided metadata or dirty model fields.
        $stored = $applicant->fresh();
        if ($stored === null || $stored->user_id !== $actor->id || $stored->status !== AdmissionApplicant::DRAFT) {
            throw new LogicException('Invalid identity creation audit subject or actor.');
        }

        $event = new AdmissionAuditEvent;
        $event->setConnection($applicant->getConnectionName());
        $event->actor_user_id = $actor->id;
        $event->applicant_id = $stored->id;
        $event->action = AdmissionAuditEvent::IDENTITY_CREATED;
        $event->metadata = ['cycle_id' => $stored->cycle_id, 'status' => $stored->status, 'version' => $stored->version];
        $event->created_at = now();
        $event->save();

        return $event;
    }
}
