<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RiskNotification;
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

    public function sendRiskNotification(Request $request, int $student): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['role', 'profile']);

        if ($user->role?->role_name === Role::STUDENT) {
            return response()->json([
                'success' => false,
                'message' => 'Only professors, registrar staff, and admins can notify students.',
            ], 403);
        }

        $assessment = $this->earlyWarningService->assessByStudentId($student);
        if (! $assessment) {
            return response()->json([
                'success' => false,
                'message' => 'Student grade record not found for monitoring.',
            ], 404);
        }

        if ($user->role?->role_name === Role::PROFESSOR) {
            $scoped = collect($this->earlyWarningService->overview($user->id)['students'] ?? [])
                ->contains(fn (array $item) => (int) $item['student_id'] === $student);

            if (! $scoped) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only notify students in your assigned subjects.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $riskLevel = (string) ($assessment['risk_level'] ?? 'moderate');
        $riskLabel = (string) ($assessment['risk_label'] ?? $riskLevel);
        $defaultMessage = sprintf(
            'You are currently marked as %s risk of failing (average %s, trend %s). Please review your grades and take recovery steps as soon as possible.',
            $riskLabel,
            $assessment['average_grade'] ?? '—',
            $assessment['trend_label'] ?? 'unknown',
        );

        $senderName = trim(implode(' ', array_filter([
            $user->profile?->first_name,
            $user->profile?->last_name,
        ]))) ?: $user->username;

        $notification = RiskNotification::query()->create([
            'student_id' => $student,
            'sender_user_id' => $user->id,
            'risk_level' => $riskLevel,
            'title' => "Academic risk notice · {$riskLabel}",
            'message' => trim((string) ($validated['message'] ?? '')) ?: $defaultMessage,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Risk notification sent to the student.',
            'data' => $this->formatRiskNotification($notification, $senderName),
        ], 201);
    }

    public function myRiskNotifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name !== Role::STUDENT) {
            return response()->json([
                'success' => false,
                'message' => 'Only students can view personal risk notifications.',
            ], 403);
        }

        $student = Student::query()->where('user_id', $user->id)->first();
        if (! $student) {
            return response()->json([
                'success' => true,
                'message' => 'No student record linked to this account.',
                'data' => ['unread' => 0, 'notifications' => []],
            ]);
        }

        $notifications = RiskNotification::query()
            ->with(['sender.profile'])
            ->where('student_id', $student->id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(function (RiskNotification $notification) {
                $sender = $notification->sender;
                $senderName = trim(implode(' ', array_filter([
                    $sender?->profile?->first_name,
                    $sender?->profile?->last_name,
                ]))) ?: ($sender?->username ?? 'Adviser');

                return $this->formatRiskNotification($notification, $senderName);
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Risk notifications loaded.',
            'data' => [
                'unread' => collect($notifications)->where('is_read', false)->count(),
                'notifications' => $notifications,
            ],
        ]);
    }

    public function markRiskNotificationRead(Request $request, int $notification): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->role?->role_name !== Role::STUDENT) {
            return response()->json([
                'success' => false,
                'message' => 'Only students can mark their notifications as read.',
            ], 403);
        }

        $student = Student::query()->where('user_id', $user->id)->first();
        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Student record not found.',
            ], 404);
        }

        $record = RiskNotification::query()
            ->with(['sender.profile'])
            ->where('id', $notification)
            ->where('student_id', $student->id)
            ->first();

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        if (! $record->read_at) {
            $record->forceFill(['read_at' => now()])->save();
        }

        $sender = $record->sender;
        $senderName = trim(implode(' ', array_filter([
            $sender?->profile?->first_name,
            $sender?->profile?->last_name,
        ]))) ?: ($sender?->username ?? 'Adviser');

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => $this->formatRiskNotification($record->fresh(['sender.profile']) ?? $record, $senderName),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRiskNotification(RiskNotification $notification, string $senderName): array
    {
        return [
            'id' => $notification->id,
            'student_id' => $notification->student_id,
            'risk_level' => $notification->risk_level,
            'title' => $notification->title,
            'message' => $notification->message,
            'sender_name' => $senderName,
            'is_read' => (bool) $notification->read_at,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
