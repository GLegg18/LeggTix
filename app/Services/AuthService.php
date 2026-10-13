<?php

namespace App\Services;

use App\Models\User;
use App\Data\LoginResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

class AuthService
{
    public function __construct(
        private readonly TransactionalEmailDispatcher $emails,
        private readonly RegistrationEmailVerificationService $verifications,
        private readonly LoginEmailVerificationService $loginVerifications,
    ) {}

    public function register(array $attributes): NewAccessToken
    {
        try {
            $token = DB::transaction(function () use ($attributes): NewAccessToken {
                $verifiedAt = $this->verifications->consume($attributes['email'], $attributes['registration_token'] ?? '');
                $user = new User([
                    'name' => $attributes['name'],
                    'email' => $attributes['email'],
                    // Public password input is always plaintext, even if it resembles a hash.
                    'password' => Hash::make($attributes['password']),
                ]);
                $user->email_verified_at = $verifiedAt;

                if (! $user->save()) {
                    throw new RuntimeException('The verified account could not be saved.');
                }

                return $this->issueToken($user);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The unique index also protects simultaneous registrations after validation.
            if (! User::where('email', $attributes['email'])->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        $this->emails->welcome((int) $token->accessToken->tokenable_id);

        return $token;
    }

    public function login(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] ?string $trustedCredential = null): LoginResult
    {
        return $this->loginVerifications->begin($credentials, $trustedCredential);
    }

    private function issueToken(User $user): NewAccessToken
    {
        $token = $user->createToken('api', ['*'], now()->addMinutes(config('sanctum.expiration')));

        if (! $token->accessToken->exists || $token->accessToken->getKey() === null) {
            throw new RuntimeException('The API token could not be saved.');
        }

        return $token;
    }
}
