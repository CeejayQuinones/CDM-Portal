<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhysicalRetrievalTask;
use App\Models\RegistrarStaff;
use App\Services\PhysicalRetrievalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrarPhysicalRetrievalController extends Controller
{
    public function __construct(private readonly PhysicalRetrievalService $retrieval) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['awaiting', 'retrieved'])],
            'cabinet_id' => ['nullable', 'integer', 'exists:cabinets,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = PhysicalRetrievalTask::query()
            ->where(function (Builder $tasks): void {
                $tasks->where('status', 'retrieved')
                    ->orWhere(fn (Builder $awaiting) => $awaiting->where('status', 'awaiting')
                        ->whereHas('documentRequests', fn (Builder $requests) => $requests->whereIn('status', ['pending', 'approved'])));
            })
            ->with([
                'student:id,user_id,student_number',
                'student.user:id',
                'student.user.profile:id,user_id,first_name,last_name',
                'student.physicalRecordLocation:id,student_id,cabinet_slot_id',
                'student.physicalRecordLocation.cabinetSlot:id,cabinet_id,slot_code',
                'student.physicalRecordLocation.cabinetSlot.cabinet:id,cabinet_code',
                'documentRequests:id,student_id,document_type_id,status',
                'documentRequests.documentType:id,document_name',
            ])
            ->latest('id');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['cabinet_id'])) {
            $query->whereHas('student.physicalRecordLocation.cabinetSlot', fn (Builder $slots) => $slots->where('cabinet_id', $filters['cabinet_id']));
        }
        if ($term = trim($filters['search'] ?? '')) {
            $query->whereHas('student', fn (Builder $students) => $students
                ->where('student_number', 'like', "%{$term}%")
                ->orWhereHas('user.profile', function (Builder $profiles) use ($term): void {
                    foreach (preg_split('/\s+/', $term) ?: [] as $namePart) {
                        $profiles->where(fn (Builder $names) => $names
                            ->where('first_name', 'like', "%{$namePart}%")
                            ->orWhere('last_name', 'like', "%{$namePart}%"));
                    }
                }));
        }

        return response()->json(['success' => true, 'data' => $query->paginate(20)]);
    }

    public function show(PhysicalRetrievalTask $physicalRetrievalTask): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $physicalRetrievalTask->load('events')]);
    }

    public function update(Request $request, PhysicalRetrievalTask $physicalRetrievalTask): JsonResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['retrieve', 'return'])]])['action'];
        $staff = $request->user()->registrarStaff;
        abort_unless($staff instanceof RegistrarStaff, 403);

        return response()->json([
            'success' => true,
            'data' => $this->retrieval->transition($physicalRetrievalTask, $staff, $action),
        ]);
    }
}
