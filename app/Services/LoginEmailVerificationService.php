<?php

namespace App\Services;

use App\Data\LoginResult;
use App\Models\LoginEmailVerification;
use App\Models\TrustedDevice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class LoginEmailVerificationService
{
    public function __construct(
        private readonly AuthenticationFingerprint $fingerprints,
        private readonly Encrypter $encrypter,
        private readonly TransactionalEmailDispatcher $emails,
    ) {}

    public function begin(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] ?string $trustedCredential = null): LoginResult
    {
        $guard = Auth::guard('web');

        if (! $guard->once($credentials)) {
            throw $this->invalidCredentials();
        }

        $authenticated = $guard->user();
        $fingerprint = $this->fingerprints->forUser($authenticated);
        $result = DB::transaction(function () use ($authenticated, $fingerprint, $trustedCredential): LoginResult {
            $user = User::query()->lockForUpdate()->find($authenticated->getAuthIdentifier());

            if ($user === null || ! hash_equals($fingerprint, $this->fingerprints->forUser($user))) {
                throw $this->invalidCredentials();
            }

            $verification = LoginEmailVerification::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? new LoginEmailVerification;
            $device = $this->presentedDevice($user, $trustedCredential);
            $now = $this->databaseTime();

            if ($device !== null && $device->revoked_at === null && $device->expires_at->greaterThan($now)
                && hash_equals($fingerprint, $device->credential_fingerprint)
                && hash_equals($device->validator_hash, hash('sha256', $this->cookieParts($trustedCredential)[1]))) {
                $validator = bin2hex(random_bytes(32));
                $device->forceFill(['validator_hash' => hash('sha256', $validator), 'last_used_at' => $now]);
                $this->save($device);

                return new LoginResult(
                    token: $this->issueToken($user),
                    trustedDeviceCredential: $device->selector.'.'.$validator,
                    trustedDeviceExpiresAt: $device->expires_at,
                );
            }

            if ($verification->exists && $verification->issued_at->addMinute()->greaterThan($now)) {
                $retryAfter = max(1, (int) ceil($now->diffInSeconds($verification->issued_at->addMinute())));

                throw new TooManyRequestsHttpException($retryAfter, 'Please wait before requesting another login code.');
            }

            $challengeId = bin2hex(random_bytes(32));
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $verification->forceFill([
                'user_id' => $user->id, 'challenge_id' => $challengeId, 'credential_fingerprint' => $fingerprint,
                'code_hash' => $this->codeHash($challengeId, $fingerprint, $code), 'encrypted_code' => Crypt::encryptString($code),
                'attempts' => 0, 'issued_at' => $now, 'expires_at' => $now->addMinutes(10), 'consumed_at' => null,
            ]);
            $this->save($verification);

            return new LoginResult(
                token: null, challengeId: $challengeId, challengeExpiresAt: $verification->expires_at,
                verificationId: $verification->id,
                forgetTrustedDevice: $trustedCredential !== null,
            );
        }, attempts: 3);

        if ($result->verificationId !== null && $result->challengeId !== null) {
            $this->emails->loginVerification($result->verificationId, $result->challengeId);
        }

        return $result;
    }

    public function verify(
        string $challengeId,
        #[\SensitiveParameter] string $code,
        bool $rememberDevice = false,
        #[\SensitiveParameter] ?string $trustedCredential = null,
    ): LoginResult {
        $userId = LoginEmailVerification::query()->where('challenge_id', $challengeId)->value('user_id');
        $result = DB::transaction(function () use ($userId, $challengeId, $code, $rememberDevice, $trustedCredential): ?LoginResult {
            $user = $userId === null ? null : User::query()->lockForUpdate()->find($userId);
            $verification = $user === null ? null : LoginEmailVerification::query()->where('user_id', $userId)->lockForUpdate()->first();
            $presentedDevice = $user === null ? null : $this->presentedDevice($user, $trustedCredential);
            $now = $this->databaseTime();

            if ($verification === null || ! hash_equals($verification->challenge_id, $challengeId) || $verification->consumed_at !== null) {
                return null;
            }

            if ($verification->expires_at->lessThanOrEqualTo($now) || $verification->attempts >= 5
                || ! hash_equals($verification->credential_fingerprint, $this->fingerprints->forUser($user))) {
                $verification->forceFill(['code_hash' => null, 'encrypted_code' => null, 'consumed_at' => $now]);
                $this->save($verification);

                return null;
            }

            if ($verification->code_hash === null || ! hash_equals($verification->code_hash, $this->codeHash($challengeId, $verification->credential_fingerprint, $code))) {
                $verification->attempts++;

                if ($verification->attempts >= 5) {
                    $verification->forceFill(['code_hash' => null, 'encrypted_code' => null]);
                }

                $this->save($verification);

                return null;
            }

            $verification->forceFill(['code_hash' => null, 'encrypted_code' => null, 'consumed_at' => $now]);
            $this->save($verification);

            if ($user->email_verified_at === null) {
                $user->email_verified_at = $now;
                $this->save($user);
            }

            $this->revokeDevice($presentedDevice, $now);
            $device = null;
            $validator = null;

            if ($rememberDevice) {
                $validator = bin2hex(random_bytes(32));
                $device = new TrustedDevice;
                $device->forceFill([
                    'user_id' => $user->id, 'selector' => bin2hex(random_bytes(16)), 'validator_hash' => hash('sha256', $validator),
                    'credential_fingerprint' => $this->fingerprints->forUser($user), 'expires_at' => $now->addDays(7),
                    'last_used_at' => $now, 'revoked_at' => null,
                ]);
                $this->save($device);
            }

            return new LoginResult(
                token: $this->issueToken($user),
                trustedDeviceCredential: $device === null ? null : $device->selector.'.'.$validator,
                trustedDeviceExpiresAt: $device?->expires_at,
                forgetTrustedDevice: ! $rememberDevice,
            );
        }, attempts: 3);

        if ($result === null) {
            throw ValidationException::withMessages(['code' => ['The code is invalid or expired. Request a new login code if needed.']]);
        }

        return $result;
    }

    public function logout(User $actor, int $tokenId, #[\SensitiveParameter] ?string $trustedCredential): void
    {
        DB::transaction(function () use ($actor, $tokenId, $trustedCredential): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $device = $this->presentedDevice($user, $trustedCredential);
            $this->revokeDevice($device, $this->databaseTime());
            $user->tokens()->whereKey($tokenId)->delete();
        }, attempts: 3);
    }

    public function revokeDevices(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $verification = LoginEmailVerification::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $now = $this->databaseTime();

            if ($verification !== null) {
                $verification->forceFill(['code_hash' => null, 'encrypted_code' => null, 'consumed_at' => $now]);
                $this->save($verification);
            }

            TrustedDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        }, attempts: 3);
    }

    private function presentedDevice(User $user, #[\SensitiveParameter] ?string $credential): ?TrustedDevice
    {
        $parts = $this->cookieParts($credential);

        return $parts === null ? null : TrustedDevice::query()->where('user_id', $user->id)->where('selector', $parts[0])->lockForUpdate()->first();
    }

    private function cookieParts(#[\SensitiveParameter] ?string $credential): ?array
    {
        return is_string($credential) && preg_match('/\A[a-f0-9]{32}\.[a-f0-9]{64}\z/', $credential) === 1
            ? explode('.', $credential, 2) : null;
    }

    private function revokeDevice(?TrustedDevice $device, CarbonImmutable $now): void
    {
        if ($device !== null && $device->revoked_at === null) {
            // The stable selector revokes the family even if a concurrent login rotated its validator.
            $device->revoked_at = $now;
            $this->save($device);
        }
    }

    private function codeHash(string $challengeId, string $fingerprint, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$fingerprint.'|'.$code, $this->encrypter->getKey());
    }

    private function issueToken(User $user): NewAccessToken
    {
        $token = $user->createToken('api', ['*'], now()->addMinutes(config('sanctum.expiration')));

        if (! $token->accessToken->exists || $token->accessToken->getKey() === null) {
            throw new RuntimeException('The API token could not be saved.');
        }

        return $token;
    }

    private function invalidCredentials(): ValidationException
    {
        return ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
    }

    private function databaseTime(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC');
    }

    private function save(User|LoginEmailVerification|TrustedDevice $model): void
    {
        if (! $model->save()) {
            throw new RuntimeException('The authentication change could not be saved.');
        }
    }
}
