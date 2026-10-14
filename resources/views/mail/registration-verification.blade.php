@extends('mail.layout')

@section('title', 'Verify your email for LeggTix')

@section('content')
    <h1 style="font-size: 24px; margin-top: 0;">Verify your email</h1>
    <p>Enter this code to verify your email address and continue creating your LeggTix account:</p>
    <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>The code expires at {{ $expiresAt }}. Requesting a new code replaces this one.</p>
    <p>Request reference: {{ $challengeId }}</p>
    <p>If you did not request an account, you can ignore this message. Do not share your code.</p>
@endsection
