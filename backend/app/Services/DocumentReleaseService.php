<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DocumentRequest;
use App\Models\RegistrarStaff;
use Illuminate\Support\Facades\DB;

class DocumentReleaseService
{
    public function release(
        DocumentRequest $documentRequest,
        RegistrarStaff $staff,
        ?int $appointmentId = null,
        bool $replaceRemarks = false,
        ?string $remarks = null,
    ): DocumentRequest {
        return DB::transaction(function () use ($appointmentId, $documentRequest, $remarks, $replaceRemarks, $staff): DocumentRequest {
            $lockedRequest = DocumentRequest::query()
                ->lockForUpdate()
                ->findOrFail($documentRequest->getKey());

            abort_unless(
                $lockedRequest->status === 'ready_for_release',
                422,
                'Only a request that is ready for release can be released.',
            );

            $appointment = $this->releaseAppointment($lockedRequest, $appointmentId);
            $releasedAt = now();
            $updates = [
                'registrar_staff_id' => $staff->id,
                'status' => 'released',
                'released_at' => $releasedAt,
                'release_date' => $releasedAt->toDateString(),
            ];
            if ($replaceRemarks) {
                $updates['remarks'] = $remarks;
            }

            $lockedRequest->update($updates);

            $appointment->update([
                'registrar_staff_id' => $staff->id,
                'status' => 'completed',
                'active_slot_key' => null,
                'completed_at' => $releasedAt,
            ]);

            $lockedRequest->statusChanges()->create([
                'registrar_staff_id' => $staff->id,
                'from_status' => 'ready_for_release',
                'to_status' => 'released',
                'action' => 'released',
            ]);

            return $lockedRequest;
        }, 3);
    }

    private function releaseAppointment(DocumentRequest $documentRequest, ?int $appointmentId): Appointment
    {
        $appointments = Appointment::query()
            ->where('document_request_id', $documentRequest->getKey())
            ->lockForUpdate();

        if ($appointmentId !== null) {
            $appointment = (clone $appointments)->whereKey($appointmentId)->first();
            abort_unless($appointment instanceof Appointment, 422, 'The appointment does not belong to this request.');
        } else {
            $appointment = (clone $appointments)
                ->orderByRaw("case when status = 'confirmed' then 0 else 1 end")
                ->latest('id')
                ->first();
        }

        abort_unless(
            $appointment instanceof Appointment,
            422,
            'A confirmed appointment is required before the document can be released.',
        );
        abort_if(
            $appointment->status !== 'confirmed',
            422,
            'The related appointment must be confirmed before the document can be released.',
        );

        return $appointment;
    }
}
