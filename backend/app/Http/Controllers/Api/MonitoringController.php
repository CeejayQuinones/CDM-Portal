<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoringPerformanceRecord;
use App\Models\MonitoringSentPlan;
use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\Student;
use App\Services\EarlyWarningService;
use App\Services\MonitoringAiHelpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitoringController extends Controller
{
    public function __construct(private readonly EarlyWarningService $warnings, private readonly MonitoringAiHelpService $ai) {}

    public function earlyWarnings(Request $request): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if ($role === Role::STUDENT) {
            return $this->ok($this->warnings->assessForUserId($request->user()->id));
        }

        $filters = $request->only(['department', 'course', 'section']);

        return $this->ok($this->warnings->overview(
            $role === Role::PROFESSOR ? $request->user()->id : null,
            $filters,
        ));
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
        if (! in_array($role, [Role::PROFESSOR, Role::REGISTRAR_STAFF, Role::ADMIN], true)) {
            return $this->forbidden('Adviser alerts are only available to professors, registrar staff, and admins.');
        }

        return $this->ok($this->warnings->adviserAlerts($role === Role::PROFESSOR ? $request->user()->id : null));
    }

    public function aiStatus(): JsonResponse
    {
        return $this->ok([
            'live_ai_configured' => $this->ai->isLiveAiConfigured(),
            'provider' => config('ai.provider'),
            'model' => config('ai.model'),
        ]);
    }

    public function aiHelp(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }

        $validated = $request->validate([
            'question' => ['nullable', 'string', 'max:1000'],
            'messages' => ['nullable', 'array', 'max:20'],
            'messages.*.role' => ['required_with:messages', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required_with:messages', 'string', 'max:2000'],
        ]);

        $help = $this->ai->generateHelp(
            $student,
            $validated['question'] ?? null,
            $validated['messages'] ?? [],
        );

        return $this->ok([
            ...$help,
            'live_ai_configured' => $this->ai->isLiveAiConfigured(),
        ]);
    }

    public function listPerformanceRecords(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }

        $records = MonitoringPerformanceRecord::query()
            ->where('student_id', $student)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (MonitoringPerformanceRecord $record) => $this->performanceRecord($record));

        return $this->ok(['records' => $records]);
    }

    public function storePerformanceRecord(Request $request, int $student): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        $allowed = [Role::PROFESSOR, Role::STUDENT, Role::ADMIN, Role::REGISTRAR_STAFF];
        if (! in_array($role, $allowed, true)) {
            return $this->forbidden('You cannot add monitoring files/records with this account.');
        }

        if ($role === Role::STUDENT) {
            $ownId = Student::query()->where('user_id', $request->user()->id)->value('id');
            if ((int) $ownId !== (int) $student) {
                return $this->forbidden('Students can only upload files to their own monitoring record.');
            }
        } elseif ($role === Role::PROFESSOR) {
            if (! $this->warnings->professorCanAccessStudent($request->user()->id, $student)) {
                return $this->forbidden('You can only add records for students in your assigned subjects.');
            }
        }

        $data = $request->validate([
            'subject_code' => ['required', 'string', 'max:40'],
            'subject_name' => ['nullable', 'string', 'max:150'],
            'assessment_name' => ['required', 'string', 'max:120'],
            'topic' => ['required', 'string', 'max:255'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'max_score' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,txt,csv,xlsx,xls,png,jpg,jpeg'],
        ]);

        $path = null;
        $originalName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $originalName = $file->getClientOriginalName();
            $path = $file->store("monitoring/records/{$student}", 'local');
        }

        $record = MonitoringPerformanceRecord::query()->create([
            'student_id' => $student,
            'professor_user_id' => $request->user()->id,
            'subject_code' => $data['subject_code'],
            'subject_name' => $data['subject_name'] ?? null,
            'assessment_name' => $data['assessment_name'],
            'topic' => $data['topic'],
            'score' => $data['score'] ?? null,
            'max_score' => $data['max_score'] ?? null,
            'notes' => $data['notes'] ?? null,
            'attachment_path' => $path,
            'attachment_name' => $originalName,
        ]);

        return response()->json(['success' => true, 'data' => $this->performanceRecord($record)], 201);
    }

    public function downloadPerformanceAttachment(Request $request, int $record): StreamedResponse|JsonResponse
    {
        $performance = MonitoringPerformanceRecord::query()->find($record);
        if (! $performance || ! $performance->attachment_path) {
            return $this->notFound('Attachment not found.');
        }

        if ($response = $this->authorizeStudent($request, (int) $performance->student_id)) {
            return $response;
        }

        $role = $request->user()->role?->role_name;
        if ($role === Role::PROFESSOR
            && (int) $performance->professor_user_id !== (int) $request->user()->id
            && ! $this->warnings->professorCanAccessStudent($request->user()->id, (int) $performance->student_id)) {
            return $this->forbidden('You can only download files for your assigned students.');
        }

        if (! Storage::disk('local')->exists($performance->attachment_path)) {
            return $this->notFound('Attachment file is missing on storage.');
        }

        return Storage::disk('local')->download(
            $performance->attachment_path,
            $performance->attachment_name ?: 'monitoring-file',
        );
    }

    public function generateRecordStudyPlan(Request $request, int $student, int $record): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if (! in_array($role, [Role::PROFESSOR, Role::ADMIN], true)) {
            return $this->forbidden('Only professors and admins can generate topic study plans.');
        }
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }

        $performance = MonitoringPerformanceRecord::query()
            ->whereKey($record)
            ->where('student_id', $student)
            ->first();
        if (! $performance) {
            return $this->notFound('Performance record not found.');
        }
        if ($role === Role::PROFESSOR && (int) $performance->professor_user_id !== (int) $request->user()->id) {
            return $this->forbidden('You can only generate plans from your own records.');
        }

        $assessment = $this->warnings->assessByStudentId($student);
        if (! $assessment) {
            return $this->notFound();
        }

        try {
            $generated = $this->ai->generateTopicStudyPlan($assessment, $this->performanceRecord($performance));
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }

        return $this->ok([
            'title' => $generated['title'],
            'plan_body' => $generated['reply'],
            'source' => $generated['source'],
            'record' => $this->performanceRecord($performance),
        ]);
    }

    public function sendRecordStudyPlan(Request $request, int $student, int $record): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if ($role !== Role::PROFESSOR) {
            return $this->forbidden('Only professors can send study plans to students.');
        }
        if (! $this->warnings->professorCanAccessStudent($request->user()->id, $student)) {
            return $this->forbidden('You can only send plans to students in your assigned subjects.');
        }

        $performance = MonitoringPerformanceRecord::query()
            ->whereKey($record)
            ->where('student_id', $student)
            ->where('professor_user_id', $request->user()->id)
            ->first();
        if (! $performance) {
            return $this->notFound('Performance record not found.');
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'plan_body' => ['required', 'string', 'max:8000'],
        ]);

        $plan = MonitoringSentPlan::query()->create([
            'student_id' => $student,
            'sender_user_id' => $request->user()->id,
            'performance_record_id' => $performance->id,
            'title' => trim((string) ($data['title'] ?? '')) ?: "Study plan: {$performance->assessment_name}",
            'topic' => $performance->topic,
            'subject_code' => $performance->subject_code,
            'plan_body' => $data['plan_body'],
            'source' => 'gemini',
        ]);

        RiskNotification::query()->create([
            'student_id' => $student,
            'sender_user_id' => $request->user()->id,
            'risk_level' => 'moderate',
            'title' => $plan->title,
            'message' => 'Your instructor sent a focused study plan for '.$performance->topic.'. Open Academic Monitoring to review it.',
        ]);

        return response()->json(['success' => true, 'data' => $this->sentPlan($plan)], 201);
    }

    public function listSentPlans(Request $request, int $student): JsonResponse
    {
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }

        $plans = MonitoringSentPlan::query()
            ->where('student_id', $student)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (MonitoringSentPlan $plan) => $this->sentPlan($plan));

        return $this->ok(['plans' => $plans]);
    }

    public function mySentPlans(Request $request): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can view received study plans.');
        }

        $student = Student::query()->where('user_id', $request->user()->id)->first();
        $plans = $student
            ? MonitoringSentPlan::query()->where('student_id', $student->id)->latest()->limit(30)->get()->map(fn ($p) => $this->sentPlan($p))
            : collect();

        return $this->ok([
            'unread' => $plans->where('is_read', false)->count(),
            'plans' => $plans->values()->all(),
        ]);
    }

    public function markSentPlanRead(Request $request, int $plan): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can mark study plans as read.');
        }

        $student = Student::query()->where('user_id', $request->user()->id)->first();
        $record = $student
            ? MonitoringSentPlan::query()->whereKey($plan)->where('student_id', $student->id)->first()
            : null;
        if (! $record) {
            return $this->notFound('Study plan not found.');
        }

        $record->update(['read_at' => now()]);

        return $this->ok($this->sentPlan($record->fresh()));
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
        $notification = RiskNotification::query()->create([
            'student_id' => $student,
            'sender_user_id' => $request->user()->id,
            'risk_level' => $assessment['risk_level'],
            'title' => 'Academic risk notice',
            'message' => trim($data['message'] ?? '') ?: $assessment['headline'],
        ]);

        return response()->json(['success' => true, 'data' => $this->notification($notification)], 201);
    }

    public function myRiskNotifications(Request $request): JsonResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->first();
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can view personal risk notifications.');
        }
        $items = $student
            ? RiskNotification::query()->where('student_id', $student->id)->latest()->limit(30)->get()->map(fn ($n) => $this->notification($n))->all()
            : [];

        return $this->ok(['unread' => collect($items)->where('is_read', false)->count(), 'notifications' => $items]);
    }

    public function markRiskNotificationRead(Request $request, int $notification): JsonResponse
    {
        $student = Student::query()->where('user_id', $request->user()->id)->first();
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Only students can mark notifications as read.');
        }
        $record = $student
            ? RiskNotification::query()->whereKey($notification)->where('student_id', $student->id)->first()
            : null;
        if (! $record) {
            return $this->notFound('Notification not found.');
        }
        $record->update(['read_at' => now()]);

        return $this->ok($this->notification($record->fresh()));
    }

    public function studentStudyStudio(Request $request): JsonResponse
    {
        if ($response = $this->requireStudent($request)) {
            return $response;
        }

        $studentId = (int) Student::query()->where('user_id', $request->user()->id)->value('id');
        $context = $this->ai->studentStudyContext($studentId);
        $weekPlan = ($context['assessment'] ?? null)
            ? $this->warnings->generateStudyPlan($context['assessment'])
            : null;

        return $this->ok([
            'topics' => $context['topics'],
            'records' => $context['records'],
            'risk' => $context['assessment'],
            'week_plan' => $weekPlan,
            'live_ai_configured' => $this->ai->isLiveAiConfigured(),
        ]);
    }

    public function generateStudentFlashcards(Request $request): JsonResponse
    {
        if ($response = $this->requireStudent($request)) {
            return $response;
        }

        $validated = $request->validate([
            'topic' => ['nullable', 'string', 'max:180'],
        ]);

        $studentId = (int) Student::query()->where('user_id', $request->user()->id)->value('id');

        return $this->ok($this->ai->generateFlashcards($studentId, $validated['topic'] ?? null));
    }

    public function generateStudentQuiz(Request $request): JsonResponse
    {
        if ($response = $this->requireStudent($request)) {
            return $response;
        }

        $validated = $request->validate([
            'topic' => ['nullable', 'string', 'max:180'],
        ]);

        $studentId = (int) Student::query()->where('user_id', $request->user()->id)->value('id');

        return $this->ok($this->ai->generateSampleQuiz($studentId, $validated['topic'] ?? null));
    }

    public function generateStudentStudioPlan(Request $request): JsonResponse
    {
        if ($response = $this->requireStudent($request)) {
            return $response;
        }

        $validated = $request->validate([
            'topic' => ['nullable', 'string', 'max:180'],
        ]);

        $studentId = (int) Student::query()->where('user_id', $request->user()->id)->value('id');

        return $this->ok($this->ai->generateStudentStudioPlan($studentId, $validated['topic'] ?? null));
    }

    private function requireStudent(Request $request): ?JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::STUDENT) {
            return $this->forbidden('Study studio tools are available on student accounts only.');
        }

        if (! Student::query()->where('user_id', $request->user()->id)->exists()) {
            return $this->forbidden('No student profile is linked to this account.');
        }

        return null;
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

    private function performanceRecord(MonitoringPerformanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'student_id' => $record->student_id,
            'subject_code' => $record->subject_code,
            'subject_name' => $record->subject_name,
            'assessment_name' => $record->assessment_name,
            'topic' => $record->topic,
            'score' => $record->score,
            'max_score' => $record->max_score,
            'notes' => $record->notes,
            'attachment_name' => $record->attachment_name,
            'has_attachment' => filled($record->attachment_path),
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    private function sentPlan(MonitoringSentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'student_id' => $plan->student_id,
            'title' => $plan->title,
            'topic' => $plan->topic,
            'subject_code' => $plan->subject_code,
            'plan_body' => $plan->plan_body,
            'source' => $plan->source,
            'is_read' => (bool) $plan->read_at,
            'read_at' => $plan->read_at?->toIso8601String(),
            'created_at' => $plan->created_at?->toIso8601String(),
        ];
    }

    private function notification(RiskNotification $n): array
    {
        return [
            'id' => $n->id,
            'risk_level' => $n->risk_level,
            'title' => $n->title,
            'message' => $n->message,
            'is_read' => (bool) $n->read_at,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
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
