<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\Student;
use App\Services\EarlyWarningService;
use App\Services\MonitoringAiHelpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MonitoringController extends Controller
{
    public function __construct(private readonly EarlyWarningService $warnings, private readonly MonitoringAiHelpService $ai) {}

    public function earlyWarnings(Request $request): JsonResponse
    {
        $role = $request->user()->role?->role_name;

        return $this->ok($role === Role::STUDENT
            ? $this->warnings->assessForUserId($request->user()->id)
            : $this->warnings->overview($role === Role::PROFESSOR ? $request->user()->id : null));
    }

    public function myRisk(Request $request): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only student accounts can load personal risk dashboards.');
        }

        return $this->ok($this->warnings->assessForUserId($request->user()->id));
    }

    public function supportPlan(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }
        $assessment = $this->warnings->assessByStudentId($student);

        return $assessment ? $this->ok($this->warnings->generateSupportPlan($assessment)) : $this->notFound();
    }

    public function studyPlans(Request $request): JsonResponse
    {
        if ($request->user()->role?->role_name === Role::STUDENT) {
            $assessment = $this->warnings->assessForUserId($request->user()->id)['students'][0] ?? null;

            return $this->ok(['summary' => ['total' => $assessment ? 1 : 0], 'plans' => $assessment ? [$this->warnings->generateStudyPlan($assessment)] : []]);
        }

        $professorUserId = $request->user()->role?->role_name === Role::PROFESSOR ? $request->user()->id : null;

        return $this->ok(collect($this->warnings->overview($professorUserId)['students'])->map(fn ($a) => $this->warnings->generateStudyPlan($a))->values()->all());
    }

    public function studyPlan(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }
        $assessment = $this->warnings->assessByStudentId($student);

        return $assessment ? $this->ok($this->warnings->generateStudyPlan($assessment)) : $this->notFound();
    }

    public function adviserAlerts(Request $request): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if (! in_array($role, [Role::PROFESSOR, Role::REGISTRAR_STAFF], true)) {
            return $this->forbidden('Adviser alerts are only available to professors and registrar staff.');
        }

        return $this->ok($this->warnings->adviserAlerts($role === Role::PROFESSOR ? $request->user()->id : null));
    }

    public function aiStatus(): JsonResponse
    {
        return $this->ok(['configured' => filled(config('services.document_analysis.gemini.api_key')) && filled(config('services.document_analysis.gemini.model')), 'provider' => 'gemini']);
    }

    public function aiHelp(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }
        $validated = $request->validate(['question' => ['required', 'string', 'max:1500']]);
        $assessment = $this->warnings->assessByStudentId($student);
        if (! $assessment) {
            return $this->notFound();
        }
        try {
            return $this->ok($this->ai->generateHelp($assessment, $validated['question']));
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public function sendRiskNotification(Request $request, int $student): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::PROFESSOR) {
            return $this->forbidden('Only professors can notify students.');
        }
        if (! $this->warnings->professorCanAccessStudent($request->user()->id, $student)) {
            return $this->forbidden('You can only notify students in your assigned subjects.');
        }
        $assessment = $this->warnings->assessByStudentId($student);
        if (! $assessment) {
            return $this->notFound();
        }
        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);
        $notification = RiskNotification::query()->create(['student_id' => $student, 'sender_user_id' => $request->user()->id, 'risk_level' => $assessment['risk_level'], 'title' => 'Academic risk notice', 'message' => trim($data['message'] ?? '') ?: $assessment['headline']]);

        return response()->json(['success' => true, 'data' => $this->notification($notification)], 201);
    }

    public function myRiskNotifications(Request $request): JsonResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->first();
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can view personal risk notifications.');
        }
        $items = $student ? RiskNotification::query()->where('student_id', $student->id)->latest()->limit(30)->get()->map(fn ($n) => $this->notification($n))->all() : [];

        return $this->ok(['unread' => collect($items)->where('is_read', false)->count(), 'notifications' => $items]);
    }

    public function markRiskNotificationRead(Request $request, int $notification): JsonResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->first();
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can mark notifications as read.');
        }
        $record = $student ? RiskNotification::query()->whereKey($notification)->where('student_id', $student->id)->first() : null;
        if (! $record) {
            return $this->notFound('Notification not found.');
        } $record->update(['read_at' => now()]);

        return $this->ok($this->notification($record->fresh()));
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
        return ['id' => $n->id, 'risk_level' => $n->risk_level, 'title' => $n->title, 'message' => $n->message, 'is_read' => (bool) $n->read_at, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String()];
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
