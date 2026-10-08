<?php

namespace App\Enums;

enum ReservationRejection: string
{
    case EventUnavailable = 'event_not_bookable';
    case AlreadyReserved = 'already_reserved';
    case Full = 'full';
    case AlreadyWaitlisted = 'already_waitlisted';
    case QueueHasPriority = 'queue_has_priority';
}
