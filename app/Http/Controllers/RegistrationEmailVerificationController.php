<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartRegistrationEmailVerificationRequest;
use App\Http\Requests\VerifyRegistrationEmailRequest;
use App\Services\RegistrationEmailVerificationService;
use Illuminate\Http\JsonResponse;

class RegistrationEmailVerificationController
{
    public function __construct(private readonly RegistrationEmailVerificationService $verifications) {}

    public function store(StartRegistrationEmailVerificationRequest $request): JsonResponse
    {
        return response()->json($this->verifications->request($request->validated('email')), 202)
            ->header('Cache-Control', 'no-store');
    }

    public function verify(VerifyRegistrationEmailRequest $request, string $challenge): JsonResponse
    {
        return response()->json($this->verifications->verify($challenge, $request->validated('code')))
            ->header('Cache-Control', 'no-store');
    }
}
