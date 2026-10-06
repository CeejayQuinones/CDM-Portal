<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventPersonnelService
{
    public function __construct(private readonly EventAuditWriter $audit) {}

    /** @return array{assignments:list<array<string,mixed>>,candidates:list<array<string,mixed>>,responsibilities:list<array<string,mixed>>} */
    public function index(Event $event, ?string $search = null): array
    {
        $assignments = EventRoleAssignment::query()
            ->where('event_id', $event->id)
            ->with(['user.role', 'user.profile', 'assignedBy.profile', 'revokedBy.profile'])
            ->orderByRaw('revoked_at is not null')
            ->latest('assigned_at')
            ->get()
            ->map(fn (EventRoleAssignment $assignment) => $this->serialize($assignment))
            ->all();

        $needle = trim((string) $search);
        $candidates = $needle === '' ? collect() : User::query()
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $role) => $role->whereIn('role_name', [Role::ADMIN, Role::REGISTRAR_STAFF, Role::PROFESSOR, Role::STUDENT]))
            ->where(function (Builder $query) use ($needle): void {
                $query->where('username', 'like', "%{$needle}%")
                    ->orWhereHas('profile', fn (Builder $profile) => $profile
                        ->where('first_name', 'like', "%{$needle}%")
                        ->orWhere('last_name', 'like', "%{$needle}%"));
            })
            ->with(['role', 'profile'])
            ->orderBy('username')
            ->limit(20)
            ->get()
            ->map(fn (User $user) => $this->candidate($user))
            ->all();

        return [
            'assignments' => $assignments,
            'candidates' => $candidates,
            'responsibilities' => [
                ['value' => EventRoleAssignment::SEMI_COORDINATOR, 'label' => 'Semi-Coordinator', 'eligible_roles' => [Role::PROFESSOR]],
                ['value' => EventRoleAssignment::REGISTERED_MODERATOR, 'label' => 'Registered Moderator', 'eligible_roles' => [Role::PROFESSOR]],
                ['value' => EventRoleAssignment::EVENT_STAFF, 'label' => 'Event Staff / Manual Verification', 'eligible_roles' => [Role::PROFESSOR, Role::STUDENT]],
                ['value' => EventRoleAssignment::REQUESTED_MODERATOR, 'label' => 'Requested Moderator / Class Mayor', 'eligible_roles' => [Role::STUDENT]],
            ],
        ];
    }

    public function assign(Event $event, User $target, string $responsibility, User $actor, ?string $startsAt = null, ?string $endsAt = null): EventRoleAssignment
    {
        $responsibility = match ($responsibility) {
            EventRoleAssignment::MODERATOR => EventRoleAssignment::REGISTERED_MODERATOR,
            EventRoleAssignment::CLASS_MAYOR => EventRoleAssignment::REQUESTED_MODERATOR,
            default => $responsibility,
        };
        $this->assertEligibility($target, $responsibility);

        return DB::transaction(function () use ($event, $target, $responsibility, $actor, $startsAt, $endsAt): EventRoleAssignment {
            Event::query()->lockForUpdate()->findOrFail($event->id);
            $assignment = EventRoleAssignment::query()
                ->where('event_id', $event->id)
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->first();
            $oldResponsibility = $assignment?->isGranting() ? $assignment->responsibility : null;
            $same = $assignment?->isGranting()
                && $assignment->responsibility === $responsibility
                && $assignment->starts_at?->toIso8601String() === ($startsAt ? Carbon::parse($startsAt)->toIso8601String() : null)
                && $assignment->ends_at?->toIso8601String() === ($endsAt ? Carbon::parse($endsAt)->toIso8601String() : null);
            if ($same) {
                return $assignment->load(['user.role', 'user.profile', 'assignedBy.profile']);
            }

            $values = [
                'responsibility' => $responsibility,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
            ];
            if ($assignment) {
                $assignment->forceFill($values)->save();
            } else {
                $assignment = EventRoleAssignment::query()->create($values + ['event_id' => $event->id, 'user_id' => $target->id]);
            }

            $action = $oldResponsibility === null ? 'event.personnel.assigned' : 'event.personnel.role_changed';
            $this->audit->write($event, $actor, $action, [
                'target_user_id' => $target->id,
                'old_responsibility' => $oldResponsibility,
                'new_responsibility' => $responsibility,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            return $assignment->fresh(['user.role', 'user.profile', 'assignedBy.profile']);
        }, 3);
    }

    public function revoke(Event $event, EventRoleAssignment $assignment, User $actor): EventRoleAssignment
    {
        abort_unless($assignment->event_id === $event->id, 404, 'Event personnel assignment not found.');

        return DB::transaction(function () use ($event, $assignment, $actor): EventRoleAssignment {
            $locked = EventRoleAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->revoked_at !== null) {
                return $locked->load(['user.role', 'user.profile', 'assignedBy.profile', 'revokedBy.profile']);
            }

            $locked->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();
            $this->audit->write($event, $actor, 'event.personnel.revoked', [
                'target_user_id' => $locked->user_id,
                'old_responsibility' => $locked->responsibility,
                'new_responsibility' => null,
            ]);

            return $locked->fresh(['user.role', 'user.profile', 'assignedBy.profile', 'revokedBy.profile']);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function serialize(EventRoleAssignment $assignment): array
    {
        $assignment->loadMissing(['user.role', 'user.profile', 'assignedBy.profile', 'revokedBy.profile']);

        return [
            'id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'name' => $this->name($assignment->user),
            'portal_role' => $assignment->user?->role?->role_name,
            'responsibility' => $assignment->responsibility,
            'responsibility_label' => $assignment->responsibilityLabel(),
            'assigned_by' => $this->name($assignment->assignedBy),
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            'starts_at' => $assignment->starts_at?->toIso8601String(),
            'ends_at' => $assignment->ends_at?->toIso8601String(),
            'revoked_at' => $assignment->revoked_at?->toIso8601String(),
            'status' => $assignment->isGranting() ? 'active' : ($assignment->revoked_at ? 'revoked' : 'inactive'),
        ];
    }

    private function assertEligibility(User $target, string $responsibility): void
    {
        $target->loadMissing('role');
        $role = $target->role?->role_name;
        $eligible = match ($responsibility) {
            EventRoleAssignment::SEMI_COORDINATOR, EventRoleAssignment::REGISTERED_MODERATOR => $role === Role::PROFESSOR,
            EventRoleAssignment::EVENT_STAFF => in_array($role, [Role::PROFESSOR, Role::STUDENT], true),
            EventRoleAssignment::REQUESTED_MODERATOR => $role === Role::STUDENT,
            default => false,
        };

        if ($target->status !== 'active' || ! $eligible) {
            throw ValidationException::withMessages(['user_id' => 'The selected account is not eligible for this Event responsibility.']);
        }
    }

    /** @return array<string, mixed> */
    private function candidate(User $user): array
    {
        return ['id' => $user->id, 'name' => $this->name($user), 'portal_role' => $user->role?->role_name];
    }

    private function name(?User $user): string
    {
        if (! $user) {
            return 'Unknown user';
        }

        $name = trim(implode(' ', array_filter([$user->profile?->first_name, $user->profile?->middle_name, $user->profile?->last_name])));

        return $name !== '' ? $name : 'Portal user #'.$user->id;
    }
}
