<?php

namespace App\Mail;

use App\Models\DocumentRequest;
use Illuminate\Mail\Mailable;

class DocumentRequestApprovedMail extends Mailable
{
    public function __construct(
        public readonly DocumentRequest $documentRequest,
        public readonly string $claimCode,
        public readonly string $registrarWindow,
    ) {}

    public function build(): self
    {
        return $this->subject('Document Request Approved')
            ->view('emails.document-request-approved');
    }
}
