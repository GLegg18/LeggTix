<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Mail\ReservationConfirmationMail;
use App\Models\Reservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendReservationConfirmationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $reservationId)
    {
        $this->onQueue('emails');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(): void
    {
        $reservation = Reservation::query()->with(['user', 'event'])->find($this->reservationId);

        if ($reservation === null || $reservation->status !== ReservationStatus::Confirmed
            || $reservation->user === null || $reservation->event === null
            || ! $reservation->event->isBookable()) {
            return;
        }

        $event = $reservation->event;
        $format = 'l j F Y, H:i T (P)';

        Mail::to($reservation->user->email)->send(new ReservationConfirmationMail(
            customerName: $reservation->user->name,
            reservationId: $reservation->id,
            eventName: $event->name,
            venue: $event->venue,
            startsAt: $event->starts_at->setTimezone($event->timezone)->format($format),
            endsAt: $event->ends_at?->setTimezone($event->timezone)->format($format),
            timezone: $event->timezone,
        ));
    }
}
