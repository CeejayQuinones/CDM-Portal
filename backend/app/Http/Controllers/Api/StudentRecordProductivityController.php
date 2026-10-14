<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRecordPreferencesRequest;
use App\Http\Requests\StudentRecordSavedFilterRequest;
use App\Models\StudentRecordSavedFilter;
use App\Models\StudentRecordStaffPreference;
use App\Services\StudentRecords\StudentRecordProductivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentRecordProductivityController extends Controller
{
    public function __construct(private readonly StudentRecordProductivityService $productivity) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->productivity->dashboard($request->user())]);
    }

    public function storeFilter(StudentRecordSavedFilterRequest $request): JsonResponse
    {
        $filter = StudentRecordSavedFilter::query()->create($request->validated() + ['user_id' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => 'Filter saved.', 'data' => $filter->only(['id', 'name', 'filters'])], 201);
    }

    public function updateFilter(StudentRecordSavedFilterRequest $request, StudentRecordSavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->user_id === $request->user()->id, 404);
        $savedFilter->update($request->validated());

        return response()->json(['success' => true, 'message' => 'Saved filter updated.', 'data' => $savedFilter->only(['id', 'name', 'filters'])]);
    }

    public function destroyFilter(Request $request, StudentRecordSavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->user_id === $request->user()->id, 404);
        $savedFilter->delete();

        return response()->json(['success' => true, 'message' => 'Saved filter deleted.']);
    }

    public function preferences(StudentRecordPreferencesRequest $request): JsonResponse
    {
        $preference = StudentRecordStaffPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['visible_columns' => $request->validated('visible_columns')],
        );

        return response()->json(['success' => true, 'message' => 'Column preferences saved.', 'data' => ['visible_columns' => $preference->visible_columns]]);
    }
}
