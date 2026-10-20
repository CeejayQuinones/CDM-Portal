<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventModeratorRequest;
use App\Models\EventRoleAssignment;
use App\Models\User;
use App\Services\Event\EventAudienceResolver;
use App\Services\Event\EventAuthorizationService;
use App\Services\Event\EventModeratorRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventExperienceController extends Controller
{
    public function __construct(
        private readonly EventModeratorRequestService $moderatorRequests,
        private readonly EventAudienceResolver $audiences,
        private readonly EventAuthorizationService $authorization,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $user->loadMissing(['profile', 'role', 'student.course']);
        $assignments = EventRoleAssignment::query()->granting()->where('user_id', $user->id)
            ->with('event:id,title,starts_at,ends_at,status')->latest('assigned_at')->get()
            ->map(fn (EventRoleAssignment $assignment) => [
                'event_id' => $assignment->event_id,
                'event_title' => $assignment->event?->title,
                'responsibility' => $assignment->canonicalResponsibility(),
                'responsibility_label' => $assignment->responsibilityLabel(),
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ])->values();
        $requests = EventModeratorRequest::query()->where('requester_user_id', $user->id)
            ->with(['requester.role', 'requester.profile', 'assignment'])->latest('requested_at')->get()
            ->map(fn (EventModeratorRequest $item) => $this->moderatorRequests->serialize($item))->values();
        $requestStatuses = $requests->unique('event_id')->keyBy('event_id');
        $assignment = fn ($query) => $query->granting()->where('user_id', $user->id);
        $pendingRequest = fn ($query) => $query->where('requester_user_id', $user->id)->where('status', 'pending');
        $availableEvents = $this->audiences->visibleTo(Event::query(), $user)
            ->where('status', 'published')->where('ends_at', '>', now())->orderBy('starts_at')
            ->with([
                'roleAssignments' => $assignment,
                'parent.roleAssignments' => $assignment,
                'moderatorRequests' => $pendingRequest,
            ])
            ->get(['id', 'parent_event_id', 'title', 'venue', 'starts_at', 'ends_at', 'status', 'attendance_methods'])
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'venue' => $event->venue,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'participant_qr_modes' => collect(['static', 'dynamic'])->filter(
                    fn (string $mode) => $event->supportsAttendanceMethod('participant_'.$mode)
                )->values(),
                'moderator_request_status' => $requestStatuses->get($event->id)['status'] ?? null,
                'moderator_request' => $requestStatuses->get($event->id),
                'can_request_moderator' => $this->authorization->canRequestModerator($event, $user),
            ])->values();

        $history = $user->student ? EventAttendance::query()
            ->where('student_id', $user->student->id)
            ->with('event:id,title,starts_at,ends_at,venue')
            ->latest('checked_in_at')->paginate(20) : null;
        if ($history) {
            $history->getCollection()->transform(fn (EventAttendance $attendance) => [
                'event_id' => $attendance->event_id,
                'title' => $attendance->event?->title,
                'date' => $attendance->event?->starts_at?->toDateString(),
                'venue' => $attendance->event?->venue,
                'status' => $attendance->status,
                'method' => $attendance->source,
                'checked_in_at' => $attendance->checked_in_at?->toIso8601String(),
            ]);
        }

        $profile = $user->profile;
        $pendingModeratorRequest = $requests->firstWhere('status', 'pending');
        $primaryAssignment = $assignments->first();
        $eventResponsibility = $this->authorization->isCoordinator($user)
            ? ['key' => 'coordinator', 'label' => 'Coordinator']
            : ($primaryAssignment
                ? ['key' => $primaryAssignment['responsibility'], 'label' => $primaryAssignment['responsibility_label']]
                : ($pendingModeratorRequest
                    ? ['key' => EventRoleAssignment::REQUESTED_MODERATOR, 'label' => 'Requested Moderator']
                    : ['key' => 'participant', 'label' => 'Participant']));

        return response()->json(['success' => true, 'data' => [
            'identity' => [
                'name' => trim(implode(' ', array_filter([$profile?->first_name, $profile?->middle_name, $profile?->last_name]))),
                'email' => $profile?->email,
                'portal_role' => $user->role?->role_name,
                'account_status' => $user->status,
                'student_number' => $user->student?->student_number,
                'course' => $user->student?->course?->course_code,
                'profile_photo_url' => $profile?->profile_photo_url,
                'can_use_personal_qr' => $user->student !== null,
                'event_responsibility' => $eventResponsibility['key'],
                'event_responsibility_label' => $eventResponsibility['label'],
            ],
            'responsibilities' => $assignments,
            'moderator_requests' => $requests,
            'available_events' => $availableEvents,
            'attended_events' => $history ?? ['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0],
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $notifications = $user->notifications()->where('data->module', 'event')->latest()->paginate(25);
        $notifications->getCollection()->transform(fn ($notification) => [
            'id' => $notification->id,
            'event' => $notification->data['event'] ?? 'event_update',
            'message' => $notification->data['message'] ?? 'Event notification',
            'event_id' => $notification->data['event_id'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'data' => $notifications, 'unread_count' => $user->unreadNotifications()->where('data->module', 'event')->count()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $user = $this->activeUser($request);
        $record = $user->notifications()->whereKey($notification)->where('data->module', 'event')->firstOrFail();
        $record->markAsRead();

        return response()->json(['success' => true, 'message' => 'Notification marked as read.']);
    }

    private function activeUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');

        return $user;
    }
}
