<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class EnrollmentNotice extends Notification
{
    public function __construct(public string $event, public string $message, public int $referenceId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'enrollment', 'event' => $this->event, 'message' => $this->message, 'reference_id' => $this->referenceId];
    }
}
