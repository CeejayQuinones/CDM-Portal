<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\User;

class EventAuthorizationService
{
    /** @var array<int, bool> */
    private array $semiCoordinatorUsers = [];

    public function isPortalCoordinator(User $user): bool
    {
        $user->loadMissing('role');

        return $user->status === 'active'
            && $user->role?->role_name === Role::ADMIN;
    }

    public function hasSemiCoordinatorAssignment(User $user): bool
    {
        if (array_key_exists($user->id, $this->semiCoordinatorUsers)) {
            return $this->semiCoordinatorUsers[$user->id];
        }

        return $this->semiCoordinatorUsers[$user->id] = $user->status === 'active' && EventRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('responsibility', EventRoleAssignment::SEMI_COORDINATOR)
            ->exists();
    }

    public function isCoordinator(User $user, ?Event $event = null): bool
    {
        return $this->isPortalCoordinator($user);
    }

    public function isSemiCoordinator(Event $event, User $user): bool
    {
        return $this->effectiveAssignment($event, $user)?->responsibility === EventRoleAssignment::SEMI_COORDINATOR;
    }

    public function effectiveAssignment(Event $event, User $user): ?EventRoleAssignment
    {
        if ($user->status !== 'active') {
            return null;
        }

        if ($event->relationLoaded('roleAssignments')
            && ($event->parent === null || ($event->relationLoaded('parent') && $event->parent->relationLoaded('roleAssignments')))) {
            $direct = $event->roleAssignments->first(fn (EventRoleAssignment $assignment) => $assignment->user_id === $user->id && $assignment->isGranting());
            if ($direct) {
                return $direct;
            }

            return $event->parent?->roleAssignments
                ->first(fn (EventRoleAssignment $assignment) => $assignment->user_id === $user->id && $assignment->isGranting());
        }

        $eventIds = array_values(array_filter([$event->id, $event->parent_event_id]));

        return EventRoleAssignment::query()
            ->granting()
            ->where('user_id', $user->id)
            ->whereIn('event_id', $eventIds)
            ->orderByRaw('case when event_id = ? then 0 else 1 end', [$event->id])
            ->first();
    }

    public function canOperateAttendance(Event $event, User $user): bool
    {
        if ($this->isCoordinator($user)) {
            return true;
        }

        if ($event->status !== 'published' || ! now()->lt($event->ends_at)) {
            return false;
        }

        return $this->effectiveAssignment($event, $user) !== null;
    }

    public function canUseRegisteredModeratorScanner(Event $event, User $user): bool
    {
        return in_array($this->effectiveAssignment($event, $user)?->responsibility, [
            EventRoleAssignment::REGISTERED_MODERATOR,
            EventRoleAssignment::MODERATOR,
        ], true);
    }

    public function canUseRequestedModeratorScanner(Event $event, User $user): bool
    {
        return in_array($this->effectiveAssignment($event, $user)?->responsibility, [
            EventRoleAssignment::REQUESTED_MODERATOR,
            EventRoleAssignment::CLASS_MAYOR,
        ], true);
    }

    public function canEditEvent(Event $event, User $user): bool
    {
        return $this->isCoordinator($user, $event) || $this->isSemiCoordinator($event, $user);
    }

    public function canManageSubEvents(Event $event, User $user): bool
    {
        return $this->canEditEvent($event, $user);
    }

    public function canViewPersonnel(Event $event, User $user): bool
    {
        return $this->canEditEvent($event, $user);
    }

    public function canManageAttendanceSession(Event $event, User $user): bool
    {
        return $this->canEditEvent($event, $user);
    }

    public function canViewReports(Event $event, User $user): bool
    {
        return $this->canEditEvent($event, $user);
    }

    /** @return array<string, mixed> */
    public function capabilities(Event $event, User $user): array
    {
        $coordinator = $this->isCoordinator($user, $event);
        $assignment = $coordinator ? null : $this->effectiveAssignment($event, $user);
        $semiCoordinator = $assignment?->responsibility === EventRoleAssignment::SEMI_COORDINATOR;
        $operational = $coordinator || ($assignment !== null && $event->status === 'published' && now()->lt($event->ends_at));

        return [
            'portal_role' => $user->role?->role_name,
            'event_responsibility' => $coordinator ? 'coordinator' : $assignment?->responsibility,
            'event_responsibility_label' => $coordinator ? 'Coordinator / Administrator' : $assignment?->responsibilityLabel(),
            'assignment_source_event_id' => $assignment?->event_id,
            'assignment_inherited' => $assignment !== null && $assignment->event_id !== $event->id,
            'can_create_event' => $coordinator,
            'can_manage_event' => $coordinator || $semiCoordinator,
            'can_edit_event' => $coordinator || $semiCoordinator,
            'can_manage_sub_events' => $coordinator || $semiCoordinator,
            'can_manage_personnel' => $coordinator,
            'can_view_personnel' => $coordinator || $semiCoordinator,
            'can_manage_attendance_session' => $coordinator || $semiCoordinator,
            'can_operate_attendance' => $operational,
            'can_verify_attendance' => $operational,
            'can_scan_static_participant_qr' => $this->canUseRegisteredModeratorScanner($event, $user),
            'can_scan_dynamic_participant_qr' => $this->canUseRequestedModeratorScanner($event, $user),
            'can_correct_attendance' => $coordinator,
            'can_view_reports' => $coordinator || $semiCoordinator,
            'can_export_reports' => $coordinator,
            'can_view_audit' => $coordinator,
        ];
    }
}
