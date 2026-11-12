<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestBatch;
use App\Models\RegistrarStaff;
use App\Services\DocumentRequestWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegistrarDocumentRequestBatchController extends Controller
{
    public function __construct(private readonly DocumentRequestWorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'completed', 'rejected', 'cancelled'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = DocumentRequestBatch::query()
            ->with([
                'student:id,user_id,student_number',
                'student.user:id',
                'student.user.profile:id,user_id,first_name,last_name',
                'documentRequests:id,student_id,document_request_batch_id,document_type_id,status,quantity,purpose,purpose_details,request_date,created_at',
                'documentRequests.documentType:id,document_name,requires_appointment',
            ])
            ->withCount('documentRequests')
            ->latest('id');

        if ($term = trim($filters['search'] ?? '')) {
            $groupId = preg_match('/^GROUP-0*([1-9]\d*)$/i', $term, $match) ? (int) $match[1] : null;
            $query->where(function (Builder $search) use ($term, $groupId): void {
                $search->whereHas('student', fn (Builder $students) => $students->where('student_number', 'like', "%{$term}%"))
                    ->orWhereHas('student.user.profile', function (Builder $profiles) use ($term): void {
                        foreach (preg_split('/\s+/', $term) ?: [] as $namePart) {
                            $profiles->where(fn (Builder $names) => $names
                                ->where('first_name', 'like', "%{$namePart}%")
                                ->orWhere('last_name', 'like', "%{$namePart}%"));
                        }
                    });
                if ($groupId !== null) {
                    $search->orWhereKey($groupId);
                }
            });
        }

        if (isset($filters['document_type_id']) || isset($filters['status'])) {
            $query->whereHas('documentRequests', function (Builder $items) use ($filters): void {
                if (isset($filters['document_type_id'])) {
                    $items->where('document_type_id', $filters['document_type_id']);
                }
                if (isset($filters['status'])) {
                    $items->where('status', $filters['status']);
                }
            });
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return response()->json(['success' => true, 'data' => $query->paginate(15)]);
    }

    public function bulkReject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1', 'max:20'],
            'request_ids.*' => ['required', 'integer', 'distinct', 'exists:document_requests,id'],
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'],
        ]);
        $staff = $request->user()->registrarStaff;
        abort_unless($staff instanceof RegistrarStaff, 403);
        $ids = array_map('intval', $data['request_ids']);
        sort($ids);

        DB::transaction(function () use ($ids, $staff, $data): void {
            $requests = DocumentRequest::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($requests->count() === count($ids), 422, 'One or more requests no longer exist.');
            abort_unless($requests->every(fn (DocumentRequest $item): bool => $item->status === 'pending'), 422, 'Only pending requests can be rejected together. Refresh and review the selection.');
            foreach ($requests as $item) {
                $this->workflow->reject($item, $staff, trim($data['reason']));
            }
        }, 3);

        return response()->json(['success' => true, 'message' => 'Selected pending requests were rejected.', 'data' => ['request_ids' => $ids]]);
    }
}
