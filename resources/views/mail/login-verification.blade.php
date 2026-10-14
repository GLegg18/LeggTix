@extends('mail.layout')

@section('title', 'Your LeggTix login code')

@section('content')
    <h1 style="font-size: 24px; margin-top: 0;">Complete your login</h1>
    <p>Enter this code to finish signing in to LeggTix:</p>
    <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>The code expires at {{ $expiresAt }}. Requesting a new login code replaces this one.</p>
    <p>Request reference: {{ $challengeId }}</p>
    <p>If you did not request this login, do not share the code and review your account credentials.</p>
@endsection
