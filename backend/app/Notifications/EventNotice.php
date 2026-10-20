<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class EventNotice extends Notification
{
    public function __construct(
        public string $event,
        public string $message,
        public int $referenceId,
        public ?int $eventId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'event',
            'event' => $this->event,
            'message' => $this->message,
            'reference_id' => $this->referenceId,
            'event_id' => $this->eventId,
        ];
    }
}
