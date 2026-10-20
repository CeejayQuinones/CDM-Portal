<?php

namespace App\Services;

use App\Models\MonitoringInterventionEvent;
use App\Models\RiskNotification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class MonitoringAuditWriter
{
    public function write(
        User $actor,
        Student $student,
        string $action,
        ?RiskNotification $notification,
        ?string $riskLevel,
        array $context = [],
    ): void {
        if (DB::transactionLevel() < 1 || ! in_array($action, ['monitoring.risk_notice.sent', 'monitoring.risk_notice.read', 'monitoring.ai_help.requested'], true)) {
            throw new LogicException('Invalid Monitoring intervention audit write.');
        }

        MonitoringInterventionEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'risk_notification_id' => $notification?->id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role?->role_name ?? 'Unknown',
            'student_id' => $student->id,
            'action' => $action,
            'risk_level' => $riskLevel,
            'context_json' => $context,
            'created_at' => now(),
        ]);
    }
}
