<?php

namespace App\Mail;

use App\Services\LoginOtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function build(): self
    {
        return $this
            ->subject('Your CDM Portal login verification code')
            ->view('emails.login-verification-code', [
                'expiresInMinutes' => LoginOtpService::CODE_TTL_MINUTES,
            ]);
    }
}
