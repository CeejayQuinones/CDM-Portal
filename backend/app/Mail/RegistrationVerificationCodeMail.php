<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function build(): self
    {
        return $this
            ->subject('Your CDM Portal verification code')
            ->view('emails.registration-verification-code');
    }
}
