<?php

namespace App\Services;

use App\Models\CampusNotice;
use App\Models\CampusNoticeAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class CampusNoticeAuditWriter
{
    /** @param array<string, mixed> $metadata */
    public function record(User $actor, CampusNotice $notice, string $action, array $metadata = []): void
    {
        if (DB::transactionLevel() < 1 || ! in_array($action, ['created', 'updated', 'published', 'archived'], true)) {
            throw new LogicException('Invalid campus notice audit event.');
        }

        CampusNoticeAuditEvent::query()->create([
            'campus_notice_id' => $notice->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
