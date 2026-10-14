<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Event\EventAttendanceService;
use App\Services\Event\EventAuthorizationService;
use App\Support\ClientPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventAttendanceController extends Controller
{
    public function __construct(
        private readonly EventAttendanceService $attendance,
        private readonly EventAuthorizationService $authorization,
    ) {}

    public function state(Request $request, Event $event): JsonResponse
    {
        $this->activeOperator($request, $event);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json(['success' => true, 'data' => $this->attendance->state($event, $validated['search'] ?? null, $validated['per_page'] ?? 50)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, Event $event): JsonResponse
    {
        $actor = $this->activeSessionManager($request, $event);
        $session = $this->attendance->open($event, $actor);

        return response()->json(['success' => true, 'message' => 'Attendance is open.', 'data' => $this->attendance->serializeSession($session)], 201);
    }

    public function close(Request $request, Event $event): JsonResponse
    {
        $actor = $this->activeSessionManager($request, $event);
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $session = $this->attendance->close($event, (int) $validated['version'], $actor);

        return response()->json(['success' => true, 'message' => 'Attendance is closed.', 'data' => $this->attendance->serializeSession($session)]);
    }

    public function token(Request $request, Event $event): JsonResponse
    {
        $this->activeSessionManager($request, $event);

        return response()->json(['success' => true, 'data' => $this->attendance->token($event)])->header('Cache-Control', 'private, no-store');
    }

    public function scan(Request $request, Event $event): JsonResponse
    {
        $student = $this->activeStudent($request);
        $validated = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $result = $this->attendance->scan($event, $student, $validated['token'], (string) $request->header(ClientPlatform::HEADER));

        return response()->json([
            'success' => $result['http'] < 400, 'code' => $result['code'], 'message' => $result['message'],
            'data' => $result['attendance'] ? ['event' => ['id' => $event->id, 'title' => $event->title], 'attendance' => $this->attendance->serializeAttendance($result['attendance'])] : null,
        ], $result['http'])->header('Cache-Control', 'private, no-store');
    }

    public function participantQr(Request $request, Event $event): JsonResponse
    {
        $student = $this->activeStudent($request);
        $validated = $request->validate(['mode' => ['required', Rule::in(['static', 'dynamic'])]]);
        abort_unless($this->attendance->studentIsEligible($event, $student), 403, 'You are not included in this Event audience.');

        return response()->json([
            'success' => true,
            'data' => $this->attendance->participantQr($event, $student, $validated['mode']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function operatorScan(Request $request, Event $event): JsonResponse
    {
        $actor = $this->activeOperator($request, $event);
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'workflow' => ['required', Rule::in(['static', 'dynamic'])],
        ]);
        $allowed = $validated['workflow'] === 'static'
            ? $this->authorization->canUseRegisteredModeratorScanner($event, $actor)
            : $this->authorization->canUseRequestedModeratorScanner($event, $actor);
        abort_unless($allowed, 403, 'This Event responsibility cannot use the selected QR workflow.');

        $result = $this->attendance->operatorScan(
            $event,
            $actor,
            $validated['token'],
            $validated['workflow'],
            (string) $request->header(ClientPlatform::HEADER),
        );

        return response()->json([
            'success' => $result['http'] < 400,
            'code' => $result['code'],
            'message' => $result['message'],
            'data' => $result['attendance'] ? ['event' => ['id' => $event->id, 'title' => $event->title], 'attendance' => $this->attendance->serializeAttendance($result['attendance'])] : null,
        ], $result['http']);
    }

    public function manual(Request $request, Event $event): JsonResponse
    {
        $actor = $this->activeOperator($request, $event);
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'status' => ['required', Rule::in(EventAttendance::STATUSES)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $record = $this->attendance->manual($event, Student::query()->findOrFail($validated['student_id']), $validated['status'], trim($validated['reason']), $actor);

        return response()->json(['success' => true, 'message' => 'Attendance recorded manually.', 'data' => $this->attendance->serializeAttendance($record)], 201);
    }

    public function correct(Request $request, Event $event, EventAttendance $attendance): JsonResponse
    {
        $actor = $this->activeCoordinator($request, $event);
        $validated = $request->validate([
            'status' => ['required', Rule::in(EventAttendance::STATUSES)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $record = $this->attendance->correct($event, $attendance, $validated['status'], trim($validated['reason']), (int) $validated['version'], $actor);

        return response()->json(['success' => true, 'message' => 'Attendance corrected.', 'data' => $this->attendance->serializeAttendance($record)]);
    }

    private function activeCoordinator(Request $request, Event $event): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing('role');
        abort_unless($this->authorization->isCoordinator($user, $event), 403, 'You are not authorized to manage Event attendance.');

        return $user;
    }

    private function activeSessionManager(Request $request, Event $event): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing('role');
        abort_unless($this->authorization->canManageAttendanceSession($event, $user), 403, 'You are not authorized to manage this Event attendance session.');

        return $user;
    }

    private function activeOperator(Request $request, Event $event): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing('role');
        abort_unless($this->authorization->canOperateAttendance($event, $user), 403, 'You are not authorized to operate attendance for this Event.');

        return $user;
    }

    private function activeStudent(Request $request): Student
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing(['role', 'student']);
        abort_unless($user->role?->role_name === Role::STUDENT && $user->student, 403, 'Only Students may scan Event attendance QR codes.');

        return $user->student;
    }
}
