<?php

namespace App\Services;

use App\Models\AccountAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class AccountAuditWriter
{
    private const ACTIONS = [
        'registrar_account_created',
        'registrar_account_updated',
        'registrar_account_status_changed',
        'professor_account_created',
        'professor_account_updated',
        'professor_account_status_changed',
    ];

    /** @param array<string, mixed> $metadata */
    public function record(User $actor, User $subject, string $action, array $metadata = []): void
    {
        if (DB::transactionLevel() < 1 || ! in_array($action, self::ACTIONS, true)) {
            throw new LogicException('Invalid account audit event.');
        }

        AccountAuditEvent::query()->create([
            'actor_user_id' => $actor->id,
            'subject_user_id' => $subject->id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
