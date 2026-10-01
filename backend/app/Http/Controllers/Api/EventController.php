<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAudience;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Services\Event\EventAudienceResolver;
use App\Services\Event\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function __construct(private readonly EventAudienceResolver $audiences, private readonly EventService $events) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in([...Event::MANAGED_STATUSES, 'ongoing', 'completed'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = $this->audiences->visibleTo(Event::query(), $user)->with(['audiences.course:id,course_code,course_name', 'audiences.section:id,section_name,year_level']);
        $query->when($validated['search'] ?? null, fn ($q, $search) => $q->where(fn ($inner) => $inner->where('title', 'like', "%$search%")->orWhere('venue', 'like', "%$search%")))
            ->when($validated['status'] ?? null, fn ($q, $status) => $status === 'completed' ? $q->where('status', 'published')->where('ends_at', '<=', now()) : ($status === 'ongoing' ? $q->where('status', 'published')->where('starts_at', '<=', now())->where('ends_at', '>', now()) : $q->where('status', $status)))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('ends_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<=', Carbon::parse($to)->endOfDay()));
        $page = $query->orderBy('starts_at')->paginate($validated['per_page'] ?? 30);
        $page->getCollection()->transform(fn (Event $event) => $this->serialize($event));

        return response()->json(['success' => true, 'data' => $page])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $user = $this->activeUser($request);
        abort_unless($this->audiences->canView($event, $user), 404, 'Event not found.');
        $event->load(['audiences.course:id,course_code,course_name', 'audiences.section:id,section_name,year_level']);
        if ($this->isStaff($user)) {
            $event->load(['auditEvents']);
        }

        return response()->json(['success' => true, 'data' => $this->serialize($event, true)])->header('Cache-Control', 'private, no-store');
    }

    public function options(Request $request): JsonResponse
    {
        $this->activeStaff($request);

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
        $actor = $this->activeStaff($request);
        $event = $this->events->create($this->validatedPayload($request), $actor);

        return response()->json(['success' => true, 'message' => $event->status === 'published' ? 'Event published.' : 'Draft saved.', 'data' => $this->serialize($event)], 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $actor = $this->activeStaff($request);
        $event = $this->events->update($event, $this->validatedPayload($request, true), $actor);

        return response()->json(['success' => true, 'message' => 'Event updated.', 'data' => $this->serialize($event)]);
    }

    public function transition(Request $request, Event $event, string $action): JsonResponse
    {
        $actor = $this->activeStaff($request);
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $event = $this->events->transition($event, $action, (int) $validated['version'], $actor);

        return response()->json(['success' => true, 'message' => 'Event status updated.', 'data' => $this->serialize($event)]);
    }

    private function validatedPayload(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'],
            'venue' => ['required', 'string', 'max:180'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'intent' => ['sometimes', Rule::in(['draft', 'publish'])], 'version' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'audiences' => ['required', 'array', 'min:1', 'max:20'], 'audiences.*.audience_type' => ['required', Rule::in(EventAudience::TYPES)],
            'audiences.*.course_id' => ['nullable', 'integer'], 'audiences.*.year_level' => ['nullable', 'integer', 'between:1,10'], 'audiences.*.section_id' => ['nullable', 'integer'],
        ]);
    }

    private function activeUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing(['role', 'student']);
        abort_unless(in_array($user->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF, Role::STUDENT, Role::PROFESSOR], true), 403, 'You are not authorized to access Events.');

        return $user;
    }

    private function activeStaff(Request $request): User
    {
        $user = $this->activeUser($request);
        abort_unless($this->isStaff($user), 403, 'You are not authorized to manage Events.');

        return $user;
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
    }

    private function serialize(Event $event, bool $detail = false): array
    {
        $data = [
            'id' => $event->id, 'title' => $event->title, 'description' => $event->description, 'venue' => $event->venue,
            'starts_at' => $event->starts_at?->toIso8601String(), 'ends_at' => $event->ends_at?->toIso8601String(),
            'status' => $event->presentationStatus(), 'managed_status' => $event->status, 'version' => $event->version,
            'audiences' => $event->audiences->map(fn ($a) => [
                'id' => $a->id, 'audience_type' => $a->audience_type, 'course_id' => $a->course_id, 'year_level' => $a->year_level, 'section_id' => $a->section_id,
                'label' => match ($a->audience_type) {
                    'all_students' => 'All Students', 'all_professors' => 'All Professors', 'all_users' => 'All Students and Professors',
                    'course' => $a->course?->course_code ?? 'Course', 'year_level' => 'Year '.$a->year_level,
                    'section' => $a->section?->section_name ?? 'Section', default => 'Audience',
                },
            ])->values(),
        ];
        if ($detail && $event->relationLoaded('auditEvents')) {
            $data['audit'] = $event->auditEvents->map(fn ($audit) => ['action' => $audit->action, 'actor_role' => $audit->actor_role, 'metadata' => $audit->metadata, 'created_at' => $audit->created_at?->toIso8601String()]);
        }

        return $data;
    }
}
