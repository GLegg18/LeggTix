<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use Laravel\Sanctum\NewAccessToken;

class LoginResult
{
    public function __construct(
        public readonly ?NewAccessToken $token,
        public readonly ?string $challengeId = null,
        public readonly ?CarbonImmutable $challengeExpiresAt = null,
        public readonly ?int $verificationId = null,
        #[\SensitiveParameter] public readonly ?string $trustedDeviceCredential = null,
        public readonly ?CarbonImmutable $trustedDeviceExpiresAt = null,
        public readonly bool $forgetTrustedDevice = false,
    ) {}
}
