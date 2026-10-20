<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\EventModeratorRequest;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EventNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventModeratorRequestService
{
    public function __construct(
        private readonly EventAudienceResolver $audiences,
        private readonly EventAuthorizationService $authorization,
        private readonly EventPersonnelService $personnel,
        private readonly EventAuditWriter $audit,
    ) {}

    public function request(Event $event, User $requester, ?string $reason = null): EventModeratorRequest
    {
        $requester->loadMissing(['role', 'student']);
        abort_unless(in_array($requester->role?->role_name, [Role::STUDENT, Role::PROFESSOR], true), 403, 'This account cannot request Event moderator access.');
        abort_unless($this->audiences->canView($event, $requester), 403, 'You are not included in this Event audience.');
        abort_unless($event->status === 'published' && now()->lt($event->ends_at), 409, 'Moderator access can only be requested for an active or upcoming published Event.');

        return DB::transaction(function () use ($event, $requester, $reason): EventModeratorRequest {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $existing = EventModeratorRequest::query()
                ->where('active_request_key', EventModeratorRequest::activeKey($event->id, $requester->id))
                ->lockForUpdate()->first();
            if ($existing) {
                throw ValidationException::withMessages(['event_id' => 'A moderator request is already pending for this Event.']);
            }
            abort_unless($this->authorization->canRequestModerator($lockedEvent, $requester), 403, 'Your current Event responsibility cannot request moderator access.');

            $request = EventModeratorRequest::query()->create([
                'event_id' => $event->id,
                'requester_user_id' => $requester->id,
                'status' => 'pending',
                'active_request_key' => EventModeratorRequest::activeKey($event->id, $requester->id),
                'request_reason' => $reason,
                'requested_at' => now(),
            ]);
            $this->audit->write($event, $requester, 'event.moderator.requested', ['request_id' => $request->id, 'reason_provided' => $reason !== null]);
            User::query()->where('status', 'active')->whereHas('role', fn (Builder $query) => $query->where('role_name', Role::ADMIN))
                ->each(fn (User $coordinator) => $coordinator->notify(new EventNotice('moderator_requested', 'A participant requested moderator access for '.$event->title.'.', $request->id, $event->id)));

            return $request->fresh($this->relations());
        }, 3);
    }

    public function review(Event $event, EventModeratorRequest $request, string $action, User $actor, ?string $reason = null): EventModeratorRequest
    {
        abort_unless($request->event_id === $event->id, 404, 'Moderator request not found.');
        abort_unless(in_array($action, ['approve', 'reject', 'revoke'], true), 404);

        return DB::transaction(function () use ($event, $request, $action, $actor, $reason): EventModeratorRequest {
            Event::query()->lockForUpdate()->findOrFail($event->id);
            $locked = EventModeratorRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($action === 'revoke') {
                abort_unless($locked->status === 'approved', 409, 'Only approved moderator access can be revoked.');
                if ($locked->assignment_id) {
                    $assignment = EventRoleAssignment::query()->find($locked->assignment_id);
                    if ($assignment) {
                        $this->personnel->revoke($event, $assignment, $actor);
                    }
                }
                $locked->forceFill(['status' => 'revoked', 'active_request_key' => null, 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();
            } else {
                abort_unless($locked->status === 'pending', 409, 'This moderator request has already been reviewed.');
                $assignment = null;
                if ($action === 'approve') {
                    $assignment = $this->personnel->assign($event, $locked->requester, EventRoleAssignment::REGISTERED_MODERATOR, $actor);
                }
                $locked->forceFill([
                    'status' => $action === 'approve' ? 'approved' : 'rejected',
                    'active_request_key' => null,
                    'assignment_id' => $assignment?->id,
                    'reviewed_by_user_id' => $actor->id,
                    'review_reason' => $reason,
                    'reviewed_at' => now(),
                ])->save();
            }

            $eventName = $action === 'approve' ? 'moderator_approved' : ($action === 'reject' ? 'moderator_rejected' : 'responsibility_revoked');
            $message = match ($action) {
                'approve' => 'Your moderator request for '.$event->title.' was approved.',
                'reject' => 'Your moderator request for '.$event->title.' was rejected.',
                default => 'Your moderator responsibility for '.$event->title.' was revoked.',
            };
            $locked->requester->notify(new EventNotice($eventName, $message, $locked->id, $event->id));
            $this->audit->write($event, $actor, 'event.moderator.'.($action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'revoked')), [
                'request_id' => $locked->id,
                'requester_user_id' => $locked->requester_user_id,
                'reason' => $reason,
            ]);

            return $locked->fresh($this->relations());
        }, 3);
    }

    public function list(Event $event, ?string $status = null): array
    {
        return EventModeratorRequest::query()->where('event_id', $event->id)
            ->when($status, fn (Builder $query, string $value) => $query->where('status', $value))
            ->with($this->relations())->latest('requested_at')->get()
            ->map(fn (EventModeratorRequest $request) => $this->serialize($request))->all();
    }

    public function serialize(EventModeratorRequest $request): array
    {
        $request->loadMissing($this->relations());
        $profile = $request->requester?->profile;

        return [
            'id' => $request->id,
            'event_id' => $request->event_id,
            'requester_user_id' => $request->requester_user_id,
            'name' => trim(implode(' ', array_filter([$profile?->first_name, $profile?->middle_name, $profile?->last_name]))) ?: 'Portal user #'.$request->requester_user_id,
            'email' => $profile?->email,
            'portal_role' => $request->requester?->role?->role_name,
            'status' => $request->status,
            'request_reason' => $request->request_reason,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'review_reason' => $request->review_reason,
            'assignment_id' => $request->assignment_id,
        ];
    }

    private function relations(): array
    {
        return ['requester.role', 'requester.profile', 'assignment'];
    }
}
