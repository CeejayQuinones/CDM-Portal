<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventAudience;
use App\Models\EventModeratorRequest;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Services\Event\EventAudienceResolver;
use App\Services\Event\EventAuthorizationService;
use App\Services\Event\EventCertificateService;
use App\Services\Event\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function __construct(
        private readonly EventAudienceResolver $audiences,
        private readonly EventService $events,
        private readonly EventAuthorizationService $authorization,
        private readonly EventCertificateService $certificates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in([...Event::MANAGED_STATUSES, 'ongoing', 'completed'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $assignment = fn ($query) => $query->granting()->where('user_id', $user->id);
        $pendingRequest = fn ($query) => $query->where('requester_user_id', $user->id)->where('status', 'pending');
        $query = $this->audiences->visibleTo(Event::query(), $user)->with([
            'creator.profile',
            'audiences.course:id,course_code,course_name', 'audiences.section:id,section_name,year_level',
            'parent.audiences.course:id,course_code,course_name', 'parent.audiences.section:id,section_name,year_level',
            'roleAssignments' => $assignment, 'parent.roleAssignments' => $assignment,
            'moderatorRequests' => $pendingRequest,
        ]);
        $query->when($validated['search'] ?? null, fn ($q, $search) => $q->where(fn ($inner) => $inner->where('title', 'like', "%$search%")->orWhere('venue', 'like', "%$search%")))
            ->when($validated['status'] ?? null, fn ($q, $status) => $status === 'completed' ? $q->where('status', 'published')->where('ends_at', '<=', now()) : ($status === 'ongoing' ? $q->where('status', 'published')->where('starts_at', '<=', now())->where('ends_at', '>', now()) : $q->where('status', $status)))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('ends_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<=', Carbon::parse($to)->endOfDay()));
        $page = $query->orderBy('starts_at')->paginate($validated['per_page'] ?? 30);
        $page->getCollection()->transform(fn (Event $event) => $this->serialize($event, $user));

        return response()->json([
            'success' => true,
            'data' => $page,
            'event_access' => ['can_create_event' => $this->authorization->isCoordinator($user)],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $user = $this->activeUser($request);
        abort_unless($this->audiences->canView($event, $user), 404, 'Event not found.');
        $assignment = fn ($query) => $query->granting()->where('user_id', $user->id);
        $pendingRequest = fn ($query) => $query->where('requester_user_id', $user->id)->where('status', 'pending');
        $event->load([
            'creator.profile',
            'audiences.course:id,course_code,course_name', 'audiences.section:id,section_name,year_level',
            'parent.audiences.course:id,course_code,course_name', 'parent.audiences.section:id,section_name,year_level',
            'roleAssignments' => $assignment, 'parent.roleAssignments' => $assignment,
            'moderatorRequests' => $pendingRequest,
            'subEvents.audiences.course:id,course_code,course_name', 'subEvents.audiences.section:id,section_name,year_level',
            'subEvents.parent.audiences.course:id,course_code,course_name', 'subEvents.parent.audiences.section:id,section_name,year_level',
            'subEvents.roleAssignments' => $assignment, 'subEvents.parent.roleAssignments' => $assignment,
            'subEvents.moderatorRequests' => $pendingRequest,
            'certificateRequirements.requiredEvent:id,title',
        ]);
        if ($this->authorization->isCoordinator($user, $event)) {
            $event->load(['auditEvents']);
        }

        $data = $this->serialize($event, $user, true);
        if ($user->role?->role_name === Role::STUDENT && $user->student) {
            $attendance = EventAttendance::query()->where('event_id', $event->id)->where('student_id', $user->student->id)->first();
            $data['my_attendance'] = $attendance ? [
                'status' => $attendance->status, 'checked_in_at' => $attendance->checked_in_at?->toIso8601String(),
                'checked_out_at' => $attendance->checked_out_at?->toIso8601String(), 'participation_minutes' => $attendance->participation_minutes,
                'completion_status' => $attendance->completion_status, 'source' => $attendance->source,
            ] : ['status' => 'not_recorded', 'checked_in_at' => null, 'checked_out_at' => null, 'source' => null];
            $data['my_certificate'] = $this->certificates->status($event, $user->student);
        }
        if (in_array($user->role?->role_name, [Role::STUDENT, Role::PROFESSOR], true)) {
            $request = EventModeratorRequest::query()->where('event_id', $event->id)
                ->where('requester_user_id', $user->id)->latest('requested_at')->first();
            $data['my_moderator_request'] = $request ? [
                'id' => $request->id,
                'status' => $request->status,
                'requested_at' => $request->requested_at?->toIso8601String(),
                'review_reason' => $request->review_reason,
            ] : null;
        }

        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function options(Request $request): JsonResponse
    {
        $this->activeEventManager($request);

        return response()->json(['success' => true, 'data' => [
            'courses' => Course::query()->where('status', 'active')->orderBy('course_code')->get(['id', 'course_code', 'course_name', 'years']),
            'sections' => Section::query()->where('status', 'open')
                ->whereHas('academicYear', fn ($query) => $query->where('status', 'active'))
                ->whereHas('semester', fn ($query) => $query->where('status', 'active'))
                ->with(['course:id,course_code'])->orderBy('section_name')->get(['id', 'course_id', 'section_name', 'year_level']),
            'year_levels' => [1, 2, 3, 4, 5], 'audience_types' => EventAudience::TYPES,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $actor = $this->activeCreator($request, $payload['parent_event_id'] ?? null);
        $event = $this->events->create($payload, $actor);

        return response()->json(['success' => true, 'message' => $event->status === 'published' ? 'Event published.' : 'Draft saved.', 'data' => $this->serialize($event, $actor)], 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $actor = $this->activeEditor($request, $event);
        $event = $this->events->update($event, $this->validatedPayload($request, true), $actor);

        return response()->json(['success' => true, 'message' => 'Event updated.', 'data' => $this->serialize($event, $actor)]);
    }

    public function transition(Request $request, Event $event, string $action): JsonResponse
    {
        $actor = $this->activeCoordinator($request, $event);
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $event = $this->events->transition($event, $action, (int) $validated['version'], $actor);

        return response()->json(['success' => true, 'message' => 'Event status updated.', 'data' => $this->serialize($event, $actor)]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $actor = $this->activeUser($request);
        abort_unless($event->parent_event_id && $this->authorization->canManageSubEvents($event->parent, $actor), 403, 'You are not authorized to archive this sub-event.');
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $event = $this->events->archiveSubEvent($event, (int) $validated['version'], $actor);

        return response()->json(['success' => true, 'message' => 'Sub-event archived.', 'data' => $this->serialize($event, $actor)]);
    }

    private function validatedPayload(Request $request, bool $updating = false): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'],
            'parent_event_id' => ['nullable', 'integer', 'exists:events,id'],
            'venue' => ['required', 'string', 'max:180'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'attendance_mode' => ['sometimes', Rule::in(Event::ATTENDANCE_MODES)],
            'late_threshold_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'minimum_participation_minutes' => ['nullable', 'integer', 'between:1,10080'],
            'attendance_methods' => ['sometimes', 'array', 'min:1', 'max:5'],
            'attendance_methods.*' => ['required', 'distinct', Rule::in(Event::ATTENDANCE_METHODS)],
            'intent' => ['sometimes', Rule::in(['draft', 'publish'])], 'version' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'audiences' => ['nullable', 'array', 'max:20'], 'audiences.*.audience_type' => ['required', Rule::in(EventAudience::TYPES)],
            'audiences.*.course_id' => ['nullable', 'integer'], 'audiences.*.year_level' => ['nullable', 'integer', 'between:1,10'], 'audiences.*.section_id' => ['nullable', 'integer'],
        ]);

        foreach (['starts_at', 'ends_at'] as $field) {
            $value = (string) $validated[$field];
            $validated[$field] = Carbon::parse($value)
                ->utc()
                ->format('Y-m-d H:i:s');
        }

        return $validated;
    }

    private function activeUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing(['role', 'student']);
        abort_unless(in_array($user->role?->role_name, [Role::ADMIN, Role::STUDENT, Role::PROFESSOR], true), 403, 'You are not authorized to access Events.');

        return $user;
    }

    private function activeEventManager(Request $request): User
    {
        $user = $this->activeUser($request);
        abort_unless($this->authorization->isCoordinator($user) || $this->authorization->hasSemiCoordinatorAssignment($user), 403, 'You are not authorized to manage Events.');

        return $user;
    }

    private function activeCreator(Request $request, ?int $parentId): User
    {
        $user = $this->activeUser($request);
        if ($parentId !== null) {
            $parent = Event::query()->findOrFail($parentId);
            abort_unless($this->authorization->canManageSubEvents($parent, $user), 403, 'You are not authorized to create a sub-event for this Event.');

            return $user;
        }

        abort_unless($this->authorization->isCoordinator($user), 403, 'Only an Event Coordinator may create a top-level Event.');

        return $user;
    }

    private function activeEditor(Request $request, Event $event): User
    {
        $user = $this->activeUser($request);
        abort_unless($this->authorization->canEditEvent($event, $user), 403, 'You are not authorized to edit this Event.');

        return $user;
    }

    private function activeCoordinator(Request $request, Event $event): User
    {
        $user = $this->activeUser($request);
        abort_unless($this->authorization->isCoordinator($user, $event), 403, 'Only an Event Coordinator may change Event status.');

        return $user;
    }

    private function serialize(Event $event, User $user, bool $detail = false): array
    {
        $effectiveAudiences = $event->audiences->isNotEmpty() ? $event->audiences : ($event->parent?->audiences ?? collect());
        $capabilities = $this->authorization->capabilities($event, $user);
        $eligibleStudent = $detail
            && $user->role?->role_name === Role::STUDENT
            && $user->student
            && $this->audiences->isStudentEligible($event, $user->student);
        $capabilities['can_self_scan'] = (bool) $eligibleStudent;
        $capabilities['can_scan_event_qr'] = (bool) $eligibleStudent
            && ($event->supportsAttendanceMethod('dynamic_event') || $event->supportsAttendanceMethod('static_event'));
        $capabilities['can_generate_participant_qr'] = (bool) $eligibleStudent
            && ($event->supportsAttendanceMethod('participant_static') || $event->supportsAttendanceMethod('participant_dynamic'));
        $capabilities['can_generate_static_participant_qr'] = (bool) $eligibleStudent
            && $event->supportsAttendanceMethod('participant_static');
        $capabilities['can_generate_dynamic_participant_qr'] = (bool) $eligibleStudent
            && $event->supportsAttendanceMethod('participant_dynamic');
        $data = [
            'id' => $event->id, 'parent_event_id' => $event->parent_event_id, 'sort_order' => $event->sort_order,
            'title' => $event->title, 'description' => $event->description, 'venue' => $event->venue,
            'starts_at' => $event->starts_at?->toIso8601String(), 'ends_at' => $event->ends_at?->toIso8601String(),
            'status' => $event->presentationStatus(), 'managed_status' => $event->status, 'version' => $event->version,
            'creator' => [
                'id' => $event->created_by,
                'name' => trim(implode(' ', array_filter([$event->creator?->profile?->first_name, $event->creator?->profile?->middle_name, $event->creator?->profile?->last_name]))) ?: 'Event Coordinator',
            ],
            'audience_inherited' => $event->audiences->isEmpty() && $event->parent !== null,
            'attendance_policy' => [
                'mode' => $event->attendance_mode,
                'late_threshold_minutes' => $event->late_threshold_minutes,
                'minimum_participation_minutes' => $event->minimum_participation_minutes,
                'methods' => $event->attendance_methods ?: Event::DEFAULT_ATTENDANCE_METHODS,
            ],
            'certificate_settings' => [
                'enabled' => (bool) $event->certificates_enabled,
                'minimum_minutes' => $event->certificate_minimum_minutes,
                'required_event_ids' => $event->relationLoaded('certificateRequirements') ? $event->certificateRequirements->pluck('required_event_id')->values() : [],
            ],
            'audiences' => $effectiveAudiences->map(fn ($a) => [
                'id' => $a->id, 'audience_type' => $a->audience_type, 'course_id' => $a->course_id, 'year_level' => $a->year_level, 'section_id' => $a->section_id,
                'label' => match ($a->audience_type) {
                    'all_students' => 'All Students', 'all_professors' => 'All Professors', 'all_users' => 'All Students and Professors',
                    'course' => $a->course?->course_code ?? 'Course', 'year_level' => 'Year '.$a->year_level,
                    'section' => $a->section?->section_name ?? 'Section', default => 'Audience',
                },
            ])->values(),
            'capabilities' => $capabilities,
        ];
        if ($detail && $event->relationLoaded('auditEvents')) {
            $data['audit'] = $event->auditEvents->map(fn ($audit) => ['action' => $audit->action, 'actor_role' => $audit->actor_role, 'metadata' => $audit->metadata, 'created_at' => $audit->created_at?->toIso8601String()]);
        }
        if ($detail && $event->relationLoaded('subEvents')) {
            $data['sub_events'] = $event->subEvents->map(fn (Event $child) => $this->serialize($child, $user))->values();
        }

        return $data;
    }
}
