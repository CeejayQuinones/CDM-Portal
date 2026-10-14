<?php

namespace App\Services\StudentRecords;

use App\Models\StudentRecordAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudentRecordAuditWriter
{
    private const ACTIONS = [
        'student_profile_updated',
        'student_status_changed',
        'physical_record_location_changed',
        'document_availability_changed',
        'document_file_uploaded',
        'document_file_replaced',
        'document_file_deleted',
    ];

    /** @param array<string, mixed> $metadata */
    public function record(User $actor, int $studentId, string $action, array $metadata = []): void
    {
        if (DB::transactionLevel() < 1 || ! in_array($action, self::ACTIONS, true)) {
            throw new \LogicException('Invalid Student Records audit event.');
        }

        StudentRecordAuditEvent::query()->create([
            'student_id' => $studentId,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
