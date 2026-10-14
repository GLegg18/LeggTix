@extends('mail.layout')

@section('title', 'Your reservation is confirmed')

@section('content')
    <h1 style="font-size: 24px; margin-top: 0;">Your reservation is confirmed</h1>
    <p>Hello {{ $customerName }},</p>
    <p>You have reserved one place at <strong>{{ $eventName }}</strong>.</p>
    <table style="width: 100%; border-collapse: collapse; text-align: left;">
        <tr><th scope="row" style="padding: 8px 16px 8px 0; vertical-align: top;">Reference</th><td style="padding: 8px 0;">LT-{{ $reservationId }}</td></tr>
        <tr><th scope="row" style="padding: 8px 16px 8px 0; vertical-align: top;">Venue</th><td style="padding: 8px 0;">{{ $venue }}</td></tr>
        <tr><th scope="row" style="padding: 8px 16px 8px 0; vertical-align: top;">Starts</th><td style="padding: 8px 0;">{{ $startsAt }}</td></tr>
        @if ($endsAt !== null)
            <tr><th scope="row" style="padding: 8px 16px 8px 0; vertical-align: top;">Ends</th><td style="padding: 8px 0;">{{ $endsAt }}</td></tr>
        @endif
        <tr><th scope="row" style="padding: 8px 16px 8px 0; vertical-align: top;">Timezone</th><td style="padding: 8px 0;">{{ $timezone }}</td></tr>
    </table>
    <p>Please keep this reference for your records.</p>
    <p>Thanks,<br>The LeggTix team</p>
@endsection
