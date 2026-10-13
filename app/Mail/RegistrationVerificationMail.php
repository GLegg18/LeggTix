<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationVerificationMail extends Mailable
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly string $expiresAt,
        public readonly string $challengeId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your email for LeggTix');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.registration-verification', text: 'mail.registration-verification-text');
    }
}
