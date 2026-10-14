<?php

namespace App\Services;

use App\Models\RegistrationEmailVerification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RegistrationEmailVerificationService
{
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly TransactionalEmailDispatcher $emails,
    ) {}

    public function request(string $email): array
    {
        $email = Str::lower(trim($email));
        $challengeId = bin2hex(random_bytes(32));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Two first requests can race before the unique email row exists.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $verification = DB::transaction(function () use ($email, $challengeId, $code): RegistrationEmailVerification {
                    $verification = RegistrationEmailVerification::query()->where('email', $email)->lockForUpdate()->first()
                        ?? new RegistrationEmailVerification;
                    $now = $this->databaseTime();
                    $verification->forceFill([
                        'email' => $email,
                        'challenge_id' => $challengeId,
                        'code_hash' => hash_hmac('sha256', $challengeId.'|'.$email.'|'.$code, $this->encrypter->getKey()),
                        'encrypted_code' => Crypt::encryptString($code),
                        'attempts' => 0,
                        'expires_at' => $now->addMinutes(10),
                        'verified_at' => null,
                        'token_hash' => null,
                        'token_expires_at' => null,
                        'consumed_at' => null,
                    ]);
                    $this->save($verification);

                    return $verification;
                }, attempts: 3);

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 1 || ! RegistrationEmailVerification::query()->where('email', $email)->exists()) {
                    throw $exception;
                }
            }
        }

        $this->emails->registrationVerification($verification->id, $verification->challenge_id);

        return ['challenge_id' => $verification->challenge_id, 'expires_at' => $verification->expires_at->toISOString()];
    }

    public function verify(string $challengeId, #[\SensitiveParameter] string $code): array
    {
        $result = DB::transaction(function () use ($challengeId, $code): ?array {
            $verification = RegistrationEmailVerification::query()->where('challenge_id', $challengeId)->lockForUpdate()->first();
            $now = $this->databaseTime();

            if ($verification === null || $verification->verified_at !== null || $verification->consumed_at !== null) {
                return null;
            }

            if ($verification->expires_at->lessThanOrEqualTo($now) || $verification->attempts >= 5) {
                $verification->forceFill(['code_hash' => null, 'encrypted_code' => null]);
                $this->save($verification);

                return null;
            }

            $expected = hash_hmac('sha256', $challengeId.'|'.$verification->email.'|'.$code, $this->encrypter->getKey());

            if ($verification->code_hash === null || ! hash_equals($verification->code_hash, $expected)) {
                $verification->attempts++;

                if ($verification->attempts >= 5) {
                    $verification->forceFill(['code_hash' => null, 'encrypted_code' => null]);
                }

                $this->save($verification);

                return null;
            }

            $token = bin2hex(random_bytes(32));
            $verification->forceFill([
                'code_hash' => null,
                'encrypted_code' => null,
                'verified_at' => $now,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => $now->addMinutes(10),
            ]);
            $this->save($verification);

            return ['registration_token' => $token, 'expires_at' => $verification->token_expires_at->toISOString()];
        }, attempts: 3);

        // Failed attempts and expiry scrubbing must commit before returning a validation error.
        if ($result === null) {
            throw ValidationException::withMessages(['code' => ['The code is invalid or expired. Request a new code if needed.']]);
        }

        return $result;
    }

    public function consume(string $email, #[\SensitiveParameter] string $registrationToken): CarbonImmutable
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Registration proof consumption requires the account transaction.');
        }

        $verification = RegistrationEmailVerification::query()->where('token_hash', hash('sha256', $registrationToken))
            ->lockForUpdate()->first();
        $now = $this->databaseTime();

        if ($verification === null || ! hash_equals($verification->email, Str::lower(trim($email)))
            || $verification->consumed_at !== null || $verification->verified_at === null
            || $verification->token_expires_at === null || $verification->token_expires_at->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages([
                'registration_token' => ['Verify this email address before completing registration.'],
            ]);
        }

        $verifiedAt = $verification->verified_at;
        $verification->forceFill([
            'token_hash' => null, 'token_expires_at' => null,
            'code_hash' => null, 'encrypted_code' => null, 'consumed_at' => $now,
        ]);
        $this->save($verification);

        return $verifiedAt;
    }

    private function databaseTime(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC');
    }

    private function save(RegistrationEmailVerification $verification): void
    {
        if (! $verification->save()) {
            throw new RuntimeException('Registration email verification could not be saved.');
        }
    }
}
