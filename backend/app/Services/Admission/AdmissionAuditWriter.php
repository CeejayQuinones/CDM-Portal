<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionAuditEvent;
use App\Models\User;
use LogicException;

class AdmissionAuditWriter
{
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
