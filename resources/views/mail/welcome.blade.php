@extends('mail.layout')

@section('title', 'Welcome to LeggTix')

@section('content')
    <h1 style="font-size: 24px; margin-top: 0;">Welcome to LeggTix</h1>
    <p>Hello {{ $customerName }},</p>
    <p>Your LeggTix account is ready. You can now sign in and reserve a place at an upcoming event.</p>
    <p>We will email you a confirmation when you make a reservation.</p>
    <p>Thanks,<br>The LeggTix team</p>
@endsection
