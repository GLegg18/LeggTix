<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginVerificationMail extends Mailable
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly string $expiresAt,
        public readonly string $challengeId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your LeggTix login code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-verification', text: 'mail.login-verification-text');
    }
}
