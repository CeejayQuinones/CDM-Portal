<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\DocumentRequest;
use App\Services\DocumentRequestWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CancelMissedDocumentAppointments extends Command
{
    protected $signature = 'document-requests:cancel-no-shows';

    protected $description = 'Cancel unverified document requests whose appointment date has passed';

    public function handle(): int
    {
        $today = now(DocumentRequestWorkflowService::BUSINESS_TIMEZONE)->toDateString();
        Appointment::query()
            ->whereNotNull('document_request_id')
            ->whereDate('appointment_date', '<', $today)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereHas('documentRequest', fn ($query) => $query->whereIn('status', ['pending', 'approved'])->whereNull('code_verified_at'))
            ->orderBy('id')
            ->chunkById(100, function ($appointments): void {
                foreach ($appointments as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $appointment = Appointment::query()->lockForUpdate()->find($candidate->id);
                        if (! $appointment || ! in_array($appointment->status, ['pending', 'confirmed'], true)) {
                            return;
                        }
                        $request = DocumentRequest::query()->lockForUpdate()->find($appointment->document_request_id);
                        if (! $request || ! in_array($request->status, ['pending', 'approved'], true) || $request->code_verified_at) {
                            return;
                        }
                        $from = $request->status;
                        $request->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Missed appointment / no show', 'verification_code_lookup' => null, 'verification_code_hash' => null]);
                        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now(), 'active_slot_key' => null, 'remarks' => 'Missed appointment / no show']);
                        $request->statusChanges()->create(['registrar_staff_id' => null, 'actor_type' => 'system', 'from_status' => $from, 'to_status' => 'cancelled', 'action' => 'no_show_cancelled', 'reason' => 'Missed appointment / no show']);
                    });
                }
            });

        return self::SUCCESS;
    }
}
