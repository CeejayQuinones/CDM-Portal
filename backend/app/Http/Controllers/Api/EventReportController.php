<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\User;
use App\Services\Event\EventAuthorizationService;
use App\Services\Event\EventReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventReportController extends Controller
{
    public function __construct(
        private readonly EventReportService $reports,
        private readonly EventAuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->staff($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(Event::MANAGED_STATUSES)],
            'audience' => ['nullable', 'string', 'max:24'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json(['success' => true, 'data' => $this->reports->index($validated, $actor)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $actor = $this->staff($request);
        abort_unless($this->authorization->canViewReports($event, $actor), 403, 'You are not authorized to view this Event report.');
        $data = $this->reports->report($event, $this->filters($request), $actor);
        $data['capabilities'] = ['can_export' => $this->authorization->isCoordinator($actor, $event)];

        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, Event $event): StreamedResponse
    {
        $actor = $this->staff($request);
        abort_unless($this->authorization->isCoordinator($actor, $event), 403, 'Only an Event Coordinator may export Event reports.');
        $actor->loadMissing('profile');
        $validated = $this->filters($request) + $request->validate(['format' => ['required', Rule::in(['csv'])]]);

        return $this->reports->csv($event, $validated, $actor);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in([...EventAttendance::STATUSES, 'not_recorded'])],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'], 'year_level' => ['nullable', 'integer', 'between:1,10'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'], 'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['student', 'check_in', 'status'])], 'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        abort_unless($this->authorization->isCoordinator($user) || $this->authorization->hasSemiCoordinatorAssignment($user), 403, 'You are not authorized to access Event reports.');

        return $user;
    }
}
