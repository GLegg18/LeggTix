<?php

namespace App\Jobs;

use App\Mail\LoginVerificationMail;
use App\Models\LoginEmailVerification;
use App\Services\AuthenticationFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendLoginVerificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $verificationId, public readonly string $challengeId)
    {
        $this->onQueue('emails');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(AuthenticationFingerprint $fingerprints): void
    {
        $verification = LoginEmailVerification::query()->with('user')->find($this->verificationId);
        $now = CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC');

        if ($verification === null || $verification->user === null || ! hash_equals($verification->challenge_id, $this->challengeId)
            || $verification->consumed_at !== null || $verification->encrypted_code === null || $verification->attempts >= 5
            || $verification->expires_at->lessThanOrEqualTo($now)
            || ! hash_equals($verification->credential_fingerprint, $fingerprints->forUser($verification->user))) {
            return;
        }

        Mail::to($verification->user->email)->send(new LoginVerificationMail(
            code: Crypt::decryptString($verification->encrypted_code),
            expiresAt: $verification->expires_at->format('j F Y, H:i:s').' UTC',
            challengeId: $verification->challenge_id,
        ));
    }
}
