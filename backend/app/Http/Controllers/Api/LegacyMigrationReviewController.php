<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegacyMigrationBatch;
use App\Services\StudentRecords\LegacyMigrationApprovalService;
use App\Services\StudentRecords\LegacyMigrationMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyMigrationReviewController extends Controller
{
    public function __construct(private readonly LegacyMigrationMatcher $matcher) {}

    public function index(): JsonResponse
    {
        $batches = LegacyMigrationBatch::query()->orderByDesc('id')->limit(50)->get()
            ->map(fn ($batch) => $this->matcher->report($batch));

        return $this->ok($batches);
    }

    public function show(LegacyMigrationBatch $batch): JsonResponse
    {
        $report = $this->matcher->report($batch);
        $report['exceptions'] = $batch->rows()->where('status', 'needs_review')
            ->select('source_row', 'reason_code')->orderBy('source_row')->limit(100)
            ->get()->map(fn ($row) => $row->only(['source_row', 'reason_code']))->all();
        $report['exceptions_truncated'] = ($report['by_status']['needs_review'] ?? 0) > 100;

        return $this->ok($report);
    }

    public function approve(Request $request, LegacyMigrationBatch $batch, LegacyMigrationApprovalService $approval): JsonResponse
    {
        $data = $request->validate([
            'source_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'reconciled_row_count' => ['required', 'integer', 'min:1'],
            'approval_reference' => ['required', 'string', 'min:10', 'max:255'],
            'backup_reference' => ['required', 'string', 'min:10', 'max:255'],
        ]);
        $approval->approve($batch, $request->user(), $data);

        return $this->ok($this->matcher->report($batch->fresh()));
    }

    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'private, no-store');
    }
}
