<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment\EnrollmentWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentAuditWriter
{
    public function record(User $actor, string $action, string $type, int $id, array $metadata): void
    {
        if (DB::transactionLevel() < 1 || ! in_array($action, ['draft_created', 'draft_saved', 'submitted', 'review_started', 'approved', 'rejected', 'cancelled', 'document_attached', 'period_created', 'period_updated'], true) || ! in_array($type, ['application', 'period'], true) || array_diff(array_keys($metadata), ['status', 'version'])) {
            throw new \LogicException('Invalid Enrollment audit.');
        }
        EnrollmentWorkflowEvent::create(['event_uuid' => (string) Str::uuid(), 'actor_user_id' => $actor->id, 'actor_role' => $actor->role->role_name, 'action' => 'enrollment.'.$action, 'subject_type' => $type, 'subject_id' => $id, 'metadata' => $metadata, 'created_at' => now()]);
    }
}
