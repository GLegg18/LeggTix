<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReservationConfirmationMail extends Mailable
{
    public function __construct(
        public readonly string $customerName,
        public readonly int $reservationId,
        public readonly string $eventName,
        public readonly string $venue,
        public readonly string $startsAt,
        public readonly ?string $endsAt,
        public readonly string $timezone,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your LeggTix reservation is confirmed');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reservation-confirmation', text: 'mail.reservation-confirmation-text');
    }
}
