<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends Mailable
{
    public function __construct(public readonly string $customerName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to LeggTix');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.welcome', text: 'mail.welcome-text');
    }
}
