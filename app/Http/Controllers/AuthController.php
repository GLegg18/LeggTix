<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyLoginEmailRequest;
use App\Data\LoginResult;
use App\Http\TrustedDeviceCookie;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\LoginEmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController
{
    public const TRUSTED_DEVICE_COOKIE = TrustedDeviceCookie::NAME;

    public function __construct(
        private readonly AuthService $auth,
        private readonly LoginEmailVerificationService $loginVerifications,
        private readonly TrustedDeviceCookie $trustedCookie,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->tokenResponse($this->auth->register($request->validated()), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->loginResponse($this->auth->login($request->validated(), $this->trustedCookie->credential($request)));
    }

    public function verifyLogin(VerifyLoginEmailRequest $request, string $challenge): JsonResponse
    {
        return $this->loginResponse($this->loginVerifications->verify(
            $challenge, $request->validated('code'), $request->boolean('remember_device'),
            $this->trustedCookie->credential($request),
        ));
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();
        $this->loginVerifications->logout(
            $request->user(), $token instanceof PersonalAccessToken ? $token->getKey() : null,
            $this->trustedCookie->credential($request),
        );

        if (! $token instanceof PersonalAccessToken && $request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->trustedCookie->forget(response()->noContent());
    }

    public function revokeTrustedDevices(Request $request): Response
    {
        $this->loginVerifications->revokeDevices($request->user());

        return $this->trustedCookie->forget(response()->noContent());
    }

    private function loginResponse(LoginResult $result): JsonResponse
    {
        $response = $result->token !== null ? $this->tokenResponse($result->token) : response()->json([
            'challenge_id' => $result->challengeId, 'expires_at' => $result->challengeExpiresAt->toISOString(),
        ], 202)->header('Cache-Control', 'no-store');

        $this->trustedCookie->apply($response, $result);

        return $response;
    }

    private function tokenResponse(NewAccessToken $token, int $status = 200): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($token->accessToken->tokenable),
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at->toISOString(),
        ], $status)->header('Cache-Control', 'no-store');
    }
}
