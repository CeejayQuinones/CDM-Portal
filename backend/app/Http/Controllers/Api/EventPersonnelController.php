<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRoleAssignment;
use App\Models\User;
use App\Services\Event\EventAuthorizationService;
use App\Services\Event\EventPersonnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventPersonnelController extends Controller
{
    public function __construct(
        private readonly EventAuthorizationService $authorization,
        private readonly EventPersonnelService $personnel,
    ) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->viewer($request, $event);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return response()->json(['success' => true, 'data' => $this->personnel->index($event, $validated['search'] ?? null)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $actor = $this->coordinator($request, $event);
        $validated = $this->validated($request, true);
        $target = User::query()->findOrFail($validated['user_id']);
        $assignment = $this->personnel->assign($event, $target, $validated['responsibility'], $actor, $validated['starts_at'] ?? null, $validated['ends_at'] ?? null);

        return response()->json(['success' => true, 'message' => 'Event personnel assigned.', 'data' => $this->personnel->serialize($assignment)], 201);
    }

    public function update(Request $request, Event $event, EventRoleAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->event_id === $event->id, 404, 'Event personnel assignment not found.');
        $actor = $this->coordinator($request, $event);
        $validated = $this->validated($request);
        $assignment = $this->personnel->assign($event, $assignment->user, $validated['responsibility'], $actor, $validated['starts_at'] ?? null, $validated['ends_at'] ?? null);

        return response()->json(['success' => true, 'message' => 'Event responsibility updated.', 'data' => $this->personnel->serialize($assignment)]);
    }

    public function revoke(Request $request, Event $event, EventRoleAssignment $assignment): JsonResponse
    {
        $actor = $this->coordinator($request, $event);
        $assignment = $this->personnel->revoke($event, $assignment, $actor);

        return response()->json(['success' => true, 'message' => 'Event responsibility revoked.', 'data' => $this->personnel->serialize($assignment)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withUser = false): array
    {
        return $request->validate([
            'user_id' => [$withUser ? 'required' : 'sometimes', 'integer', 'exists:users,id'],
            'responsibility' => ['required', Rule::in(EventRoleAssignment::RESPONSIBILITIES)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
    }

    private function coordinator(Request $request, Event $event): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->authorization->isCoordinator($user, $event), 403, 'You are not authorized to manage Event personnel.');

        return $user;
    }

    private function viewer(Request $request, Event $event): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->authorization->canViewPersonnel($event, $user), 403, 'You are not authorized to view Event personnel.');

        return $user;
    }
}
