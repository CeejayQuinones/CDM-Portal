<?php

namespace App\Services\StudentRecords;

use App\Models\LegacyMigrationBatch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyMigrationApprovalService
{
    public function approve(LegacyMigrationBatch $batch, User $actor, array $input): LegacyMigrationBatch
    {
        if ($actor->role?->role_name !== Role::REGISTRAR_STAFF) {
            throw ValidationException::withMessages(['actor' => ['Only Registrar staff can approve a migration batch.']]);
        }

        return DB::transaction(function () use ($batch, $actor, $input): LegacyMigrationBatch {
            $batch = LegacyMigrationBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $count = $batch->rows()->count();
            $ready = $batch->rows()->where('status', 'ready')->count();
            if ($batch->status !== 'preflighted' || $count < 1 || $ready !== $count || $batch->row_count !== $count) {
                throw ValidationException::withMessages(['batch' => ['All staged rows must pass a complete, current preflight before approval.']]);
            }
            if (! hash_equals($batch->source_sha256, (string) ($input['source_sha256'] ?? '')) || (int) ($input['reconciled_row_count'] ?? -1) !== $count) {
                throw ValidationException::withMessages(['reconciliation' => ['Source checksum and reconciled row count must match the staged export.']]);
            }
            $batch->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_reference' => trim($input['approval_reference']),
                'backup_reference' => trim($input['backup_reference']),
            ]);

            return $batch;
        }, 3);
    }
}
