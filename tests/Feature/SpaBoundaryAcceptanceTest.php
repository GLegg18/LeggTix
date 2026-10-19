<?php

namespace Tests\Feature;

use App\Http\TrustedDeviceCookie;
use App\Models\User;
use App\Services\LoginEmailVerificationService;
use Illuminate\Auth\Events\Validated;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SpaBoundaryAcceptanceTest extends TestCase
{
    use DatabaseMigrations;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'sanctum.stateful' => ['localhost:3000'],
            'cors.allowed_origins' => ['http://localhost:3000'],
            'sanctum.middleware.validate_csrf_token' => EnforceBoundaryCsrf::class,
            'session.secure' => false,
        ]);
    }

    public function test_referer_fallback_requires_exact_origin_and_conflicting_headers_cannot_bypass_it(): void
    {
        $this->browser('GET', '/api/spa/user', headers: ['Referer' => 'http://localhost:3000/account'])
            ->assertUnauthorized();

        foreach ([
            ['Referer' => 'http://localhost:30000/account'],
            ['Referer' => 'http://localhost:3000.evil.example/account'],
            ['Referer' => 'https://localhost:3000/account'],
            ['Origin' => 'http://localhost:3000.evil.example', 'Referer' => 'http://localhost:3000/account'],
            ['Origin' => 'http://localhost:3000', 'Referer' => 'http://untrusted.example/account'],
        ] as $headers) {
            $this->browser('GET', '/api/spa/user', headers: $headers)->assertForbidden();
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('login_email_verifications', 0);
    }

    public function test_csrf_rejection_prevents_forget_browser_side_effects_and_session_stays_usable(): void
    {
        [$user] = $this->signedInBrowser();
        $device = DB::table('trusted_devices')->where('user_id', $user->id)->sole();
        $this->browser('DELETE', '/api/spa/trusted-devices', csrf: false)->assertStatus(419);
        $this->assertEquals($device, DB::table('trusted_devices')->where('id', $device->id)->sole());
        $this->browser('GET', '/api/spa/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->browser('DELETE', '/api/spa/trusted-devices')->assertNoContent();
        $this->assertNotNull(DB::table('trusted_devices')->where('id', $device->id)->value('revoked_at'));
        $this->browser('GET', '/api/spa/user')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_password_change_between_validation_and_lock_is_not_overwritten_by_rehash(): void
    {
        Hash::driver('bcrypt')->setRounds(8);
        $user = User::factory()->create();
        $oldHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        $newHash = password_hash('replacement-password', PASSWORD_BCRYPT, ['cost' => 8]);
        DB::table('users')->where('id', $user->id)->update(['password' => $oldHash]);
        $this->assertTrue(Hash::needsRehash($oldHash));
        $changed = false;
        Event::listen(Validated::class, function (Validated $event) use ($user, $newHash, &$changed): void {
            if ((int) $event->user->getAuthIdentifier() === $user->id) {
                // Model an independent credential change after old-password validation,
                // before the login transaction locks and compares the current row.
                DB::table('users')->where('id', $user->id)->update(['password' => $newHash]);
                $changed = true;
            }
        });

        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('POST', '/api/spa/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue($changed);
        $this->assertSame($newHash, DB::table('users')->where('id', $user->id)->value('password'));
        $this->assertDatabaseCount('login_email_verifications', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertGuest('web');
        $this->browser('GET', '/api/spa/user')->assertUnauthorized();
    }

    public function test_forget_browser_is_actor_scoped_ignores_foreign_id_and_preserves_existing_tokens(): void
    {
        [$actor] = $this->signedInBrowser();
        $other = User::factory()->create();
        $service = app(LoginEmailVerificationService::class);
        $challenge = $service->begin(['email' => $other->email, 'password' => 'password'], issueToken: false);
        $code = Crypt::decryptString(DB::table('login_email_verifications')->where('user_id', $other->id)->value('encrypted_code'));
        $service->verify($challenge->challengeId, $code, rememberDevice: true, issueToken: false);
        $otherDevice = DB::table('trusted_devices')->where('user_id', $other->id)->sole();
        $otherChallenge = DB::table('login_email_verifications')->where('user_id', $other->id)->sole();
        $actorToken = $actor->createToken('existing-api');
        $otherToken = $other->createToken('existing-api');

        $this->browser('DELETE', '/api/spa/trusted-devices', ['user_id' => $other->id])->assertNoContent();
        $this->assertNotNull(DB::table('trusted_devices')->where('user_id', $actor->id)->value('revoked_at'));
        $this->assertEquals($otherDevice, DB::table('trusted_devices')->where('id', $otherDevice->id)->sole());
        $this->assertEquals($otherChallenge, DB::table('login_email_verifications')->where('user_id', $other->id)->sole());
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->browser('GET', '/api/spa/user', headers: [
            'Origin' => 'http://localhost:3000', 'Authorization' => 'Bearer '.$otherToken->plainTextToken,
        ])->assertOk()->assertJsonPath('data.id', $actor->id);

        // End the browser session before testing bearer identity: Sanctum deliberately
        // prefers a first-party session, and Laravel's test app reuses its session store.
        $this->browser('POST', '/api/spa/logout')->assertNoContent();
        foreach ([$actorToken, $otherToken] as $token) {
            Auth::forgetGuards();
            $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token->plainTextToken])
                ->assertOk()->assertJsonPath('data.id', $token->accessToken->tokenable_id);
        }
    }

    private function signedInBrowser(): array
    {
        $user = User::factory()->create();
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $challenge = $this->browser('POST', '/api/spa/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(202);
        $code = Crypt::decryptString(DB::table('login_email_verifications')->where('user_id', $user->id)->value('encrypted_code'));
        $this->browser('POST', '/api/spa/login/email-verifications/'.$challenge->json('challenge_id').'/verify', [
            'code' => $code, 'remember_device' => true,
        ])->assertOk();
        $this->assertArrayHasKey(TrustedDeviceCookie::NAME, $this->cookies);

        return [$user];
    }

    private function browser(string $method, string $path, array $body = [], bool $csrf = true, array $headers = ['Origin' => 'http://localhost:3000']): TestResponse
    {
        Auth::forgetGuards();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if ($csrf && isset($this->cookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        $response = $this->call($method, $path, [], $this->cookies, [], $server, json_encode((object) $body));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}

class EnforceBoundaryCsrf extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
