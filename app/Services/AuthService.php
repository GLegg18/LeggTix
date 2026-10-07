<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    public function register(array $attributes): NewAccessToken
    {
        try {
            return DB::transaction(function () use ($attributes): NewAccessToken {
                $user = User::create([
                    'name' => $attributes['name'],
                    'email' => $attributes['email'],
                    // Public password input is always plaintext, even if it resembles a hash.
                    'password' => Hash::make($attributes['password']),
                ]);

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
    }

    public function login(array $credentials): NewAccessToken
    {
        $guard = Auth::guard('web');

        if (! $guard->once($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->issueToken($guard->user());
    }

    private function issueToken(User $user): NewAccessToken
    {
        return $user->createToken('api', ['*'], now()->addMinutes(config('sanctum.expiration')));
    }
}
