<?php

namespace App\Services;

use App\Models\AccountAuditEvent;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionAuditEvent;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Enrollment\EnrollmentWorkflowEvent;
use App\Models\Event;
use App\Models\EventAuditEvent;
use App\Models\EventModeratorRequest;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

class AdminDashboardService
{
    private const RECENT_LIMIT = 10;

    public function get(): array
    {
        $period = $this->currentPeriod();
        $admissionCounts = $this->groupedCounts(AdmissionApplicant::query(), 'status');
        $enrollmentCounts = $period
            ? $this->groupedCounts(EnrollmentApplication::query()->where('period_id', $period->id), 'status')
            : [];

        $pendingAdmission = $this->sumStatuses($admissionCounts, [
            AdmissionApplicant::SUBMITTED,
            AdmissionApplicant::UNDER_REVIEW,
        ]);
        $pendingEnrollment = $this->sumStatuses($enrollmentCounts, ['submitted', 'under_review']);
        $pendingModeratorRequests = EventModeratorRequest::query()->where('status', 'pending')->count();
        $currentEnrollments = $period
            ? Enrollment::query()
                ->where('academic_year_id', $period->academic_year_id)
                ->where('semester_id', $period->semester_id)
                ->whereIn('status', ['pending', 'enrolled'])
                ->count()
            : 0;
        $activeEvents = Event::query()
            ->where('status', 'published')
            ->where('ends_at', '>', now())
            ->count();

        $pendingReviews = [
            ['type' => 'admission', 'label' => 'Admission applications', 'count' => $pendingAdmission, 'route_name' => 'registrar-admissions'],
            ['type' => 'enrollment', 'label' => 'Enrollment applications', 'count' => $pendingEnrollment, 'route_name' => 'enrollment-applications'],
            ['type' => 'moderator_requests', 'label' => 'Event Moderator requests', 'count' => $pendingModeratorRequests, 'route_name' => 'event-attendance'],
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'timezone' => 'Asia/Manila',
            'summary' => [
                'active_students' => Student::query()->whereHas('user', fn ($query) => $query->where('status', 'active'))->count(),
                'active_professors' => Professor::query()
                    ->where('status', 'active')
                    ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                    ->count(),
                'registrar_staff_accounts' => $this->usersWithRole(Role::REGISTRAR_STAFF)->count(),
                'guest_accounts' => $this->usersWithRole(Role::GUEST)->count(),
                'pending_admission_applications' => $pendingAdmission,
                'current_term_enrollments' => $currentEnrollments,
                'pending_administrative_actions' => $pendingAdmission + $pendingEnrollment + $pendingModeratorRequests,
                'published_or_ongoing_events' => $activeEvents,
            ],
            'admissions' => [
                'by_status' => $admissionCounts,
                'total' => array_sum($admissionCounts),
            ],
            'enrollment' => [
                'current_term' => $this->termSummary($period),
                'by_status' => $enrollmentCounts,
                'activity' => [
                    'applications' => array_sum($enrollmentCounts),
                    'awaiting_review' => $pendingEnrollment,
                    'approved' => $enrollmentCounts['approved'] ?? 0,
                    'finalized' => $enrollmentCounts['enrolled'] ?? 0,
                    'academic_enrollments' => $currentEnrollments,
                ],
            ],
            'events' => [
                'pending_moderator_requests' => $pendingModeratorRequests,
                'items' => $this->eventOperations(),
            ],
            'administrative' => [
                'pending_reviews' => $pendingReviews,
                'recent_activity' => $this->recentActivity(),
            ],
        ];
    }

    private function currentPeriod(): ?EnrollmentPeriod
    {
        $periods = EnrollmentPeriod::query()
            ->with(['academicYear:id,school_year', 'semester:id,semester_name'])
            ->where('enabled', true)
            ->where('closes_at', '>', now())
            ->whereHas('academicYear', fn ($query) => $query->where('status', 'active'))
            ->whereHas('semester', fn ($query) => $query->where('status', 'active'))
            ->orderBy('opens_at')
            ->get();
        $open = $periods->filter(fn (EnrollmentPeriod $period) => $period->state === 'open');

        if ($open->count() > 1) {
            return null;
        }

        return $open->first() ?? $periods->first();
    }

    private function usersWithRole(string $role)
    {
        return User::query()->whereHas('role', fn ($query) => $query->where('role_name', $role));
    }

    private function groupedCounts($query, string $column): array
    {
        return $query->selectRaw("{$column}, COUNT(*) AS aggregate")
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function sumStatuses(array $counts, array $statuses): int
    {
        return array_sum(array_map(fn (string $status): int => $counts[$status] ?? 0, $statuses));
    }

    private function termSummary(?EnrollmentPeriod $period): ?array
    {
        if (! $period) {
            return null;
        }

        return [
            'period_id' => $period->id,
            'academic_year_id' => $period->academic_year_id,
            'academic_year' => $period->academicYear?->school_year,
            'semester_id' => $period->semester_id,
            'semester' => $period->semester?->semester_name,
            'state' => $period->state,
            'opens_at' => $period->opens_at?->toIso8601String(),
            'closes_at' => $period->closes_at?->toIso8601String(),
        ];
    }

    private function eventOperations(): array
    {
        return Event::query()
            ->whereNull('parent_event_id')
            ->where('status', 'published')
            ->where('ends_at', '>', now())
            ->with('attendanceSession:id,event_id,status,opened_at,closed_at')
            ->orderBy('starts_at')
            ->limit(6)
            ->get(['id', 'title', 'venue', 'starts_at', 'ends_at', 'status'])
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'venue' => $event->venue,
                'status' => $event->presentationStatus(),
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'attendance_session' => $event->attendanceSession ? [
                    'status' => $event->attendanceSession->status,
                    'opened_at' => $event->attendanceSession->opened_at?->toIso8601String(),
                    'closed_at' => $event->attendanceSession->closed_at?->toIso8601String(),
                ] : null,
            ])
            ->all();
    }

    private function recentActivity(): array
    {
        $activities = collect()
            ->concat($this->auditActivity(AccountAuditEvent::query(), 'accounts'))
            ->concat($this->auditActivity(AdmissionAuditEvent::query(), 'admission'))
            ->concat($this->auditActivity(EnrollmentWorkflowEvent::query(), 'enrollment'))
            ->concat($this->auditActivity(EventAuditEvent::query(), 'events'));

        return $activities
            ->sortByDesc('occurred_at')
            ->take(self::RECENT_LIMIT)
            ->values()
            ->all();
    }

    private function auditActivity($query, string $module): Collection
    {
        return $query->latest('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'action', 'created_at'])
            ->map(fn ($event): array => [
                'id' => $module.'-'.$event->id,
                'module' => $module,
                'action' => str_replace(['.', '_'], ' ', (string) $event->action),
                'occurred_at' => $event->created_at?->toIso8601String(),
            ]);
    }
}
