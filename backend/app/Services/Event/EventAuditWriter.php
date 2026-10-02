<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\EventAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class EventAuditWriter
{
    public function write(Event $event, User $actor, string $action, array $metadata = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Event audit writes require an active transaction.');
        }

        EventAuditEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role?->role_name ?? 'Unknown',
            'action' => str_starts_with($action, 'attendance.') ? $action : 'event.'.$action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
