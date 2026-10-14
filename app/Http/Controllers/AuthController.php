<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyLoginEmailRequest;
use App\Data\LoginResult;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\LoginEmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\NewAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController
{
    public const TRUSTED_DEVICE_COOKIE = '__Host-leggtix_trusted_device';

    public function __construct(
        private readonly AuthService $auth,
        private readonly LoginEmailVerificationService $loginVerifications,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->tokenResponse($this->auth->register($request->validated()), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->loginResponse($this->auth->login($request->validated(), $this->trustedCredential($request)));
    }

    public function verifyLogin(VerifyLoginEmailRequest $request, string $challenge): JsonResponse
    {
        return $this->loginResponse($this->loginVerifications->verify(
            $challenge, $request->validated('code'), $request->boolean('remember_device'),
            $this->trustedCredential($request),
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
        $this->loginVerifications->logout(
            $request->user(), $request->user()->currentAccessToken()->getKey(), $this->trustedCredential($request),
        );

        return $this->clearTrustedCookie(response()->noContent());
    }

    public function revokeTrustedDevices(Request $request): Response
    {
        $this->loginVerifications->revokeDevices($request->user());

        return $this->clearTrustedCookie(response()->noContent());
    }

    private function loginResponse(LoginResult $result): JsonResponse
    {
        $response = $result->token !== null ? $this->tokenResponse($result->token) : response()->json([
            'challenge_id' => $result->challengeId, 'expires_at' => $result->challengeExpiresAt->toISOString(),
        ], 202)->header('Cache-Control', 'no-store');

        if ($result->trustedDeviceCredential !== null) {
            $response->headers->setCookie(new Cookie(
                self::TRUSTED_DEVICE_COOKIE, $result->trustedDeviceCredential, $result->trustedDeviceExpiresAt,
                '/', null, true, true, false, Cookie::SAMESITE_STRICT,
            ));
        } elseif ($result->forgetTrustedDevice) {
            $this->clearTrustedCookie($response);
        }

        return $response;
    }

    private function clearTrustedCookie(Response|JsonResponse $response): Response|JsonResponse
    {
        // __Host- cookies require these attributes even when expiring the credential.
        $response->headers->setCookie(new Cookie(
            self::TRUSTED_DEVICE_COOKIE, '', 1, '/', null, true, true, false, Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }

    private function trustedCredential(Request $request): ?string
    {
        $credential = $request->cookie(self::TRUSTED_DEVICE_COOKIE);

        return is_string($credential) ? $credential : null;
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
