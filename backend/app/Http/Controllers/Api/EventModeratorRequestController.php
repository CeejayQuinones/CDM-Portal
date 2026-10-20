<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventModeratorRequest;
use App\Models\User;
use App\Services\Event\EventAuthorizationService;
use App\Services\Event\EventModeratorRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventModeratorRequestController extends Controller
{
    public function __construct(
        private readonly EventModeratorRequestService $requests,
        private readonly EventAuthorizationService $authorization,
    ) {}

    public function store(Request $request, Event $event): JsonResponse
    {
        $user = $this->activeUser($request);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $moderatorRequest = $this->requests->request($event, $user, $validated['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Moderator request submitted for review.',
            'data' => $this->requests->serialize($moderatorRequest),
        ], 201);
    }

    public function index(Request $request, Event $event): JsonResponse
    {
        $user = $this->activeUser($request);
        abort_unless($this->authorization->canViewPersonnel($event, $user), 403, 'You are not authorized to view moderator requests.');
        $validated = $request->validate(['status' => ['nullable', Rule::in(EventModeratorRequest::STATUSES)]]);

        return response()->json(['success' => true, 'data' => $this->requests->list($event, $validated['status'] ?? null)]);
    }

    public function review(Request $request, Event $event, EventModeratorRequest $moderatorRequest, string $action): JsonResponse
    {
        $user = $this->activeUser($request);
        abort_unless($this->authorization->isCoordinator($user, $event), 403, 'Only an Event Coordinator may review moderator requests.');
        abort_unless(in_array($action, ['approve', 'reject', 'revoke'], true), 404);
        $validated = $request->validate([
            'reason' => [Rule::requiredIf(in_array($action, ['reject', 'revoke'], true)), 'nullable', 'string', 'min:5', 'max:1000'],
        ]);
        $result = $this->requests->review($event, $moderatorRequest, $action, $user, $validated['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Moderator request '.($action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'revoked')).'.',
            'data' => $this->requests->serialize($result),
        ]);
    }

    private function activeUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing(['role', 'student']);

        return $user;
    }
}
