<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampusNoticeRequest;
use App\Models\CampusNotice;
use App\Services\CampusNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CampusNoticeController extends Controller
{
    public function __construct(private readonly CampusNoticeService $notices) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CampusNotice::class);

        $query = CampusNotice::query()->with(['creator:id,username', 'publisher:id,username'])->latest();
        foreach (['status', 'category', 'priority'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 100));
        $page->through(fn (CampusNotice $notice) => $this->serialize($notice));

        return response()->json(['success' => true, 'data' => [
            'notices' => $page,
            'options' => [
                'categories' => CampusNotice::CATEGORIES,
                'priorities' => CampusNotice::PRIORITIES,
                'statuses' => CampusNotice::STATUSES,
                'audiences' => CampusNotice::AUDIENCES,
                'weekdays' => CampusNotice::WEEKDAYS,
            ],
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function store(CampusNoticeRequest $request): JsonResponse
    {
        Gate::authorize('create', CampusNotice::class);
        $notice = $this->notices->create($request->user(), $request->validated());

        return response()->json(['success' => true, 'data' => $this->serialize($notice)], 201);
    }

    public function update(CampusNoticeRequest $request, CampusNotice $campusNotice): JsonResponse
    {
        Gate::authorize('update', $campusNotice);
        $notice = $this->notices->update($request->user(), $campusNotice, $request->validated());

        return response()->json(['success' => true, 'data' => $this->serialize($notice)]);
    }

    public function publish(Request $request, CampusNotice $campusNotice): JsonResponse
    {
        Gate::authorize('publish', $campusNotice);
        $notice = $this->notices->publish($request->user(), $campusNotice);

        return response()->json(['success' => true, 'data' => $this->serialize($notice)]);
    }

    public function archive(Request $request, CampusNotice $campusNotice): JsonResponse
    {
        Gate::authorize('archive', $campusNotice);
        $notice = $this->notices->archive($request->user(), $campusNotice);

        return response()->json(['success' => true, 'data' => $this->serialize($notice)]);
    }

    private function serialize(CampusNotice $notice): array
    {
        $notice->loadMissing(['creator:id,username', 'publisher:id,username']);

        return [
            ...$notice->only([
                'id', 'title', 'message', 'category', 'priority', 'audiences', 'status',
                'publication_start_at', 'publication_end_at', 'effective_start_at', 'effective_end_at',
                'is_recurring', 'recurrence_weekdays', 'requires_acknowledgement', 'official_source',
                'affected_scope', 'target_route', 'published_at', 'archived_at', 'created_at', 'updated_at',
            ]),
            'creator_name' => $notice->creator?->username,
            'publisher_name' => $notice->publisher?->username,
        ];
    }
}
