<?php

namespace App\Services;

use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MonitoringInterventionService
{
    private const DUPLICATE_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly EarlyWarningService $warnings,
        private readonly MonitoringAuditWriter $audit,
    ) {}

    /** @return array{notification: RiskNotification, duplicate: bool} */
    public function send(User $actor, Student $student, array $input): array
    {
        $actor->loadMissing('role');
        $role = $actor->role?->role_name;
        abort_unless(in_array($role, [Role::PROFESSOR, Role::REGISTRAR_STAFF, Role::ADMIN], true), 403, 'Only Monitoring staff can send academic support notices.');
        if ($role === Role::PROFESSOR) {
            abort_unless($this->warnings->professorCanAccessStudent($actor->id, $student->id), 403, 'You can only notify students in your assigned subjects.');
        }

        $assessment = $this->warnings->assessByStudentId(
            $student->id,
            $role === Role::PROFESSOR ? $actor->id : null,
        );
        abort_unless($assessment, 404, 'Student monitoring record not found.');

        $title = trim((string) ($input['title'] ?? '')) ?: 'Academic support notice';
        $message = $this->normalizedMessage($input['message'] ?? null, $assessment['risk_level']);
        $context = $this->safeContext($assessment);

        return DB::transaction(function () use ($actor, $student, $assessment, $title, $message, $context): array {
            $existing = RiskNotification::query()
                ->where('sender_user_id', $actor->id)
                ->where('student_id', $student->id)
                ->where('risk_level', $assessment['risk_level'])
                ->where('title', $title)
                ->where('message', $message)
                ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing) {
                return ['notification' => $existing->load('sender.profile'), 'duplicate' => true];
            }

            $notification = RiskNotification::query()->create([
                'student_id' => $student->id,
                'sender_user_id' => $actor->id,
                'risk_level' => $assessment['risk_level'],
                'title' => $title,
                'message' => $message,
                'context_json' => $context,
            ]);
            $this->audit->write(
                $actor,
                $student,
                'monitoring.risk_notice.sent',
                $notification,
                $assessment['risk_level'],
                $context,
            );

            return ['notification' => $notification->load('sender.profile'), 'duplicate' => false];
        });
    }

    public function markRead(User $actor, RiskNotification $notification): RiskNotification
    {
        $actor->loadMissing(['role', 'student']);
        abort_unless($actor->role?->role_name === Role::STUDENT && $actor->student?->id === $notification->student_id, 404, 'Academic support notice not found.');

        return DB::transaction(function () use ($actor, $notification): RiskNotification {
            $locked = RiskNotification::query()->lockForUpdate()->findOrFail($notification->id);
            if ($locked->read_at === null) {
                $locked->forceFill(['read_at' => now()])->save();
                $this->audit->write(
                    $actor,
                    $actor->student,
                    'monitoring.risk_notice.read',
                    $locked,
                    $locked->risk_level,
                    ['notice_created_at' => $locked->created_at?->toIso8601String()],
                );
            }

            return $locked->fresh('sender.profile');
        });
    }

    /** @param array<string, mixed> $assessment @return array<string, mixed> */
    private function safeContext(array $assessment): array
    {
        return [
            'risk_level' => $assessment['risk_level'],
            'risk_reasons' => array_slice($assessment['reasons'] ?? [], 0, 8),
            'subject_codes' => collect($assessment['subjects'] ?? [])->pluck('subject_code')->filter()->unique()->take(12)->values()->all(),
            'trend' => $assessment['trend'],
            'monitoring_evaluated_at' => $assessment['evaluated_at'] ?? now()->toIso8601String(),
        ];
    }

    private function normalizedMessage(mixed $custom, string $riskLevel): string
    {
        $message = preg_replace('/\s+/', ' ', trim((string) $custom));
        if ($message !== '') {
            return $message;
        }

        return match ($riskLevel) {
            'high' => 'Academic monitoring detected a high-risk trend in your published results. Please review your grades and consider the suggested academic support actions.',
            'moderate' => 'Academic monitoring detected a concerning trend in your published results. Please review your grades and consider the suggested academic support actions.',
            'stable' => 'Academic monitoring currently shows stable published results. Continue your study routine and review new official results when available.',
            default => 'More published grade information is needed for a detailed academic monitoring assessment. Please continue checking your official results.',
        };
    }
}
