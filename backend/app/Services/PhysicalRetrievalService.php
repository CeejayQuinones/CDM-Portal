<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\PhysicalRetrievalTask;
use App\Models\RegistrarStaff;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class PhysicalRetrievalService
{
    /** Caller must hold a transaction and the student row lock. */
    public function attachNewRequest(DocumentRequest $request): void
    {
        if (! $request->documentType->requires_physical_retrieval) {
            return;
        }

        $task = PhysicalRetrievalTask::query()
            ->where('active_student_id', $request->student_id)
            ->lockForUpdate()
            ->first();
        if (! $task) {
            $task = PhysicalRetrievalTask::query()->create([
                'student_id' => $request->student_id,
                'active_student_id' => $request->student_id,
                'status' => 'awaiting',
            ]);
            $task->events()->create(['action' => 'created', 'created_at' => now()]);
        }
        $task->documentRequests()->attach($request->id);
    }

    public function transition(PhysicalRetrievalTask $task, RegistrarStaff $staff, string $action): PhysicalRetrievalTask
    {
        return DB::transaction(function () use ($task, $staff, $action): PhysicalRetrievalTask {
            Student::query()->whereKey($task->student_id)->lockForUpdate()->firstOrFail();
            $locked = PhysicalRetrievalTask::query()->lockForUpdate()->findOrFail($task->id);
            if ($action === 'retrieve') {
                abort_unless($locked->status === 'awaiting', 422, 'Only awaiting folders can be retrieved.');
                abort_unless(
                    $locked->documentRequests()->whereIn('status', ['pending', 'approved'])->exists(),
                    422,
                    'No active document request still needs this folder.',
                );
                $location = $locked->student->physicalRecordLocation?->load('cabinetSlot.cabinet');
                abort_unless($location?->cabinetSlot?->cabinet, 422, 'Assign a physical record location before retrieval.');
                $cabinet = $location->cabinetSlot->cabinet->cabinet_code;
                $slot = $location->cabinetSlot->slot_code;
                $locked->update([
                    'status' => 'retrieved', 'retrieved_by' => $staff->id, 'retrieved_at' => now(),
                    'cabinet_code_snapshot' => $cabinet, 'slot_code_snapshot' => $slot,
                ]);
                $locked->events()->create([
                    'registrar_staff_id' => $staff->id, 'action' => 'retrieved',
                    'cabinet_code_snapshot' => $cabinet, 'slot_code_snapshot' => $slot, 'created_at' => now(),
                ]);
            } else {
                abort_unless($locked->status === 'retrieved', 422, 'Only retrieved folders can be returned.');
                $locked->update(['status' => 'returned', 'active_student_id' => null, 'returned_by' => $staff->id, 'returned_at' => now()]);
                $locked->events()->create([
                    'registrar_staff_id' => $staff->id, 'action' => 'returned',
                    'cabinet_code_snapshot' => $locked->cabinet_code_snapshot,
                    'slot_code_snapshot' => $locked->slot_code_snapshot,
                    'created_at' => now(),
                ]);
            }

            return $locked->fresh();
        }, 3);
    }
}
