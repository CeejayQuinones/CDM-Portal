<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\GradingPeriod;
use App\Models\MonitoringPerformanceRecord;
use App\Models\MonitoringSentPlan;
use App\Models\Professor;
use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\Student;
use App\Services\EarlyWarningService;
use App\Services\GradebookImporter;
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
            'question' => ['nullable', 'string', 'max:2000'],
            'messages' => ['nullable', 'array', 'max:24'],
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
        $allowed = [Role::PROFESSOR, Role::STUDENT];
        if (! in_array($role, $allowed, true)) {
            return $this->forbidden('Class scores are recorded by the professor. Registrar staff approve the official grade, and admin only views the record.');
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

    public function updatePerformanceRecord(Request $request, int $record): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::PROFESSOR) {
            return $this->forbidden('Only a professor can change a class score.');
        }

        $performance = MonitoringPerformanceRecord::query()->find($record);
        if (! $performance || (int) $performance->professor_user_id !== (int) $request->user()->id) {
            return $this->notFound('Class score not found.');
        }

        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:9999'],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:9999'],
        ]);

        $performance->update([
            'score' => $data['score'],
            'max_score' => $data['max_score'],
        ]);

        return $this->ok($this->performanceRecord($performance->fresh()));
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

    public function submittedGrades(Request $request): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if (! in_array($role, [Role::REGISTRAR_STAFF, Role::ADMIN], true)) {
            return $this->forbidden('Submitted grades are reviewed by the registrar. Admin may view the queue.');
        }

        $grades = Grade::query()
            ->with([
                'gradingPeriod:id,period_name',
                'enrollmentSubject.subject:id,subject_code,subject_name',
                'enrollmentSubject.enrollment.student.userProfile',
            ])
            ->where('status', 'submitted')
            ->orderBy('submitted_at')
            ->limit(100)
            ->get()
            ->map(fn (Grade $grade) => $this->submittedGrade($grade));

        return $this->ok([
            'grades' => $grades,
            'can_approve' => $role === Role::REGISTRAR_STAFF,
        ]);
    }

    public function approveGrade(Request $request, int $grade): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::REGISTRAR_STAFF) {
            return $this->forbidden('Only registrar staff can approve a grade onto the official record.');
        }

        $row = Grade::query()->with('gradingPeriod:id,period_name')->find($grade);
        if (! $row || $row->status !== 'submitted') {
            return $this->notFound('That grade is not waiting for registrar approval.');
        }

        $row->update(['status' => 'approved']);

        return $this->ok($this->submittedGrade($row->fresh([
            'gradingPeriod:id,period_name',
            'enrollmentSubject.subject:id,subject_code,subject_name',
            'enrollmentSubject.enrollment.student.userProfile',
        ])));
    }

    public function approveAllGrades(Request $request): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::REGISTRAR_STAFF) {
            return $this->forbidden('Only registrar staff can approve a grade onto the official record.');
        }

        $approved = Grade::query()->where('status', 'submitted')->update([
            'status' => 'approved',
            'updated_at' => now(),
        ]);

        return $this->ok(['approved' => $approved]);
    }

    public function periodGrades(Request $request, int $student): JsonResponse
    {
        if ($response = $this->requireProfessorSubject($request, $student)) {
            return $response;
        }

        $professorId = Professor::query()->where('user_id', $request->user()->id)->value('id');
        $rows = EnrollmentSubject::query()
            ->with(['subject:id,subject_code,subject_name', 'grades.gradingPeriod:id,period_name'])
            ->where('professor_id', $professorId)
            ->whereHas('enrollment', fn ($query) => $query->where('student_id', $student))
            ->get();

        $subjects = $rows->map(function (EnrollmentSubject $row): array {
            $byPeriod = [];
            foreach ($row->grades as $grade) {
                $name = $grade->gradingPeriod?->period_name;
                if (is_string($name)) {
                    $byPeriod[$name] = $grade;
                }
            }

            $periods = [];
            foreach (['Midterm', 'Final'] as $name) {
                $grade = $byPeriod[$name] ?? null;
                $periods[] = [
                    'period' => $name,
                    'grade' => $grade?->grade,
                    'status' => $grade?->status ?? 'open',
                ];
            }

            return [
                'subject_code' => $row->subject?->subject_code,
                'subject_name' => $row->subject?->subject_name,
                'periods' => $periods,
            ];
        })->values();

        return $this->ok(['subjects' => $subjects]);
    }

    public function submitPeriodGrade(Request $request, int $student): JsonResponse
    {
        if ($response = $this->requireProfessorSubject($request, $student)) {
            return $response;
        }

        $data = $request->validate([
            'subject_code' => ['required', 'string', 'max:40'],
            'period' => ['required', 'string', 'in:Midterm,Final'],
            'grade' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $professorId = (int) Professor::query()->where('user_id', $request->user()->id)->value('id');
        $enrollmentSubject = EnrollmentSubject::query()
            ->where('professor_id', $professorId)
            ->whereHas('enrollment', fn ($query) => $query->where('student_id', $student))
            ->whereHas('subject', fn ($query) => $query->where('subject_code', $data['subject_code']))
            ->first();
        if (! $enrollmentSubject) {
            return $this->notFound('That subject is not assigned to you for this student.');
        }

        $period = GradingPeriod::query()->where('period_name', $data['period'])->first();
        if (! $period) {
            return $this->notFound('That grading period is not set up.');
        }

        $existing = Grade::query()
            ->where('enrollment_subject_id', $enrollmentSubject->id)
            ->where('grading_period_id', $period->id)
            ->first();
        if ($existing && $existing->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'That grade is already on the official record.',
            ], 422);
        }

        $score = round((float) $data['grade'], 2);
        $payload = [
            'professor_id' => $professorId,
            'grade' => $score,
            'remarks' => $score >= 75 ? 'Passed' : 'Failed',
            'status' => 'submitted',
            'submitted_at' => now(),
        ];

        if ($existing) {
            $existing->update($payload);
        } else {
            Grade::query()->create([
                'enrollment_subject_id' => $enrollmentSubject->id,
                'grading_period_id' => $period->id,
                ...$payload,
            ]);
        }

        return $this->ok([
            'subject_code' => $data['subject_code'],
            'period' => $data['period'],
            'grade' => $score,
            'status' => 'submitted',
        ]);
    }

    public function gradebook(Request $request): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::PROFESSOR) {
            return $this->forbidden('Only a professor can open the class grade book.');
        }

        return $this->ok(['rows' => $this->gradebookRows($request)]);
    }

    public function exportGradebook(Request $request): StreamedResponse|JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::PROFESSOR) {
            return $this->forbidden('Only a professor can export class grades.');
        }

        $rows = array_map(fn (array $row) => [
            $row['student_number'] ?? '',
            $row['student_name'],
            $row['year_level'] ?? '',
            $row['section_name'] ?? '',
            $row['course_code'] ?? '',
            $row['course_name'] ?? '',
            $row['subject_code'],
            $row['subject_name'] ?? '',
            $row['class_scores_text'],
            $row['midterm'] ?? '',
            $row['midterm_status'],
            $row['final'] ?? '',
            $row['final_status'],
        ], $this->gradebookRows($request));

        return \App\Support\SimpleXlsx::download('class-grades.xlsx', [
            'Student number',
            'Student name',
            'Year level',
            'Section',
            'Course',
            'Course name',
            'Subject',
            'Subject name',
            'Class scores',
            'Midterm',
            'Midterm status',
            'Final',
            'Final status',
        ], $rows);
    }

    public function importGradebook(Request $request, GradebookImporter $importer): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::PROFESSOR) {
            return $this->forbidden('Only a professor can import a class spreadsheet.');
        }

        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'subject_code' => ['nullable', 'string', 'max:40'],
        ]);
        $file = $request->file('file');
        $extension = strtolower((string) $file?->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Import a .xlsx or .csv spreadsheet.',
            ], 422);
        }

        try {
            $result = $importer->import(
                (int) $request->user()->id,
                (string) $file->getRealPath(),
                $request->string('subject_code')->toString() ?: null,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->ok($result);
    }

    public function generateGradeStudyPlan(Request $request, int $student): JsonResponse
    {
        $role = $request->user()->role?->role_name;
        if ($role !== Role::PROFESSOR) {
            return $this->forbidden('Only a professor can draft a study plan. Registrar staff approve grades, and admin only views the record.');
        }
        if ($response = $this->authorizeStudent($request, $student)) {
            return $response;
        }

        $data = $request->validate([
            'subject_code' => ['required', 'string', 'max:40'],
        ]);
        $assessment = $this->warnings->assessByStudentId($student);
        $subject = $this->gradeSubject($assessment, $data['subject_code']);
        if (! $assessment || ! $subject) {
            return $this->notFound('Approved grade record not found for that subject.');
        }

        try {
            $generated = $this->ai->generateTopicStudyPlan($assessment, $this->gradePlanRecord($subject));
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }

        return $this->ok([
            'title' => $generated['title'],
            'plan_body' => $generated['reply'],
            'source' => $generated['source'],
            'subject' => $subject,
        ]);
    }

    public function sendGradeStudyPlan(Request $request, int $student): JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::PROFESSOR) {
            return $this->forbidden('Only professors can send study plans to students.');
        }
        if (! $this->warnings->professorCanAccessStudent($request->user()->id, $student)) {
            return $this->forbidden('You can only send plans to students in your assigned subjects.');
        }

        $data = $request->validate([
            'subject_code' => ['required', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:180'],
            'plan_body' => ['required', 'string', 'max:8000'],
        ]);
        $subject = $this->gradeSubject($this->warnings->assessByStudentId($student), $data['subject_code']);
        if (! $subject) {
            return $this->notFound('Approved grade record not found for that subject.');
        }

        $plan = MonitoringSentPlan::query()->create([
            'student_id' => $student,
            'sender_user_id' => $request->user()->id,
            'performance_record_id' => null,
            'title' => trim((string) ($data['title'] ?? '')) ?: 'Study plan: '.$subject['subject_code'],
            'topic' => $subject['subject_name'] ?? $subject['subject_code'],
            'subject_code' => $subject['subject_code'],
            'plan_body' => $data['plan_body'],
            'source' => 'gemini',
        ]);

        RiskNotification::query()->create([
            'student_id' => $student,
            'sender_user_id' => $request->user()->id,
            'risk_level' => $subject['risk_level'] ?? 'moderate',
            'title' => $plan->title,
            'message' => 'Your instructor sent a study plan for '.$subject['subject_code'].'. Open Academic Monitoring to review it.',
        ]);

        return response()->json(['success' => true, 'data' => $this->sentPlan($plan)], 201);
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
            'student_id' => $studentId,
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
            'record_id' => ['required', 'integer'],
        ]);

        $studentId = (int) Student::query()->where('user_id', $request->user()->id)->value('id');

        try {
            return $this->ok($this->ai->generateFlashcards($studentId, (int) $validated['record_id']));
        } catch (\RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function generateStudentQuiz(Request $request): JsonResponse
    {
        if ($response = $this->requireStudent($request)) {
            return $response;
        }

        $validated = $request->validate([
            'record_id' => ['required', 'integer'],
        ]);

        $studentId = (int) Student::query()->where('user_id', $request->user()->id)->value('id');

        try {
            return $this->ok($this->ai->generateSampleQuiz($studentId, (int) $validated['record_id']));
        } catch (\RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function generateStudentStudioPlan(Request $request): JsonResponse
    {
        if ($response = $this->requireStudent($request)) {
            return $response;
        }

        $validated = $request->validate([
            'record_id' => ['nullable', 'integer', 'required_without:topic'],
            'topic' => ['nullable', 'string', 'max:180', 'required_without:record_id'],
        ]);

        $studentId = (int) Student::query()->where('user_id', $request->user()->id)->value('id');

        try {
            if (filled($validated['topic'] ?? null) && empty($validated['record_id'])) {
                return $this->ok($this->ai->generateRequestedTopicPlan($studentId, (string) $validated['topic']));
            }

            return $this->ok($this->ai->generateStudentStudioPlan($studentId, (int) $validated['record_id']));
        } catch (\RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
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

    private function requireProfessorSubject(Request $request, int $student): ?JsonResponse
    {
        if ($request->user()->role?->role_name !== Role::PROFESSOR) {
            return $this->forbidden('Only a professor can submit a period grade.');
        }
        if (! $this->warnings->professorCanAccessStudent($request->user()->id, $student)) {
            return $this->forbidden('You can only submit grades for students in your assigned subjects.');
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function gradebookRows(Request $request): array
    {
        $professorId = Professor::query()->where('user_id', $request->user()->id)->value('id');
        if (! $professorId) {
            return [];
        }

        $enrolled = EnrollmentSubject::query()
            ->with([
                'subject:id,subject_code,subject_name',
                'grades.gradingPeriod:id,period_name',
                'enrollment.section:id,section_name,year_level',
                'enrollment.student.userProfile:id,first_name,last_name',
                'enrollment.student.course:id,course_code,course_name',
            ])
            ->where('professor_id', $professorId)
            ->get();

        $studentIds = $enrolled->map(fn (EnrollmentSubject $row) => $row->enrollment?->student_id)->filter()->unique()->values();
        $records = MonitoringPerformanceRecord::query()
            ->whereIn('student_id', $studentIds)
            ->where('professor_user_id', $request->user()->id)
            ->whereNotNull('score')
            ->where('max_score', '>', 0)
            ->orderBy('assessment_name')
            ->get()
            ->groupBy(fn (MonitoringPerformanceRecord $record) => $record->student_id.'|'.strtoupper((string) $record->subject_code));

        return $enrolled->map(function (EnrollmentSubject $row) use ($records): array {
            $student = $row->enrollment?->student;
            $profile = $student?->userProfile;
            $name = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])));
            $code = (string) ($row->subject?->subject_code ?? '');
            $scores = collect($records[$student?->id.'|'.strtoupper($code)] ?? [])
                ->map(fn (MonitoringPerformanceRecord $record) => [
                    'id' => $record->id,
                    'assessment_name' => $record->assessment_name,
                    'score' => $record->score,
                    'max_score' => $record->max_score,
                ])->values()->all();
            $periods = ['Midterm' => null, 'Final' => null];
            $status = ['Midterm' => 'open', 'Final' => 'open'];
            foreach ($row->grades as $grade) {
                $period = $grade->gradingPeriod?->period_name;
                if (! is_string($period) || ! array_key_exists($period, $periods)) {
                    continue;
                }
                $periods[$period] = $grade->grade;
                $status[$period] = $grade->status;
            }

            return [
                'student_id' => $student?->id,
                'student_number' => $student?->student_number,
                'student_name' => $name !== '' ? $name : 'Student',
                'year_level' => $student?->year_level ?? $row->enrollment?->section?->year_level,
                'section_name' => $row->enrollment?->section?->section_name,
                'course_code' => $student?->course?->course_code,
                'course_name' => $student?->course?->course_name,
                'subject_code' => $code,
                'subject_name' => $row->subject?->subject_name,
                'class_scores' => $scores,
                'class_scores_text' => collect($scores)->map(fn (array $score) => $score['assessment_name'].': '.$score['score'].'/'.$score['max_score'])->implode('; '),
                'midterm' => $periods['Midterm'],
                'midterm_status' => $status['Midterm'],
                'final' => $periods['Final'],
                'final_status' => $status['Final'],
            ];
        })->sortBy([
            ['course_code', 'asc'],
            ['section_name', 'asc'],
            ['student_name', 'asc'],
            ['subject_code', 'asc'],
        ])->values()->all();
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

    /** @param  array<string, mixed>|null  $assessment */
    private function gradeSubject(?array $assessment, string $subjectCode): ?array
    {
        foreach ($assessment['subjects'] ?? [] as $subject) {
            if (($subject['subject_code'] ?? '') === $subjectCode) {
                return $subject;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $subject */
    private function gradePlanRecord(array $subject): array
    {
        $periods = $subject['periods'] ?? [];
        $format = fn ($value) => $value === null ? 'not yet released' : (string) $value;

        return [
            'assessment_name' => 'Approved grades',
            'topic' => $subject['subject_name'] ?? $subject['subject_code'] ?? 'Subject',
            'subject_code' => $subject['subject_code'] ?? '',
            'score' => $subject['average_grade'] ?? null,
            'max_score' => 100,
            'notes' => 'Midterm '.$format($periods['Midterm'] ?? null)
                .', Final '.$format($periods['Final'] ?? null)
                .'. Trend '.($subject['trend'] ?? 'steady').'.',
        ];
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

    private function submittedGrade(Grade $grade): array
    {
        $student = $grade->enrollmentSubject?->enrollment?->student;
        $profile = $student?->userProfile;
        $name = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])));

        return [
            'id' => $grade->id,
            'student_name' => $name !== '' ? $name : 'Student',
            'student_number' => $student?->student_number,
            'subject_code' => $grade->enrollmentSubject?->subject?->subject_code,
            'subject_name' => $grade->enrollmentSubject?->subject?->subject_name,
            'period' => $grade->gradingPeriod?->period_name,
            'grade' => $grade->grade,
            'remarks' => $grade->remarks,
            'status' => $grade->status,
            'submitted_at' => $grade->submitted_at?->toIso8601String(),
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
