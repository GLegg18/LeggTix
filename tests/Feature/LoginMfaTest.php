<?php

namespace Tests\Feature;

use App\Jobs\SendLoginVerificationEmail;
use App\Models\User;
use App\Services\AuthenticationFingerprint;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class LoginMfaTest extends TestCase
{
    use DatabaseMigrations;

    private const COOKIE = '__Host-leggtix_trusted_device';

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
        DB::purge('login_observer');
        parent::tearDown();
    }

    #[DataProvider('verificationHistory')]
    public function test_password_alone_returns_only_challenge_for_every_account_and_code_completes_login(bool $previouslyVerified): void
    {
        $user = User::factory()->create();
        if ($previouslyVerified) {
            $user->forceFill(['email_verified_at' => '2026-01-01 12:00:00'])->save();
        }
        $oldVerifiedAt = $user->email_verified_at;
        $challenge = $this->api('/api/login', ['email' => '  '.strtoupper($user->email).'  ', 'password' => 'password'])
            ->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['challenge_id', 'expires_at'], array_keys($challenge->json()));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $challenge->json('challenge_id'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $row = $this->row($challenge);
        $code = Crypt::decryptString($row->encrypted_code);
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $this->assertNotSame($code, $row->encrypted_code);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotSame($code, $row->code_hash);
        Queue::assertPushed(SendLoginVerificationEmail::class, function ($job) use ($row, $user, $code): bool {
            $this->assertSame($row->id, $job->verificationId);
            $this->assertSame($row->challenge_id, $job->challengeId);
            $this->assertSame('emails', $job->queue);
            $this->assertNull($job->connection);
            foreach ([$user->email, $user->password, $code, $row->encrypted_code, $row->code_hash, $row->credential_fingerprint] as $secret) {
                $this->assertStringNotContainsString($secret, serialize($job));
            }

            return true;
        });
        Queue::assertCount(1);
        $this->api('/api/user', [], method: 'GET', token: $row->challenge_id)->assertUnauthorized();

        $response = $this->api($this->verifyPath($row->challenge_id), ['code' => $code])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['user', 'access_token', 'token_type', 'expires_at'], array_keys($response->json()));
        $this->assertSame(['id', 'name', 'email', 'role'], array_keys($response->json('user')));
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('trusted_devices', 0);
        if ($this->cookie($response) !== null) {
            $this->assertSecureCookie($this->cookie($response));
            $this->assertLessThanOrEqual(time(), $this->cookie($response)->getExpiresTime());
        }
        $consumed = $this->row($challenge);
        $this->assertNotNull($consumed->consumed_at);
        $this->assertNull($consumed->code_hash);
        $this->assertNull($consumed->encrypted_code);
        $this->assertNotNull($user->fresh()->email_verified_at);
        if ($previouslyVerified) {
            $this->assertTrue($oldVerifiedAt->equalTo($user->fresh()->email_verified_at));
        }
        foreach ([$code, $user->password, $row->credential_fingerprint] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->api('/api/user', [], method: 'GET', token: $response->json('access_token'))
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->api($this->verifyPath($row->challenge_id), ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public static function verificationHistory(): array
    {
        return [['previouslyVerified' => false], ['previouslyVerified' => true]];
    }

    public function test_password_cost_upgrade_preserves_the_bearer_email_factor_and_response_contract(): void
    {
        config(['hashing.rehash_on_login' => true]);
        Hash::driver('bcrypt')->setRounds(4);
        $user = User::factory()->create();
        $oldHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        DB::table('users')->where('id', $user->id)->update(['password' => $oldHash]);
        Hash::driver('bcrypt')->setRounds(8);

        [, $challenge, $code] = $this->challenge($user);
        $this->assertSame(['challenge_id', 'expires_at'], array_keys($challenge->json()));
        $current = $user->fresh();
        $this->assertNotSame($oldHash, $current->password);
        $this->assertTrue(Hash::check('password', $current->password));
        $this->assertSame(8, password_get_info($current->password)['options']['cost']);
        $this->assertFalse(Hash::needsRehash($current->password));
        $this->assertSame(app(AuthenticationFingerprint::class)->forUser($current), $this->row($challenge)->credential_fingerprint);
        $this->assertGuest('web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->api('/api/user', [], method: 'GET')->assertUnauthorized();

        $response = $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code])->assertOk();
        $this->assertSame(['user', 'access_token', 'token_type', 'expires_at'], array_keys($response->json()));
        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->api('/api/user', [], method: 'GET', token: $response->json('access_token'))
            ->assertOk()->assertJsonPath('data.id', $user->id);
    }

    #[DataProvider('tokenWriteFailures')]
    public function test_password_rehash_save_failure_leaves_credentials_and_authentication_side_effects_unchanged(bool $veto): void
    {
        config(['hashing.rehash_on_login' => true]);
        Hash::driver('bcrypt')->setRounds(4);
        $user = User::factory()->create();
        $oldHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        DB::table('users')->where('id', $user->id)->update(['password' => $oldHash]);
        Hash::driver('bcrypt')->setRounds(8);
        $attempted = false;
        Event::listen('eloquent.saving: '.User::class, static function (User $saving) use ($user, $veto, &$attempted): bool {
            if ($saving->id === $user->id && $saving->isDirty('password')) {
                $attempted = true;
                if ($veto) {
                    return false;
                }
                throw new RuntimeException('Injected password rehash write failure');
            }

            return true;
        });

        $this->api('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(500);
        $this->assertTrue($attempted);
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertGuest('web');
        $this->assertDatabaseCount('login_email_verifications', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
        Queue::assertNothingPushed();

        Event::forget('eloquent.saving: '.User::class);
        [, $challenge, $code] = $this->challenge($user);
        $this->assertFalse(Hash::needsRehash($user->fresh()->password));
        $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    #[DataProvider('malformedCodes')]
    public function test_invalid_code_input_cannot_issue_tokens_or_trust(mixed $code): void
    {
        [$user, $challenge] = $this->challenge();
        $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code, 'remember_device' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
        $this->assertSame(0, $this->row($challenge)->attempts);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public static function malformedCodes(): array
    {
        return [[null], [123456], ['12345'], ['1234567'], ['abcdef'], [['123456']]];
    }

    public function test_leading_zero_code_is_preserved_as_six_digit_string(): void
    {
        [, $challenge] = $this->challenge();
        $row = $this->row($challenge);
        $code = '001234';
        DB::table('login_email_verifications')->where('id', $row->id)->update([
            'code_hash' => hash_hmac('sha256', $row->challenge_id.'|'.$row->credential_fingerprint.'|'.$code,
                app(\Illuminate\Contracts\Encryption\Encrypter::class)->getKey()),
            'encrypted_code' => Crypt::encryptString($code),
        ]);
        $this->api($this->verifyPath($row->challenge_id), ['code' => $code])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_failed_guesses_commit_before_422_and_five_disable_code(): void
    {
        [, $challenge, $correct] = $this->challenge();
        $wrong = $correct === '999999' ? '999998' : '999999';
        config(['database.connections.login_observer' => config('database.connections.mysql')]);
        $observer = DB::connection('login_observer');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $wrong], ip: '192.0.2.'.$attempt)
                ->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertSame($attempt, $observer->table('login_email_verifications')->value('attempts'));
        }
        $this->assertNull($this->row($challenge)->code_hash);
        $this->assertNull($this->row($challenge)->encrypted_code);
        $this->travel(61)->seconds();
        $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $correct])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_expired_unknown_and_consumed_codes_have_generic_validation_errors(): void
    {
        [, $challenge, $code] = $this->challenge();
        DB::table('login_email_verifications')->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        $expired = $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code])->assertUnprocessable();
        $unknown = $this->api($this->verifyPath(str_repeat('a', 64)), ['code' => $code])->assertUnprocessable();
        $this->assertSame($unknown->json(), $expired->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_resend_rechecks_password_rotates_code_and_enforces_real_database_cooldown(): void
    {
        [$user, $first, $code] = $this->challenge();
        $firstRow = $this->row($first);
        $this->api('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertEquals($firstRow, $this->row($first));
        $this->api('/api/login', ['email' => $user->email, 'password' => 'password'], ip: '192.0.2.2')->assertStatus(429)->assertHeader('Retry-After');
        $this->expireIssuanceCooldown($user);
        $second = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(202);
        $this->assertNotSame($first->json('challenge_id'), $second->json('challenge_id'));
        $this->assertSame($firstRow->id, $this->row($second)->id);
        $this->api($this->verifyPath($first->json('challenge_id')), ['code' => $code])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Queue::assertPushed(SendLoginVerificationEmail::class, 2);
    }

    #[DataProvider('changedCredentials')]
    public function test_pending_code_is_invalid_after_credentials_change_or_account_deletion(string $change): void
    {
        [$user, $challenge, $code] = $this->challenge();
        $this->changeCredentials($user, $change);
        $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code, 'remember_device' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public static function changedCredentials(): array
    {
        return [['password'], ['email'], ['deleted']];
    }

    #[DataProvider('tokenWriteFailures')]
    public function test_token_write_failure_rolls_back_code_consumption_trust_and_verification_marker(bool $veto): void
    {
        [$user, $challenge, $code] = $this->challenge();
        $before = $this->row($challenge);
        Event::listen('eloquent.creating: '.PersonalAccessToken::class, static function () use ($veto): bool {
            if ($veto) {
                return false;
            }
            throw new RuntimeException('Injected login token write failure');
        });
        $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code, 'remember_device' => true])->assertStatus(500);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertEquals($before, $this->row($challenge));
        Event::forget('eloquent.creating: '.PersonalAccessToken::class);
        $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code, 'remember_device' => true])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('trusted_devices', 1);
    }

    #[DataProvider('tokenWriteFailures')]
    public function test_token_write_failure_rolls_back_trusted_rotation_so_old_cookie_can_retry(bool $veto): void
    {
        [$user, , $cookie] = $this->trustedLogin();
        $before = DB::table('trusted_devices')->sole();
        Event::listen('eloquent.creating: '.PersonalAccessToken::class, static function () use ($veto): bool {
            if ($veto) {
                return false;
            }
            throw new RuntimeException('Injected trusted-login token write failure');
        });
        $this->api('/api/login', ['email' => $user->email, 'password' => 'password'], cookie: $cookie)->assertStatus(500);
        $this->assertEquals($before, DB::table('trusted_devices')->sole());
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Event::forget('eloquent.creating: '.PersonalAccessToken::class);
        $response = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'], cookie: $cookie)->assertOk();
        $this->assertNotSame($cookie, $this->cookie($response)->getValue());
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public static function tokenWriteFailures(): array
    {
        return [['veto' => false], ['veto' => true]];
    }

    public function test_required_login_email_enqueue_failure_is_sanitized_503_without_token(): void
    {
        $user = User::factory()->create();
        Queue::shouldReceive('connection')->once()->withNoArgs()->andThrow(new RuntimeException('redis://private:secret@host'));
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame(['email_type', 'reference_id', 'exception_type'], array_keys($context));
            $this->assertStringNotContainsString('private', $message.json_encode($context));
            $this->assertStringNotContainsString('secret', $message.json_encode($context));

            return true;
        })->andThrow(new RuntimeException('Injected logger failure'));
        $response = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(503)->assertHeader('Retry-After', '1')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
        $this->assertDatabaseCount('login_email_verifications', 1);
        Queue::clearResolvedInstance('queue');
        $this->app->forgetInstance('queue');
        Queue::fake();
        $this->expireIssuanceCooldown($user);
        $fresh = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(202);
        Queue::assertPushed(SendLoginVerificationEmail::class, 1);
        $this->api($this->verifyPath($fresh->json('challenge_id')), ['code' => Crypt::decryptString($this->row($fresh)->encrypted_code)])->assertOk();
    }

    public function test_login_email_waits_for_real_outer_commit_and_rollback_discards_it(): void
    {
        $user = User::factory()->create();
        $this->assertSame(DatabaseTransactionsManager::class, get_class(app('db.transactions')));
        DB::beginTransaction();
        DB::beginTransaction();
        $this->api('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(202);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SendLoginVerificationEmail::class, 1);
        Queue::fake();
        $other = User::factory()->create();
        DB::beginTransaction();
        $this->api('/api/login', ['email' => $other->email, 'password' => 'password'])->assertStatus(202);
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('login_email_verifications', ['user_id' => $other->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_trust_is_opt_in_only_after_code_and_cookie_has_secure_host_only_attributes(): void
    {
        $user = User::factory()->create();
        $this->api('/api/login', ['email' => $user->email, 'password' => 'password', 'remember_device' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('remember_device');
        [, $challenge, $code] = $this->challenge($user);
        $response = $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code, 'remember_device' => true])->assertOk();
        $cookie = $this->cookie($response);
        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\.[a-f0-9]{64}\z/', $cookie->getValue());
        $this->assertSecureCookie($cookie);
        [$selector, $validator] = explode('.', $cookie->getValue(), 2);
        $device = DB::table('trusted_devices')->sole();
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame($selector, $device->selector);
        $this->assertSame(hash('sha256', $validator), $device->validator_hash);
        $this->assertStringNotContainsString($cookie->getValue(), $response->getContent());
        $this->assertStringNotContainsString($validator, json_encode($device));
        $seconds = (float) DB::scalar('SELECT TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(6), expires_at) / 1000000 FROM trusted_devices');
        $this->assertGreaterThan(604790, $seconds);
        $this->assertLessThanOrEqual(604800, $seconds);
        $this->assertSame(strtotime($device->expires_at.' UTC'), $cookie->getExpiresTime());
    }

    public function test_valid_trust_still_requires_password_and_rotates_validator_without_extending_absolute_expiry(): void
    {
        [$user, $response, $oldCookie] = $this->trustedLogin();
        $old = DB::table('trusted_devices')->sole();
        $this->api('/api/login', [], cookie: $oldCookie)->assertUnprocessable();
        $this->api('/api/user', [], method: 'GET', cookie: $oldCookie)->assertUnauthorized();
        $this->api('/api/login', ['email' => $user->email, 'password' => 'wrong'], cookie: $oldCookie)->assertUnprocessable();
        $this->assertEquals($old, DB::table('trusted_devices')->sole());
        $this->assertDatabaseCount('personal_access_tokens', 1);
        // Simulate an already-aged trust window in this isolated database. A sliding implementation would extend it.
        DB::table('trusted_devices')->where('id', $old->id)->update([
            'created_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY)'),
            'expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 6 DAY)'),
        ]);
        $agedExpiry = DB::table('trusted_devices')->value('expires_at');
        $next = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'], cookie: $oldCookie)->assertOk();
        $rotated = $this->cookie($next);
        $this->assertNotSame($oldCookie, $rotated->getValue());
        $this->assertSame(explode('.', $oldCookie)[0], explode('.', $rotated->getValue())[0]);
        $this->assertSame($agedExpiry, DB::table('trusted_devices')->value('expires_at'));
        $this->assertSame(strtotime($agedExpiry.' UTC'), $rotated->getExpiresTime());
        $this->assertSecureCookie($rotated);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseCount('trusted_devices', 1);
        Queue::assertPushed(SendLoginVerificationEmail::class, 1);
    }

    public function test_password_cost_upgrade_requires_a_new_email_factor_for_previously_trusted_browser(): void
    {
        config(['hashing.rehash_on_login' => true]);
        Hash::driver('bcrypt')->setRounds(4);
        [$user, , $oldCookie] = $this->trustedLogin();
        $oldDevice = DB::table('trusted_devices')->where('user_id', $user->id)->sole();
        $oldHash = $user->fresh()->password;
        Hash::driver('bcrypt')->setRounds(8);
        $this->expireIssuanceCooldown($user);

        $challenge = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'], cookie: $oldCookie)
            ->assertStatus(202)->assertJsonMissingPath('access_token');
        $current = $user->fresh();
        $this->assertNotSame($oldHash, $current->password);
        $this->assertTrue(Hash::check('password', $current->password));
        $this->assertFalse(Hash::needsRehash($current->password));
        $this->assertNotSame($oldDevice->credential_fingerprint, app(AuthenticationFingerprint::class)->forUser($current));
        $this->assertSame(app(AuthenticationFingerprint::class)->forUser($current), $this->row($challenge)->credential_fingerprint);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertGuest('web');
        $this->assertLessThanOrEqual(time(), $this->cookie($challenge)->getExpiresTime());

        $code = Crypt::decryptString($this->row($challenge)->encrypted_code);
        $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code], cookie: $oldCookie)->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertNotNull(DB::table('trusted_devices')->where('id', $oldDevice->id)->value('revoked_at'));
        Queue::assertPushed(SendLoginVerificationEmail::class, 2);
    }

    #[DataProvider('invalidTrust')]
    public function test_unusable_trust_falls_back_to_code_and_clears_cookie_without_issuing_token(string $state): void
    {
        [$user, , $cookie] = $this->trustedLogin();
        $credentials = ['email' => $user->email, 'password' => 'password'];
        if ($state === 'expired') {
            DB::table('trusted_devices')->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        } elseif ($state === 'revoked') {
            DB::table('trusted_devices')->update(['revoked_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        } elseif ($state === 'password') {
            $user->forceFill(['password' => Hash::make('new-password')])->save();
            $credentials['password'] = 'new-password';
        } elseif ($state === 'email') {
            $user->forceFill(['email' => 'changed@example.test'])->save();
            $credentials['email'] = 'changed@example.test';
        } elseif ($state === 'other user') {
            $other = User::factory()->create();
            $credentials['email'] = $other->email;
        } elseif ($state === 'wrong validator') {
            $cookie = explode('.', $cookie)[0].'.'.str_repeat('a', 64);
        } elseif ($state === 'oversized') {
            $cookie = str_repeat('a', 10000);
        } else {
            $cookie = 'invalid-cookie';
        }
        $this->expireIssuanceCooldown($user);
        $response = $this->api('/api/login', $credentials, cookie: $cookie)->assertStatus(202)->assertJsonMissingPath('access_token');
        $clear = $this->cookie($response);
        $this->assertInstanceOf(Cookie::class, $clear);
        $this->assertSecureCookie($clear);
        $this->assertLessThanOrEqual(time(), $clear->getExpiresTime());
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('trusted_devices', 1);
        if ($state === 'other user') {
            $this->assertNull(DB::table('trusted_devices')->value('revoked_at'), 'A foreign cookie must not mutate another owner\'s trust.');
        }
    }

    public static function invalidTrust(): array
    {
        return [['expired'], ['revoked'], ['password'], ['email'], ['other user'], ['wrong validator'], ['oversized'], ['malformed']];
    }

    public function test_logout_revokes_same_owner_device_family_even_with_pre_rotation_cookie_and_only_current_api_token(): void
    {
        [$user, $first, $oldCookie] = $this->trustedLogin();
        $second = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'], cookie: $oldCookie)->assertOk();
        $this->assertNotSame($oldCookie, $this->cookie($second)->getValue());
        $logout = $this->api('/api/logout', [], token: $first->json('access_token'), cookie: $oldCookie)->assertNoContent();
        $this->assertNotNull(DB::table('trusted_devices')->value('revoked_at'));
        $this->assertSecureCookie($this->cookie($logout));
        $this->assertLessThanOrEqual(time(), $this->cookie($logout)->getExpiresTime());
        $this->api('/api/user', [], method: 'GET', token: $first->json('access_token'))->assertUnauthorized();
        $this->api('/api/user', [], method: 'GET', token: $second->json('access_token'))->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_logout_and_revoke_all_are_authenticated_and_cannot_touch_other_owners_devices(): void
    {
        [$a, $aLogin, $aCookie] = $this->trustedLogin();
        [$b, $bLogin, $bCookie] = $this->trustedLogin();
        $this->api('/api/trusted-devices', [], method: 'DELETE')->assertUnauthorized();
        $this->api('/api/logout', [], token: $aLogin->json('access_token'), cookie: $bCookie)->assertNoContent();
        $this->assertNull(DB::table('trusted_devices')->where('user_id', $b->id)->value('revoked_at'));
        $this->assertNull(DB::table('trusted_devices')->where('user_id', $a->id)->value('revoked_at'));
        $aToken = $a->createToken('authenticated-revoke-all')->plainTextToken;
        $this->expireIssuanceCooldown($a);
        $pending = $this->api('/api/login', ['email' => $a->email, 'password' => 'password'])->assertStatus(202);
        $pendingCode = Crypt::decryptString($this->row($pending)->encrypted_code);
        $response = $this->api('/api/trusted-devices?user_id='.$b->id, ['user_id' => $b->id], method: 'DELETE', token: $aToken, cookie: $bCookie)
            ->assertNoContent();
        $this->assertSecureCookie($this->cookie($response));
        $this->assertNotNull(DB::table('trusted_devices')->where('user_id', $a->id)->value('revoked_at'));
        $this->assertNull(DB::table('trusted_devices')->where('user_id', $b->id)->value('revoked_at'));
        $this->api($this->verifyPath($pending->json('challenge_id')), ['code' => $pendingCode, 'remember_device' => true])->assertUnprocessable();
        $this->assertSame(1, DB::table('trusted_devices')->where('user_id', $a->id)->count());
        $this->api('/api/user', [], method: 'GET', token: $aToken)->assertOk();
        $this->api('/api/user', [], method: 'GET', token: $bLogin->json('access_token'))->assertOk();
    }

    public function test_existing_valid_bearer_tokens_remain_usable_without_silently_enabling_browser_trust(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->createToken('api', ['*'], now()->addDay())->plainTextToken;
        $this->api('/api/user', [], method: 'GET', token: $oldToken)->assertOk();
        $this->assertDatabaseCount('trusted_devices', 0);
        Queue::assertNothingPushed();
    }

    public function test_code_guess_budget_survives_new_challenges_and_ips(): void
    {
        $user = User::factory()->create();
        for ($generation = 0; $generation < 4; $generation++) {
            [, $challenge, $correct] = $this->challenge($user, ip: '192.0.2.'.($generation + 1));
            $wrong = $correct === '999999' ? '999998' : '999999';
            for ($guess = 0; $guess < 5; $guess++) {
                $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $wrong], ip: '192.0.2.'.($guess + 10))->assertUnprocessable();
            }
            $this->expireIssuanceCooldown($user);
            $this->travel(61)->seconds();
        }
        [, $fresh, $correct] = $this->challenge($user, ip: '192.0.2.99');
        $this->api($this->verifyPath($fresh->json('challenge_id')), ['code' => $correct], ip: '192.0.2.100')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_guess_account_budget_cannot_be_reset_by_changing_ip_or_mysql_equivalent_spelling(): void
    {
        User::factory()->create(['email' => 'john@example.test']);
        foreach (['john@example.test', 'jöhn@example.test', 'JOHN@example.test', ' JÖHN@EXAMPLE.TEST ', 'john@example.test'] as $index => $email) {
            $this->api('/api/login', ['email' => $email, 'password' => 'wrong'], ip: '192.0.2.'.($index + 1))->assertUnprocessable();
        }
        $this->api('/api/login', ['email' => 'john@example.test', 'password' => 'password'], ip: '192.0.2.99')->assertStatus(429);
        $this->assertDatabaseCount('login_email_verifications', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_json_only_login_and_verify_reject_query_secrets_and_unknown_fields(): void
    {
        [$user, $challenge, $code] = $this->challenge();
        $this->api('/api/login?password=password', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $path = $this->verifyPath($challenge->json('challenge_id'));
        $this->api($path.'?code='.$code, ['code' => $code])->assertUnprocessable();
        $this->api($path, ['code' => $code, 'user_id' => $user->id])->assertUnprocessable();
        $this->api($path, ['code' => $code, 'remember_device' => ['true']])->assertUnprocessable();
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')->assertStatus(400);
        $this->call('POST', '/api/login', ['email' => $user->email, 'password' => 'password'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'])->assertStatus(415);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_array_valued_cookie_fails_closed_without_a_type_error_or_foreign_device_revocation(): void
    {
        [, , $otherCookie] = $this->trustedLogin();
        $user = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $response = $this->call('POST', '/api/login', [], [self::COOKIE => ['credential' => $otherCookie]], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => $user->email, 'password' => 'password'], JSON_THROW_ON_ERROR));
        $response->assertStatus(202)->assertJsonMissingPath('access_token');
        $this->assertNull(DB::table('trusted_devices')->value('revoked_at'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    private function challenge(?User $user = null, string $ip = '127.0.0.1'): array
    {
        $user ??= User::factory()->create();
        $response = $this->api('/api/login', ['email' => $user->email, 'password' => 'password'], ip: $ip)->assertStatus(202);

        return [$user, $response, Crypt::decryptString($this->row($response)->encrypted_code)];
    }

    private function trustedLogin(): array
    {
        [$user, $challenge, $code] = $this->challenge();
        $response = $this->api($this->verifyPath($challenge->json('challenge_id')), ['code' => $code, 'remember_device' => true])->assertOk();

        return [$user, $response, $this->cookie($response)->getValue()];
    }

    private function row(TestResponse $challenge): object
    {
        return DB::table('login_email_verifications')->where('challenge_id', $challenge->json('challenge_id'))->sole();
    }

    private function expireIssuanceCooldown(User $user): void
    {
        // Explicit isolated-fixture aging, never a production/testing bypass of the DB clock.
        DB::table('login_email_verifications')->where('user_id', $user->id)->update(['issued_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 61 SECOND)')]);
    }

    private function changeCredentials(User $user, string $change): void
    {
        if ($change === 'deleted') {
            DB::table('login_email_verifications')->where('user_id', $user->id)->delete();
            DB::table('trusted_devices')->where('user_id', $user->id)->delete();
            $user->delete();
        } else {
            $user->forceFill([$change => $change === 'password' ? Hash::make('changed-password') : 'changed@example.test'])->save();
        }
    }

    private function cookie(TestResponse $response): ?Cookie
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::COOKIE) {
                return $cookie;
            }
        }

        return null;
    }

    private function assertSecureCookie(Cookie $cookie): void
    {
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
    }

    private function verifyPath(string $challenge): string
    {
        return '/api/login/email-verifications/'.$challenge.'/verify';
    }

    private function api(string $uri, array $body, string $method = 'POST', ?string $token = null, ?string $cookie = null, string $ip = '127.0.0.1'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call($method, $uri, [], $cookie === null ? [] : [self::COOKIE => $cookie], [], $server,
            json_encode((object) $body, JSON_THROW_ON_ERROR));
    }
}
