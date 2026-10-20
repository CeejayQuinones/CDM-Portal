<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventEmailNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public string $message)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->greeting('CDM Portal Event update')->line($this->message)->line('Sign in to CDM Portal for the authoritative Event details.');
    }
}
