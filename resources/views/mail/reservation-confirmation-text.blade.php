Your reservation is confirmed

Hello {!! $customerName !!},

You have reserved one place at {!! $eventName !!}.

Reference: LT-{!! $reservationId !!}
Venue: {!! $venue !!}
Starts: {!! $startsAt !!}
@if ($endsAt !== null)
Ends: {!! $endsAt !!}
@endif
Timezone: {!! $timezone !!}

Please keep this reference for your records.

Thanks,
The LeggTix team
