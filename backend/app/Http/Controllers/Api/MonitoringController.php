<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Student;
use App\Services\EarlyWarningService;
use App\Services\MonitoringAiHelpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function __construct(
        private readonly EarlyWarningService $earlyWarningService,
        private readonly MonitoringAiHelpService $monitoringAiHelpService,
    ) {
    }

    public function earlyWarnings(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name === Role::STUDENT) {
            return response()->json([
                'success' => true,
                'message' => 'Personal early-warning assessment loaded.',
                'data' => $this->earlyWarningService->assessForUserId($user->id),
            ]);
        }

        $professorUserId = $user->role?->role_name === Role::PROFESSOR ? $user->id : null;

        return response()->json([
            'success' => true,
            'message' => 'Early-warning overview loaded.',
            'data' => $this->earlyWarningService->overview($professorUserId),
        ]);
    }

    public function myRisk(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name !== Role::STUDENT) {
            return response()->json([
                'success' => false,
                'message' => 'Only student accounts can load personal risk dashboards.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Personal early-warning assessment loaded.',
            'data' => $this->earlyWarningService->assessForUserId($user->id),
        ]);
    }

    public function supportPlan(Request $request, int $student): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name === Role::STUDENT) {
            $owned = Student::query()->where('user_id', $user->id)->where('id', $student)->exists();
            if (! $owned) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only generate a support plan for your own record.',
                ], 403);
            }
        }

        $assessment = $this->earlyWarningService->assessByStudentId($student);

        if (! $assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Student grade record not found for monitoring.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Help support plan generated.',
            'data' => $this->earlyWarningService->generateSupportPlan($assessment),
        ]);
    }

    public function studyPlans(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name === Role::STUDENT) {
            $personal = $this->earlyWarningService->assessForUserId($user->id);
            $student = $personal['students'][0] ?? null;

            return response()->json([
                'success' => true,
                'message' => 'Guided study plan loaded.',
                'data' => [
                    'summary' => [
                        'total' => $student ? 1 : 0,
                        'with_focus_subjects' => $student && count($student['subjects'] ?? []) ? 1 : 0,
                    ],
                    'plans' => $student ? [$this->earlyWarningService->generateStudyPlan($student)] : [],
                ],
            ]);
        }

        $professorUserId = $user->role?->role_name === Role::PROFESSOR ? $user->id : null;

        return response()->json([
            'success' => true,
            'message' => 'Guided study plans loaded.',
            'data' => $this->earlyWarningService->studyPlansOverview($professorUserId),
        ]);
    }

    public function studyPlan(Request $request, int $student): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name === Role::STUDENT) {
            $owned = Student::query()->where('user_id', $user->id)->where('id', $student)->exists();
            if (! $owned) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only view your own study plan.',
                ], 403);
            }
        }

        $assessment = $this->earlyWarningService->assessByStudentId($student);

        if (! $assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Student grade record not found for study planning.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Guided study plan generated.',
            'data' => $this->earlyWarningService->generateStudyPlan($assessment),
        ]);
    }

    public function adviserAlerts(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name === Role::STUDENT) {
            return response()->json([
                'success' => false,
                'message' => 'Adviser Alert Inbox is for professors, registrar staff, and admins.',
            ], 403);
        }

        $professorUserId = $user->role?->role_name === Role::PROFESSOR ? $user->id : null;

        return response()->json([
            'success' => true,
            'message' => 'Adviser alerts loaded.',
            'data' => $this->earlyWarningService->adviserAlerts($professorUserId),
        ]);
    }

    public function aiHelp(Request $request, int $student): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name === Role::STUDENT) {
            $owned = Student::query()->where('user_id', $user->id)->where('id', $student)->exists();
            if (! $owned) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only ask AI Help about your own record.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'question' => ['nullable', 'string', 'max:1000'],
            'messages' => ['nullable', 'array', 'max:20'],
            'messages.*.role' => ['required_with:messages', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required_with:messages', 'string', 'max:2000'],
        ]);

        $help = $this->monitoringAiHelpService->generateHelp(
            $student,
            $validated['question'] ?? null,
            $validated['messages'] ?? [],
        );

        return response()->json([
            'success' => true,
            'message' => $help['source'] === 'live-ai'
                ? 'Live AI help generated.'
                : 'AI Help coach response generated.',
            'data' => [
                ...$help,
                'live_ai_configured' => $this->monitoringAiHelpService->isLiveAiConfigured(),
            ],
        ]);
    }

    public function aiStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'AI Help status loaded.',
            'data' => [
                'live_ai_configured' => $this->monitoringAiHelpService->isLiveAiConfigured(),
                'provider' => config('ai.provider'),
                'model' => config('ai.model'),
            ],
        ]);
    }
}
