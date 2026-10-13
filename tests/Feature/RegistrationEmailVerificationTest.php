<?php

namespace Tests\Feature;

use App\Jobs\SendRegistrationVerificationEmail;
use App\Jobs\SendWelcomeEmail;
use App\Models\RegistrationEmailVerification;
use App\Models\User;
use App\Services\RegistrationEmailVerificationService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CreatesRegistrationProofs;
use Tests\TestCase;

class RegistrationEmailVerificationTest extends TestCase
{
    // Real commits prove failed attempts survive 422 and callbacks wait for the outer transaction.
    use DatabaseMigrations;
    use CreatesRegistrationProofs;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('verification_observer');
        parent::tearDown();
    }

    public function test_anonymous_email_code_proof_and_signup_flow_stores_no_plaintext_secrets(): void
    {
        $requested = $this->api('/api/registration/email-verifications', ['email' => '  New.Customer@Example.TEST  '])
            ->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['challenge_id', 'expires_at'], array_keys($requested->json()));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $requested->json('challenge_id'));
        $row = RegistrationEmailVerification::sole();
        $code = Crypt::decryptString($row->encrypted_code);
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $this->assertSame('new.customer@example.test', $row->email);
        $this->assertSame(hash_hmac('sha256', $row->challenge_id.'|'.$row->email.'|'.$code, app(Encrypter::class)->getKey()), $row->code_hash);
        $this->assertNotSame($code, $row->encrypted_code);
        $this->assertStringNotContainsString($code, $requested->getContent());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Queue::assertPushed(SendRegistrationVerificationEmail::class, function ($job) use ($row, $code): bool {
            $this->assertSame($row->id, $job->verificationId);
            $this->assertSame($row->challenge_id, $job->challengeId);
            $this->assertSame('emails', $job->queue);
            $this->assertNull($job->connection);
            foreach ([$row->email, $code, $row->encrypted_code, $row->code_hash] as $secret) {
                $this->assertStringNotContainsString($secret, serialize($job));
            }

            return true;
        });
        Queue::assertCount(1);

        $verified = $this->api($this->verifyPath($row->challenge_id), ['code' => $code])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['registration_token', 'expires_at'], array_keys($verified->json()));
        $proof = $verified->json('registration_token');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $proof);
        $row->refresh();
        $this->assertSame(hash('sha256', $proof), $row->token_hash);
        $this->assertNull($row->code_hash);
        $this->assertNull($row->encrypted_code);
        $this->assertNotNull($row->verified_at);
        foreach (['email', 'challenge_id', 'code_hash', 'encrypted_code', 'token_hash'] as $sensitiveField) {
            $this->assertArrayNotHasKey($sensitiveField, $row->toArray());
        }

        $response = $this->api('/api/register', $this->registration($proof, '  NEW.CUSTOMER@EXAMPLE.TEST  ')
            + ['email_verified_at' => '1999-01-01', 'remember_token' => 'attacker'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $user = User::sole();
        $this->assertTrue($row->verified_at->equalTo($user->email_verified_at));
        $this->assertArrayNotHasKey('email_verified_at', $user->toArray());
        $this->assertSame(['id', 'name', 'email', 'role'], array_keys($response->json('user')));
        foreach ([$proof, $code, $row->token_hash, $user->password] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $row->refresh();
        $this->assertNotNull($row->consumed_at);
        $this->assertNull($row->token_hash);
        $this->assertNull($row->token_expires_at);
        Queue::assertPushed(SendWelcomeEmail::class, 1);
        Queue::assertCount(2);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    #[DataProvider('badEmails')]
    public function test_request_rejects_missing_or_invalid_email_without_mail_or_state(mixed $email): void
    {
        $this->api('/api/registration/email-verifications', ['email' => $email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('registration_email_verifications', 0);
        Queue::assertNothingPushed();
    }

    public static function badEmails(): array
    {
        return [[null], [''], ['not-email'], [['customer@example.test']], [str_repeat('x', 256).'@example.test']];
    }

    public function test_existing_legacy_account_can_login_but_cannot_request_another_signup_code(): void
    {
        $legacy = User::factory()->create(['email' => 'legacy@example.test']);
        $this->assertNull($legacy->email_verified_at);
        $this->api('/api/login', ['email' => 'legacy@example.test', 'password' => 'password'])->assertOk();
        $this->api('/api/registration/email-verifications', ['email' => ' LEGACY@EXAMPLE.TEST '])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('registration_email_verifications', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('badCodes')]
    public function test_malformed_codes_are_rejected_without_consuming_guess_budget(mixed $code): void
    {
        $row = $this->challenge('001234');
        $this->api($this->verifyPath($row->challenge_id), ['code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(0, $row->fresh()->attempts);
        $this->assertNull($row->fresh()->token_hash);
    }

    public static function badCodes(): array
    {
        return [[null], [1234], [123456], ['12345'], ['1234567'], ['12a456'], [['123456']]];
    }

    public function test_six_digit_string_with_leading_zero_is_accepted_only_once(): void
    {
        $row = $this->challenge('001234');
        $token = $this->api($this->verifyPath($row->challenge_id), ['code' => '001234'])->assertOk()->json('registration_token');
        $firstHash = $row->fresh()->token_hash;
        $this->api($this->verifyPath($row->challenge_id), ['code' => '001234'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame($firstHash, $row->fresh()->token_hash);
        $this->assertSame(hash('sha256', $token), $firstHash);
    }

    public function test_five_wrong_guesses_commit_and_destroy_code_even_when_request_returns_422(): void
    {
        $row = $this->challenge('001234');
        config(['database.connections.verification_observer' => config('database.connections.mysql')]);
        $observer = DB::connection('verification_observer');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->api($this->verifyPath($row->challenge_id), ['code' => '999999'])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertSame($attempt, $observer->table('registration_email_verifications')->where('id', $row->id)->value('attempts'));
        }
        $this->assertNull($row->fresh()->code_hash);
        $this->assertNull($row->fresh()->encrypted_code);
        $this->travel(61)->seconds(); // The sixth request must reach the state check, beyond the minute limiter.
        $this->api($this->verifyPath($row->challenge_id), ['code' => '001234'])->assertUnprocessable();
        $this->assertSame(5, $row->fresh()->attempts);
        $this->assertNull($row->fresh()->token_hash);
    }

    public function test_expired_and_unknown_codes_return_the_same_generic_error_without_secret_fields(): void
    {
        $row = $this->challenge('001234');
        DB::table('registration_email_verifications')->where('id', $row->id)->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        $expired = $this->api($this->verifyPath($row->challenge_id), ['code' => '001234'])
            ->assertUnprocessable()->assertJsonValidationErrors('code')->json();
        $unknown = $this->api($this->verifyPath(str_repeat('a', 64)), ['code' => '001234'])
            ->assertUnprocessable()->assertJsonValidationErrors('code')->json();
        $this->assertSame($unknown, $expired);
        $this->assertNull($row->fresh()->encrypted_code);
        $this->assertNull($row->fresh()->code_hash);
        $this->assertNull($row->fresh()->token_hash);
    }

    #[DataProvider('badProofs')]
    public function test_signup_requires_a_valid_same_mailbox_unexpired_proof(string $scenario): void
    {
        $proof = $this->registrationProof('test@example.test');
        $email = 'test@example.test';
        if ($scenario === 'missing') {
            $proof = null;
        } elseif ($scenario === 'unknown') {
            $proof = str_repeat('a', 64);
        } elseif ($scenario === 'other email') {
            $email = 'other@example.test';
        } elseif ($scenario === 'accent email') {
            $email = 'tést@example.test';
            $this->assertSame(1, (int) DB::scalar("SELECT CONVERT('test' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT('tést' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci"));
        } else {
            DB::table('registration_email_verifications')->update(['token_expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        }
        $this->api('/api/register', $this->registration($proof, $email))
            ->assertUnprocessable()->assertJsonValidationErrors('registration_token');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(RegistrationEmailVerification::sole()->consumed_at);
        Queue::assertNothingPushed();
    }

    public static function badProofs(): array
    {
        return [['missing'], ['unknown'], ['other email'], ['accent email'], ['expired']];
    }

    public function test_resend_rotates_challenge_and_invalidates_old_code_and_old_proof(): void
    {
        $first = $this->challenge('001234');
        $firstId = $first->id;
        $firstChallenge = $first->challenge_id;
        $proof = $this->api($this->verifyPath($firstChallenge), ['code' => '001234'])->assertOk()->json('registration_token');
        $second = app(RegistrationEmailVerificationService::class)->request(' CUSTOMER@EXAMPLE.TEST ');
        $this->assertNotSame($firstChallenge, $second['challenge_id']);
        $this->assertSame($firstId, RegistrationEmailVerification::sole()->id);
        $this->assertNull($first->fresh()->token_hash);
        $this->api($this->verifyPath($firstChallenge), ['code' => '001234'])->assertUnprocessable();
        $this->api('/api/register', $this->registration($proof))->assertUnprocessable()->assertJsonValidationErrors('registration_token');
        $this->assertDatabaseCount('users', 0);
        Queue::assertPushed(SendRegistrationVerificationEmail::class, 2);
        Queue::assertNotPushed(SendWelcomeEmail::class);
    }

    public function test_successful_signup_consumes_proof_and_replay_cannot_create_another_account_or_token(): void
    {
        $proof = $this->registrationProof();
        $this->api('/api/register', $this->registration($proof))->assertCreated();
        $this->api('/api/register', $this->registration($proof))->assertUnprocessable();
        $this->api('/api/register', $this->registration($proof, 'other@example.test'))->assertUnprocessable()->assertJsonValidationErrors('registration_token');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Queue::assertPushed(SendWelcomeEmail::class, 1);
    }

    public function test_token_write_failure_rolls_back_user_and_proof_then_same_proof_can_succeed(): void
    {
        $proof = $this->registrationProof();
        $before = DB::table('registration_email_verifications')->first();
        Event::listen('eloquent.creating: '.PersonalAccessToken::class, static function (): never {
            throw new RuntimeException('Injected token write failure');
        });
        $this->api('/api/register', $this->registration($proof))->assertStatus(500);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertEquals($before, DB::table('registration_email_verifications')->first());
        Queue::assertNothingPushed();
        Event::forget('eloquent.creating: '.PersonalAccessToken::class);
        $this->api('/api/register', $this->registration($proof))->assertCreated();
        $this->assertNotNull(RegistrationEmailVerification::sole()->consumed_at);
    }

    public function test_proof_consumption_outside_registration_transaction_is_refused(): void
    {
        $proof = $this->registrationProof();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires the account transaction');
        app(RegistrationEmailVerificationService::class)->consume('customer@example.test', $proof);
    }

    public function test_required_verification_enqueue_failure_is_503_and_can_be_recovered_by_requesting_again(): void
    {
        Queue::shouldReceive('connection')->once()->withNoArgs()->andThrow(new RuntimeException('redis://private:secret@host'));
        Log::spy();
        $response = $this->api('/api/registration/email-verifications', ['email' => 'customer@example.test'])
            ->assertStatus(503)->assertHeader('Retry-After', '1');
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $oldChallenge = RegistrationEmailVerification::sole()->challenge_id;
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('users', 0);
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame(['email_type', 'reference_id', 'exception_type'], array_keys($context));
            $this->assertSame('registration_verification', $context['email_type']);
            $this->assertStringNotContainsString('secret', $message.json_encode($context));

            return true;
        });
        Queue::clearResolvedInstance('queue');
        $this->app->forgetInstance('queue');
        Queue::fake();
        $this->travel(61)->seconds();
        $fresh = $this->api('/api/registration/email-verifications', ['email' => 'customer@example.test'])->assertStatus(202);
        $this->assertNotSame($oldChallenge, $fresh->json('challenge_id'));
        Queue::assertPushed(SendRegistrationVerificationEmail::class, 1);
    }

    public function test_required_delivery_waits_for_real_outer_commit_and_is_discarded_on_rollback(): void
    {
        $this->assertSame(DatabaseTransactionsManager::class, get_class(app('db.transactions')));
        DB::beginTransaction();
        DB::beginTransaction();
        app(RegistrationEmailVerificationService::class)->request('customer@example.test');
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SendRegistrationVerificationEmail::class, 1);
        Queue::fake();
        DB::beginTransaction();
        app(RegistrationEmailVerificationService::class)->request('rollback@example.test');
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('registration_email_verifications', ['email' => 'rollback@example.test']);
    }

    public function test_queue_and_logger_outage_still_returns_sanitized_503_for_required_email(): void
    {
        Queue::shouldReceive('connection')->once()->withNoArgs()->andThrow(new RuntimeException('private queue secret'));
        Log::shouldReceive('error')->once()->andThrow(new RuntimeException('private logger secret'));
        $response = $this->api('/api/registration/email-verifications', ['email' => 'customer@example.test'])
            ->assertStatus(503)->assertHeader('Retry-After', '1');
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertDatabaseCount('registration_email_verifications', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_request_ip_limit_bounds_rotating_mailbox_spam(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->api('/api/registration/email-verifications', ['email' => 'ip-limit-'.$attempt.'@example.test'])->assertStatus(202);
        }
        $this->api('/api/registration/email-verifications', ['email' => 'ip-limit-next@example.test'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('registration_email_verifications', 10);
        Queue::assertPushed(SendRegistrationVerificationEmail::class, 10);
    }

    public function test_verify_ip_limit_bounds_rotating_challenge_spam(): void
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $challenge = str_pad(dechex($attempt), 64, '0', STR_PAD_LEFT);
            $this->api($this->verifyPath($challenge), ['code' => '999999'])->assertUnprocessable();
        }
        $this->api($this->verifyPath(str_repeat('f', 64)), ['code' => '999999'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('registration_email_verifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_email_and_challenge_limits_cannot_be_reset_by_switching_ip_or_resending(): void
    {
        $this->api('/api/registration/email-verifications', ['email' => 'customer@example.test'], '192.0.2.1')->assertStatus(202);
        $this->api('/api/registration/email-verifications', ['email' => ' CUSTOMER@EXAMPLE.TEST '], '192.0.2.2')->assertStatus(429)->assertHeader('Retry-After');
        for ($request = 2; $request <= 5; $request++) {
            $this->travel(61)->seconds();
            $this->api('/api/registration/email-verifications', ['email' => 'customer@example.test'], '192.0.2.'.$request)->assertStatus(202);
        }
        $this->travel(61)->seconds();
        $this->api('/api/registration/email-verifications', ['email' => 'customer@example.test'], '192.0.2.6')->assertStatus(429);
        $this->assertDatabaseCount('registration_email_verifications', 1);
        Queue::assertPushed(SendRegistrationVerificationEmail::class, 5);
    }

    public function test_global_email_verify_budget_survives_resend_new_challenge_and_new_ip(): void
    {
        for ($generation = 0; $generation < 4; $generation++) {
            $row = $this->challenge('001234');
            for ($guess = 0; $guess < 5; $guess++) {
                $this->api($this->verifyPath($row->challenge_id), ['code' => '999999'], '192.0.2.'.($generation + 1))->assertUnprocessable();
            }
            $this->travel(61)->seconds();
        }
        $new = $this->challenge('001234');
        $this->api($this->verifyPath($new->challenge_id), ['code' => '001234'], '192.0.2.99')->assertStatus(429);
        $this->assertNull($new->fresh()->token_hash);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_signup_guess_budget_is_bound_to_mailbox_across_ip_changes(): void
    {
        $proof = $this->registrationProof();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->api('/api/register', $this->registration(str_repeat('a', 64)), '192.0.2.'.($attempt + 1))->assertUnprocessable();
        }
        $this->api('/api/register', $this->registration($proof), '192.0.2.99')->assertStatus(429);
        $this->assertNull(RegistrationEmailVerification::sole()->consumed_at);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_code_and_proof_query_strings_are_refused_to_avoid_url_secret_leaks(): void
    {
        $row = $this->challenge('001234');
        $this->api($this->verifyPath($row->challenge_id).'?code=001234', ['code' => '001234'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($row->fresh()->token_hash);
        $proof = $this->registrationProof();
        $this->api('/api/register?registration_token='.$proof, $this->registration($proof))->assertUnprocessable()->assertJsonValidationErrors('registration_token');
        $this->assertDatabaseCount('users', 0);
    }

    private function challenge(string $code): RegistrationEmailVerification
    {
        $requested = app(RegistrationEmailVerificationService::class)->request('customer@example.test');
        $row = RegistrationEmailVerification::where('challenge_id', $requested['challenge_id'])->sole();
        $row->forceFill([
            'code_hash' => hash_hmac('sha256', $row->challenge_id.'|'.$row->email.'|'.$code, app(Encrypter::class)->getKey()),
            'encrypted_code' => Crypt::encryptString($code),
        ])->save();

        return $row->refresh();
    }

    private function registration(?string $proof, string $email = 'customer@example.test'): array
    {
        return ['name' => 'Verified Customer', 'email' => $email, 'password' => 'correct-password',
            'password_confirmation' => 'correct-password', 'registration_token' => $proof];
    }

    private function verifyPath(string $challenge): string
    {
        return '/api/registration/email-verifications/'.$challenge.'/verify';
    }

    private function api(string $uri, array $data, string $ip = '127.0.0.1'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            json_encode((object) $data, JSON_THROW_ON_ERROR));
    }
}
