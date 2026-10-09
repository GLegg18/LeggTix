<?php

namespace App\Exceptions;

use App\Enums\ReservationRejection;
use RuntimeException;

class ReservationRejectedException extends RuntimeException
{
    public function __construct(public readonly ReservationRejection $reason)
    {
        parent::__construct(match ($reason) {
            ReservationRejection::EventUnavailable => 'Only published events that have not started accept reservations.',
            ReservationRejection::AlreadyReserved => 'You already have a confirmed reservation for this event.',
            ReservationRejection::Full => 'This event has no available places.',
            ReservationRejection::AlreadyWaitlisted => 'You are already waiting for this event.',
            ReservationRejection::QueueHasPriority => 'Existing waitlist entries have priority for available places.',
        });
    }
}
