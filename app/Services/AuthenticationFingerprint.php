<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;

class AuthenticationFingerprint
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function forUser(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            $user->getKey(), $user->email, $user->getAuthPassword(),
        ], JSON_THROW_ON_ERROR), $this->encrypter->getKey());
    }
}
