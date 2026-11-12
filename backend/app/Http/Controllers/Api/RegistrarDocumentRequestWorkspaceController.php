<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhysicalRetrievalTask;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegistrarDocumentRequestWorkspaceController extends Controller
{
    private const ACTIVE_STATUSES = ['pending', 'approved'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::ACTIVE_STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->studentsWithActiveCounts();
        $query->whereHas('documentRequests', function (Builder $requests) use ($filters): void {
            $requests->whereIn('status', self::ACTIVE_STATUSES);
            if (isset($filters['status'])) {
                $requests->where('status', $filters['status']);
            }
        });

        if ($term = trim($filters['search'] ?? '')) {
            $requestId = preg_match('/^REQ-0*([1-9]\d*)$/i', $term, $match) ? (int) $match[1] : null;
            $query->where(function (Builder $students) use ($term, $requestId): void {
                $students->where('student_number', 'like', "%{$term}%")
                    ->orWhereHas('user.profile', function (Builder $profiles) use ($term): void {
                        foreach (preg_split('/\s+/', $term) ?: [] as $namePart) {
                            $profiles->where(fn (Builder $names) => $names
                                ->where('first_name', 'like', "%{$namePart}%")
                                ->orWhere('last_name', 'like', "%{$namePart}%"));
                        }
                    });
                if ($requestId !== null) {
                    $students->orWhereHas('documentRequests', fn (Builder $requests) => $requests
                        ->whereKey($requestId)->whereIn('status', self::ACTIVE_STATUSES));
                }
            });
        }

        return response()->json(['success' => true, 'data' => $query->orderBy('id')->paginate(15)]);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $task = $this->activeTask($student);
        abort_unless($student->documentRequests()->whereIn('status', self::ACTIVE_STATUSES)->exists() || $task, 404);

        $student->load([
            'user:id',
            'user.profile:id,user_id,first_name,last_name',
            'physicalRecordLocation:id,student_id,cabinet_slot_id',
            'physicalRecordLocation.cabinetSlot:id,cabinet_id,slot_code',
            'physicalRecordLocation.cabinetSlot.cabinet:id,cabinet_code',
        ]);
        $requests = $student->documentRequests()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->select(['id', 'student_id', 'document_type_id', 'document_request_batch_id', 'quantity', 'status', 'request_date', 'created_at'])
            ->with(['documentType:id,document_name', 'batch:id,student_id,created_at'])
            ->latest('created_at')->latest('id')->paginate(20);

        return response()->json(['success' => true, 'data' => [
            'student' => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'user' => $student->user,
                'physical_record_location' => $student->physicalRecordLocation,
            ],
            'requests' => $requests,
            'retrieval_task' => $task?->only(['id', 'status', 'cabinet_code_snapshot', 'slot_code_snapshot']),
            'retrieval_request_ids' => $task?->documentRequests()->whereIn('document_requests.status', self::ACTIVE_STATUSES)->pluck('document_requests.id') ?? [],
        ]]);
    }

    public function sameLocation(Request $request, Student $student): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        abort_unless($student->documentRequests()->whereIn('status', self::ACTIVE_STATUSES)->exists(), 404);
        $slot = $student->physicalRecordLocation?->cabinetSlot;
        abort_unless($slot?->cabinet, 422, 'This student has no assigned cabinet and drawer/slot.');

        $students = $this->studentsWithActiveCounts()
            ->where('students.id', '!=', $student->id)
            ->whereHas('physicalRecordLocation', fn (Builder $locations) => $locations->where('cabinet_slot_id', $slot->id))
            ->whereHas('documentRequests', fn (Builder $requests) => $requests->whereIn('status', self::ACTIVE_STATUSES))
            ->orderBy('id')->paginate(10);

        $studentIds = $students->getCollection()->pluck('id');
        $retrievalCounts = DB::table('physical_retrieval_request')
            ->join('document_requests', 'document_requests.id', '=', 'physical_retrieval_request.document_request_id')
            ->whereIn('document_requests.student_id', $studentIds)
            ->whereIn('document_requests.status', self::ACTIVE_STATUSES)
            ->select('document_requests.student_id')
            ->selectRaw('COUNT(DISTINCT document_requests.id) as required_count')
            ->groupBy('document_requests.student_id')
            ->pluck('required_count', 'document_requests.student_id');
        $students->getCollection()->each(function (Student $matching) use ($retrievalCounts): void {
            $matching->setAttribute('retrieval_required_count', (int) ($retrievalCounts[$matching->id] ?? 0));
        });

        return response()->json(['success' => true, 'data' => [
            'location' => ['cabinet' => $slot->cabinet->cabinet_code, 'drawer' => $slot->slot_code],
            'students' => $students,
        ]]);
    }

    private function studentsWithActiveCounts(): Builder
    {
        return Student::query()->select(['id', 'user_id', 'student_number'])
            ->with(['user:id', 'user.profile:id,user_id,first_name,last_name'])
            ->withCount([
                'documentRequests as active_requests_count' => fn (Builder $requests) => $requests->whereIn('status', self::ACTIVE_STATUSES),
                'documentRequests as pending_requests_count' => fn (Builder $requests) => $requests->where('status', 'pending'),
                'documentRequests as approved_requests_count' => fn (Builder $requests) => $requests->where('status', 'approved'),
            ]);
    }

    private function activeTask(Student $student): ?PhysicalRetrievalTask
    {
        return PhysicalRetrievalTask::query()->where('active_student_id', $student->id)
            ->whereIn('status', ['awaiting', 'retrieved'])->first();
    }
}
