<?php

namespace Tests\Feature;

use App\Http\TrustedDeviceCookie;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SpaAuthenticationTest extends TestCase
{
    use DatabaseMigrations;

    private array $browserCookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'sanctum.stateful' => ['localhost:3000'],
            'cors.allowed_origins' => ['http://localhost:3000'],
            'sanctum.middleware.validate_csrf_token' => EnforceSpaCsrfToken::class,
            'session.secure' => false,
        ]);
    }

    public function test_password_alone_never_authenticates_and_email_factor_establishes_session_without_token(): void
    {
        $user = User::factory()->create();
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $sessionBefore = session()->getId();
        $challenge = $this->browser('POST', '/api/spa/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['challenge_id', 'expires_at'], array_keys($challenge->json()));
        $this->assertGuest('web');
        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->browser('GET', '/api/spa/user')->assertUnauthorized();
        $this->browser('GET', '/api/user')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $row = DB::table('login_email_verifications')->sole();
        $code = Crypt::decryptString($row->encrypted_code);
        $wrong = $code === '999999' ? '999998' : '999999';
        $path = '/api/spa/login/email-verifications/'.$challenge->json('challenge_id').'/verify';
        $this->browser('POST', $path, ['code' => $wrong])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, DB::table('login_email_verifications')->value('attempts'));
        $this->browser('GET', '/api/spa/user')->assertUnauthorized();

        $response = $this->browser('POST', $path, ['code' => $code])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame(['user'], array_keys($response->json()));
        $this->assertSame(['id', 'name', 'email', 'role'], array_keys($response->json('user')));
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->assertNotNull(DB::table('login_email_verifications')->value('consumed_at'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->browser('GET', '/api/spa/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->browser('POST', $path, ['code' => $code])->assertUnprocessable();
    }

    public function test_email_ownership_signup_uses_existing_proof_rules_without_issuing_token(): void
    {
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $attributes = [
            'name' => 'Alex Example', 'email' => 'alex@example.test',
            'password' => 'example-password', 'password_confirmation' => 'example-password',
            'registration_token' => str_repeat('a', 64),
        ];
        $this->browser('POST', '/api/spa/register', $attributes)->assertUnprocessable()->assertJsonValidationErrors('registration_token');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');

        $challenge = $this->browser('POST', '/api/spa/registration/email-verifications', ['email' => $attributes['email']])->assertStatus(202);
        $code = Crypt::decryptString(DB::table('registration_email_verifications')->value('encrypted_code'));
        $proof = $this->browser('POST', '/api/spa/registration/email-verifications/'.$challenge->json('challenge_id').'/verify', ['code' => $code])
            ->assertOk()->json('registration_token');
        $attributes['registration_token'] = $proof;
        $response = $this->browser('POST', '/api/spa/register', $attributes)->assertCreated()->assertJsonPath('user.role', 'regular_user');
        $this->assertSame(['user'], array_keys($response->json()));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotNull(User::query()->sole()->email_verified_at);
        $this->assertNotNull(DB::table('registration_email_verifications')->value('consumed_at'));
        $this->browser('GET', '/api/spa/user')->assertOk()->assertJsonPath('data.email', $attributes['email']);
        $this->browser('POST', '/api/spa/register', $attributes)->assertUnprocessable();
    }

    public function test_spa_writes_require_csrf_and_explicit_origin_while_bearer_calls_remain_stateless(): void
    {
        $user = User::factory()->create();
        $credentials = ['email' => $user->email, 'password' => 'password'];
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->browser('POST', '/api/spa/login', $credentials, csrf: false)->assertStatus(419);
        $this->assertDatabaseCount('login_email_verifications', 0);
        $this->browser('GET', '/api/spa/user', origin: 'https://localhost:3000')->assertForbidden();
        $this->browser('GET', '/api/spa/user', origin: 'http://untrusted.example')->assertForbidden();
        $this->browser('GET', '/api/spa/user', origin: null)->assertForbidden();

        $token = $user->createToken('existing-api')->plainTextToken;
        Auth::forgetGuards();
        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data.id', $user->id);
        Auth::forgetGuards();
        $this->getJson('/api/spa/user', ['Origin' => 'http://localhost:3000', 'Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->browser('POST', '/api/spa/login', $credentials)->assertStatus(202);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_only_configured_origin_receives_credentialed_cors_and_retry_header(): void
    {
        foreach (['http://localhost:3000' => true, 'http://untrusted.example' => false] as $origin => $allowed) {
            $response = $this->options('/api/spa/login', [], [
                'Origin' => $origin, 'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
            ])->assertNoContent();
            if ($allowed) {
                $response->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Access-Control-Allow-Credentials', 'true');
            } else {
                // Fruitcake safely emits the single configured origin even for other callers.
                $this->assertNotSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
                $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
            }
        }
        $this->browser('GET', '/sanctum/csrf-cookie')->assertHeader('Access-Control-Expose-Headers', 'Retry-After');
    }

    public function test_logout_invalidates_session_revokes_presented_trust_and_preserves_existing_api_tokens(): void
    {
        [$user, $response] = $this->login(remember: true);
        $token = $user->createToken('existing-api')->plainTextToken;
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === TrustedDeviceCookie::NAME);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $sessionBefore = session()->getId();
        $this->browser('POST', '/api/spa/logout')->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->assertNotNull(DB::table('trusted_devices')->value('revoked_at'));
        $this->browser('GET', '/api/spa/user')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Auth::forgetGuards();
        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_shared_api_logout_accepts_first_party_session_without_transient_token_error(): void
    {
        $this->login();
        $this->browser('POST', '/api/logout')->assertNoContent();
        $this->browser('GET', '/api/spa/user')->assertUnauthorized();
    }

    public function test_trusted_password_login_rotates_cookie_without_creating_api_token(): void
    {
        [$user] = $this->login(remember: true);
        $trustedCookie = $this->browserCookies[TrustedDeviceCookie::NAME];
        $device = DB::table('trusted_devices')->sole();
        $this->browserCookies = [TrustedDeviceCookie::NAME => $trustedCookie];
        session()->flush();
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('GET', '/api/spa/user')->assertUnauthorized();
        $this->browser('POST', '/api/spa/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertExactJson(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'regular_user']]);
        $rotated = DB::table('trusted_devices')->sole();
        $this->assertNotSame($device->validator_hash, $rotated->validator_hash);
        $this->assertSame($device->expires_at, $rotated->expires_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->browser('GET', '/api/spa/user')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_password_change_invalidates_existing_browser_session(): void
    {
        [$user] = $this->login();
        $user->forceFill(['password' => 'different-password'])->save();
        $this->browser('GET', '/api/spa/user')->assertUnauthorized();
    }

    public function test_failed_verification_database_write_rolls_back_consumption_and_can_retry(): void
    {
        $user = User::factory()->create();
        $this->browser('GET', '/sanctum/csrf-cookie');
        $challenge = $this->browser('POST', '/api/spa/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(202);
        $row = DB::table('login_email_verifications')->sole();
        $code = Crypt::decryptString($row->encrypted_code);
        $path = '/api/spa/login/email-verifications/'.$challenge->json('challenge_id').'/verify';
        Event::listen('eloquent.saving: '.User::class, static fn () => false);
        $this->browser('POST', $path, ['code' => $code])->assertStatus(500);
        $this->assertEquals($row, DB::table('login_email_verifications')->sole());
        $this->assertGuest('web');
        Event::forget('eloquent.saving: '.User::class);
        $this->browser('POST', $path, ['code' => $code])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function login(bool $remember = false): array
    {
        $user = User::factory()->create();
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $challenge = $this->browser('POST', '/api/spa/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(202);
        $code = Crypt::decryptString(DB::table('login_email_verifications')->where('user_id', $user->id)->value('encrypted_code'));
        $response = $this->browser('POST', '/api/spa/login/email-verifications/'.$challenge->json('challenge_id').'/verify', [
            'code' => $code, 'remember_device' => $remember,
        ])->assertOk();

        return [$user, $response];
    }

    private function browser(string $method, string $path, array $data = [], bool $csrf = true, ?string $origin = 'http://localhost:3000'): TestResponse
    {
        Auth::forgetGuards();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }
        if ($csrf && isset($this->browserCookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->browserCookies['XSRF-TOKEN'];
        }
        $response = $this->call($method, $path, [], $this->browserCookies, [], $server, json_encode((object) $data));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
                unset($this->browserCookies[$cookie->getName()]);
            } else {
                $this->browserCookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}

// Exercise real CSRF validation instead of Laravel's automatic PHPUnit bypass.
class EnforceSpaCsrfToken extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
