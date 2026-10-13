<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesRegistrationProofs
{
    /** A completed email step, without dispatching mail from unrelated workflow tests. */
    private function registrationProof(mixed $email = 'customer@example.test'): string
    {
        $normalized = is_string($email) && mb_strlen(trim($email)) <= 255
            ? mb_strtolower(trim($email)) : 'customer@example.test';
        $token = bin2hex(random_bytes(32));
        DB::table('registration_email_verifications')->updateOrInsert(['email' => $normalized], [
            'challenge_id' => bin2hex(random_bytes(32)), 'code_hash' => null, 'encrypted_code' => null,
            'attempts' => 0, 'expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE)'),
            'verified_at' => DB::raw('UTC_TIMESTAMP(6)'), 'token_hash' => hash('sha256', $token),
            'token_expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE)'),
            'consumed_at' => null, 'created_at' => DB::raw('UTC_TIMESTAMP(6)'), 'updated_at' => DB::raw('UTC_TIMESTAMP(6)'),
        ]);

        return $token;
    }
}
