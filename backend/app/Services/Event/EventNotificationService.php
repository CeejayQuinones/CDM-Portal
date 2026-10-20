<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EventEmailNotice;
use App\Notifications\EventNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EventNotificationService
{
    public function __construct(private readonly EventAudienceResolver $audiences) {}

    public function eventAudience(Event $event, string $type, string $message, int $referenceId, bool $email = false): int
    {
        $recipients = $this->recipients($event);
        $sent = 0;
        foreach ($recipients as $user) {
            try {
                $duplicate = $user->notifications()->where('data->module', 'event')->where('data->event', $type)->where('data->reference_id', $referenceId)->exists();
                if ($duplicate) {
                    continue;
                }
                $user->notify(new EventNotice($type, $message, $referenceId, $event->id));
                if ($email && $user->routeNotificationFor('mail')) {
                    $user->notify(new EventEmailNotice('CDM Portal: '.$event->title, $message));
                }
                $sent++;
            } catch (\Throwable $exception) {
                Log::warning('Event notification delivery failed.', ['event_id' => $event->id, 'user_id' => $user->id, 'type' => $type, 'exception' => $exception::class]);
            }
        }

        return $sent;
    }

    public function user(User $user, Event $event, string $type, string $message, int $referenceId, bool $email = false): void
    {
        try {
            $duplicate = $user->notifications()->where('data->module', 'event')->where('data->event', $type)->where('data->reference_id', $referenceId)->exists();
            if ($duplicate) {
                return;
            }
            $user->notify(new EventNotice($type, $message, $referenceId, $event->id));
            if ($email && $user->routeNotificationFor('mail')) {
                $user->notify(new EventEmailNotice('CDM Portal: '.$event->title, $message));
            }
        } catch (\Throwable $exception) {
            Log::warning('Event notification delivery failed.', ['event_id' => $event->id, 'user_id' => $user->id, 'type' => $type, 'exception' => $exception::class]);
        }
    }

    public function upcomingReminders(): int
    {
        $sent = 0;
        Event::query()->where('status', 'published')->whereBetween('starts_at', [now()->addHours(23), now()->addHours(25)])->each(function (Event $event) use (&$sent): void {
            $sent += $this->eventAudience($event, 'event_reminder', $event->title.' starts in about 24 hours at '.$event->venue.'.', (int) $event->starts_at->timestamp, true);
        });

        return $sent;
    }

    private function recipients(Event $event): Collection
    {
        $event->loadMissing(['audiences', 'parent.audiences']);
        $effective = $event->audiences->isNotEmpty() ? $event->audiences : ($event->parent?->audiences ?? collect());
        $users = $this->audiences->eligibleStudents($event)->with('user')->get()->pluck('user')->filter();
        if ($effective->pluck('audience_type')->intersect(['all_professors', 'all_users'])->isNotEmpty()) {
            $professors = User::query()->where('status', 'active')->whereHas('role', fn ($query) => $query->where('role_name', Role::PROFESSOR))->get();
            $users = $users->concat($professors);
        }

        return $users->unique('id')->values();
    }
}
