<?php

namespace App\Http\Controllers;

use App\Data\LoginResult;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyLoginEmailRequest;
use App\Http\Resources\UserResource;
use App\Http\TrustedDeviceCookie;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LoginEmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SpaAuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly LoginEmailVerificationService $loginVerifications,
        private readonly TrustedDeviceCookie $trustedCookie,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated(), issueToken: false);
        $this->establishSession($request, $user);

        return response()->json(['user' => new UserResource($user)], 201)->header('Cache-Control', 'no-store');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->loginResponse($request, $this->auth->login(
            $request->validated(), $this->trustedCookie->credential($request), issueToken: false,
        ));
    }

    public function verifyLogin(VerifyLoginEmailRequest $request, string $challenge): JsonResponse
    {
        return $this->loginResponse($request, $this->loginVerifications->verify(
            $challenge, $request->validated('code'), $request->boolean('remember_device'),
            $this->trustedCookie->credential($request), issueToken: false,
        ));
    }

    private function loginResponse(Request $request, LoginResult $result): JsonResponse
    {
        if ($result->user !== null) {
            $this->establishSession($request, $result->user);
            $response = response()->json(['user' => new UserResource($result->user)]);
        } else {
            $response = response()->json([
                'challenge_id' => $result->challengeId, 'expires_at' => $result->challengeExpiresAt->toISOString(),
            ], 202);
        }

        $response->header('Cache-Control', 'no-store');
        $this->trustedCookie->apply($response, $result);

        return $response;
    }

    private function establishSession(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        $guard->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));
    }
}
