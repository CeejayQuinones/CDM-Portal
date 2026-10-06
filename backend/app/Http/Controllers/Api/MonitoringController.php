<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\Student;
use App\Services\EarlyWarningService;
use App\Services\MonitoringAiHelpService;
use App\Services\MonitoringInterventionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonitoringController extends Controller
{
    public function __construct(
        private readonly EarlyWarningService $warnings,
        private readonly MonitoringAiHelpService $ai,
        private readonly MonitoringInterventionService $interventions,
    ) {}

    public function earlyWarnings(Request $request): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        $filters = $this->filters($request);

        return $this->ok($role === Role::STUDENT
            ? $this->warnings->assessForUserId($request->user()->id, $filters)
            : $this->warnings->overview($role === Role::PROFESSOR ? $request->user()->id : null, $filters));
    }

    public function myRisk(Request $request): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only student accounts can load personal risk dashboards.');
        }

        return $this->ok($this->warnings->assessForUserId($request->user()->id, $this->filters($request)));
    }

    public function supportPlan(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }
        $assessment = $this->assessmentFor($request, $student);

        return $assessment ? $this->ok($this->warnings->generateSupportPlan($assessment)) : $this->notFound();
    }

    public function studyPlans(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        if ($request->user()->role?->role_name === Role::STUDENT) {
            $assessment = $this->warnings->assessForUserId($request->user()->id, $filters)['students'][0] ?? null;

            return $this->ok(['summary' => ['total' => $assessment ? 1 : 0, 'with_focus_subjects' => $assessment && $assessment['risk_level'] !== 'insufficient' ? 1 : 0], 'plans' => $assessment ? [$this->warnings->generateStudyPlan($assessment)] : []]);
        }

        $professorUserId = $request->user()->role?->role_name === Role::PROFESSOR ? $request->user()->id : null;
        $plans = collect($this->warnings->overview($professorUserId, $filters)['students'])
            ->map(fn (array $assessment) => $this->warnings->generateStudyPlan($assessment))
            ->values();

        return $this->ok([
            'summary' => ['total' => $plans->count(), 'with_focus_subjects' => $plans->filter(fn (array $plan) => count($plan['focus_subjects']) > 0)->count()],
            'plans' => $plans->all(),
        ]);
    }

    public function studyPlan(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }
        $assessment = $this->assessmentFor($request, $student);

        return $assessment ? $this->ok($this->warnings->generateStudyPlan($assessment)) : $this->notFound();
    }

    public function adviserAlerts(Request $request): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if (! in_array($role, [Role::PROFESSOR, Role::REGISTRAR_STAFF, Role::ADMIN], true)) {
            return $this->forbidden('Adviser alerts are only available to monitoring staff.');
        }

        return $this->ok($this->warnings->adviserAlerts($role === Role::PROFESSOR ? $request->user()->id : null, $this->filters($request)));
    }

    public function aiStatus(): JsonResponse
    {
        return $this->ok($this->ai->status());
    }

    public function aiHelp(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }
        $validated = $request->validate(['question' => ['required', 'string', 'min:2', 'max:1500', 'not_regex:/<[^>]+>/']]);
        $assessment = $this->assessmentFor($request, $student);
        if (! $assessment) {
            return $this->notFound();
        }
        $target = Student::query()->find($student);

        return $this->ok($this->ai->generateHelp($request->user(), $target, $assessment, $validated['question']));
    }

    public function sendRiskNotification(Request $request, int $student): JsonResponse
    {
        $target = Student::query()->find($student);
        if (! $target) {
            return $this->notFound('Student monitoring record not found.');
        }
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $result = $this->interventions->send($request->user(), $target, $data);

        return response()->json([
            'success' => true,
            'message' => $result['duplicate'] ? 'An identical recent notice already exists.' : 'Academic support notice sent.',
            'data' => $this->notification($result['notification']) + ['duplicate' => $result['duplicate']],
        ], $result['duplicate'] ? 200 : 201);
    }

    public function myRiskNotifications(Request $request): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can view personal risk notifications.');
        }
        $student = Student::query()->where('user_id', $request->user()->id)->first();
        $query = RiskNotification::query()->where('student_id', $student?->id)->with('sender.profile');
        $unread = $student ? (clone $query)->whereNull('read_at')->count() : 0;
        $items = $student ? $query->latest()->limit(50)->get()->map(fn (RiskNotification $notification) => $this->notification($notification))->all() : [];

        return $this->ok(['unread' => $unread, 'notifications' => $items]);
    }

    public function markRiskNotificationRead(Request $request, int $notification): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can mark notifications as read.');
        }
        $student = Student::query()->where('user_id', $request->user()->id)->first();
        $record = $student ? RiskNotification::query()->whereKey($notification)->where('student_id', $student->id)->first() : null;
        if (! $record) {
            return $this->notFound('Academic support notice not found.');
        }

        return $this->ok($this->notification($this->interventions->markRead($request->user(), $record)));
    }

    private function authorizeStudent(Request $request, int $student): ?JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if ($role === Role::STUDENT && ! Student::query()->where('user_id', $request->user()->id)->whereKey($student)->exists()) {
            return $this->forbidden('You can only access your own monitoring record.');
        }
        if ($role === Role::PROFESSOR && ! $this->warnings->professorCanAccessStudent($request->user()->id, $student)) {
            return $this->forbidden('You can only access students in your assigned subjects.');
        }

        return null;
    }

    private function notification(RiskNotification $n): array
    {
        $n->loadMissing(['sender.profile', 'sender.role']);
        $profile = $n->sender?->profile;
        $senderName = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name]))) ?: 'CDM Staff';

        return [
            'id' => $n->id,
            'risk_level' => $n->risk_level,
            'title' => $n->title,
            'message' => $n->message,
            'context' => $n->context_json,
            'sender' => ['name' => $senderName, 'role' => $n->sender?->role?->role_name],
            'is_read' => (bool) $n->read_at,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
            'links' => ['monitoring' => '/monitoring', 'grades' => '/grading'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function assessmentFor(Request $request, int $student): ?array
    {
        $professorUserId = $request->user()->role?->role_name === Role::PROFESSOR
            ? $request->user()->id
            : null;

        return $this->warnings->assessByStudentId($student, $professorUserId);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'risk' => ['nullable', Rule::in(['high', 'moderate', 'stable', 'insufficient'])],
        ]);
    }

    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }

    private function notFound(string $message = 'Student grade record not found for monitoring.'): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 404);
    }
}
