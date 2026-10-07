<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $changes = []): array
    {
        return array_replace([
            'name' => 'Test Customer',
            'email' => 'customer@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ], $changes);
    }

    /** Use ordinary JSON clients without an Accept header or a fake auth guard. */
    private function api(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        // The test kernel is reused; real HTTP requests receive fresh guards.
        $this->app['auth']->forgetGuards();
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call($method, $uri, [], [], [], $server, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_registration_uses_normalized_email_and_returns_only_safe_fields_and_a_hashed_expiring_token(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'));

        $response = $this->api('POST', '/api/register', $this->registration([
            'email' => '  Customer@Example.TEST  ',
            'id' => 900,
            'created_at' => '1999-01-01',
            'email_verified_at' => '1999-01-01',
            'remember_token' => 'client-controlled',
            'owner_id' => 900,
        ]));

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $user = User::sole();
        $response->assertExactJson([
            'user' => ['id' => $user->id, 'name' => 'Test Customer', 'email' => 'customer@example.test', 'role' => 'regular_user'],
            'access_token' => $response->json('access_token'),
            'token_type' => 'Bearer',
            'expires_at' => '2026-10-08T12:00:00.000000Z',
        ]);
        $this->assertNotSame(900, $user->id);
        $this->assertTrue(Hash::check('correct-password', $user->password));
        $this->assertNotSame('correct-password', $user->password);
        $this->assertSame('2026-10-07 12:00:00', $user->created_at->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $storedToken = PersonalAccessToken::sole();
        [, $secret] = explode('|', $response->json('access_token'), 2);
        $this->assertSame(hash('sha256', $secret), $storedToken->token);
        $this->assertSame(['*'], $storedToken->abilities);
        $this->assertSame($user->id, $storedToken->tokenable_id);
        $this->assertSame(User::class, $storedToken->tokenable_type);
        $this->assertTrue($storedToken->expires_at->equalTo(now()->addDay()));
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertStringNotContainsString($storedToken->token, $response->getContent());
    }

    public function test_lifecycle_revokes_only_the_current_client_and_returns_the_authenticated_users_identity(): void
    {
        $tokenA = $this->api('POST', '/api/register', $this->registration())->assertCreated()->json('access_token');
        $tokenB = $this->api('POST', '/api/login', ['email' => '  CUSTOMER@EXAMPLE.TEST ', 'password' => 'correct-password'])
            ->assertOk()->json('access_token');
        $other = User::factory()->create();
        $this->assertNotSame($tokenA, $tokenB);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->api('GET', '/api/user?user_id='.$other->id, [], $tokenA)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'id' => User::where('email', 'customer@example.test')->value('id'),
                'name' => 'Test Customer', 'email' => 'customer@example.test', 'role' => 'regular_user',
            ]]);
        $this->api('POST', '/api/logout', ['user_id' => $other->id, 'token_id' => PersonalAccessToken::latest('id')->value('id')], $tokenA)
            ->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->api('GET', '/api/user', [], $tokenA)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->api('POST', '/api/logout', [], $tokenA)->assertUnauthorized();
        $this->api('GET', '/api/user', [], $tokenB)->assertOk()->assertJsonPath('data.email', 'customer@example.test');
        $this->api('POST', '/api/logout', [], $tokenB)->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('roles')]
    public function test_every_existing_role_can_login_and_read_their_identity_without_role_change(string $role): void
    {
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        $token = $this->api('POST', '/api/login', ['email' => $user->email, 'password' => 'password', 'role' => 'admin'])
            ->assertOk()->assertJsonPath('user.role', $role)->json('access_token');

        $this->api('GET', '/api/user', [], $token)->assertOk()->assertJsonPath('data.role', $role);
        $this->assertSame($role, $user->fresh()->role->value);
    }

    public static function roles(): array
    {
        return array_map(fn (string $role): array => [$role], ['regular_user', 'organiser', 'admin']);
    }

    #[DataProvider('privilegedRegistrationRoles')]
    public function test_public_registration_rejects_any_nonempty_role_assignment(string $role): void
    {
        $this->api('POST', '/api/register', $this->registration(['role' => $role]))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function privilegedRegistrationRoles(): array
    {
        return [['admin'], ['organiser'], ['regular_user'], ['ADMIN']];
    }

    #[DataProvider('invalidRegistration')]
    public function test_registration_validates_missing_and_malformed_fields_without_writes(array $changes, array $errors): void
    {
        $this->api('POST', '/api/register', $this->registration($changes))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidRegistration(): array
    {
        return [
            'name missing' => [['name' => null], ['name']],
            'name blank' => [['name' => '   '], ['name']],
            'name array' => [['name' => ['customer']], ['name']],
            'name too long' => [['name' => str_repeat('a', 256)], ['name']],
            'email missing' => [['email' => null], ['email']],
            'email malformed' => [['email' => 'invalid'], ['email']],
            'email array' => [['email' => ['customer@example.test']], ['email']],
            'email too long' => [['email' => str_repeat('x', 245).'@example.test'], ['email']],
            'password missing' => [['password' => null], ['password']],
            'password array' => [['password' => ['correct-password']], ['password']],
            'password too short' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
            'confirmation missing' => [['password_confirmation' => null], ['password']],
            'confirmation mismatch' => [['password_confirmation' => 'another-password'], ['password']],
            'bcrypt over 72 bytes' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], ['password']],
            'multibyte over 72 bytes' => [['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)], ['password']],
            'null byte' => [['password' => "correct\0password", 'password_confirmation' => "correct\0password"], ['password']],
        ];
    }

    public function test_a_72_byte_multibyte_password_is_accepted_without_truncation(): void
    {
        $password = str_repeat('é', 36);
        $this->api('POST', '/api/register', $this->registration(['password' => $password, 'password_confirmation' => $password]))
            ->assertCreated();
        $this->api('POST', '/api/login', ['email' => 'customer@example.test', 'password' => $password])->assertOk();
        $this->assertTrue(Hash::check($password, User::sole()->password));
    }

    public function test_a_hash_shaped_password_is_hashed_as_the_literal_submitted_password(): void
    {
        $password = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->api('POST', '/api/register', $this->registration(['password' => $password, 'password_confirmation' => $password]))
            ->assertCreated();
        $stored = User::sole()->password;
        $this->assertNotSame($password, $stored);
        $this->assertTrue(Hash::check($password, $stored));
        $this->assertFalse(Hash::check('x', $stored));
        $this->assertSame((int) config('hashing.bcrypt.rounds'), password_get_info($stored)['options']['cost']);
        $this->api('POST', '/api/login', ['email' => 'customer@example.test', 'password' => $password])->assertOk();
        $this->api('POST', '/api/login', ['email' => 'customer@example.test', 'password' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_registration_accepts_the_minimum_password_maximum_name_and_long_valid_email(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 62);
        $this->assertSame(253, strlen($email));
        $this->api('POST', '/api/register', $this->registration([
            'name' => str_repeat('n', 255), 'email' => $email,
            'password' => 'password', 'password_confirmation' => 'password',
        ]))->assertCreated()->assertJsonPath('user.email', $email);
        $this->assertSame(255, strlen(User::sole()->name));
    }

    public function test_changing_a_valid_token_secret_does_not_authenticate_or_revoke_it(): void
    {
        $token = $this->api('POST', '/api/register', $this->registration())->json('access_token');
        [$id] = explode('|', $token, 2);
        $forged = $id.'|'.str_repeat('x', 40);
        $this->api('GET', '/api/user', [], $forged)->assertUnauthorized();
        $this->api('POST', '/api/logout', [], $forged)->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->api('GET', '/api/user', [], $token)->assertOk();
    }

    public function test_a_hostile_origin_does_not_receive_cors_permission(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example.test')->get('/api/health')
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeaders([
            'Origin' => 'https://untrusted.example.test',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/login')->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    #[DataProvider('invalidLogin')]
    public function test_login_validation_is_json_without_accept_and_creates_no_token(array $data, string $field): void
    {
        $this->api('POST', '/api/login', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidLogin(): array
    {
        return [
            'empty' => [[], 'email'],
            'bad email' => [['email' => 'bad', 'password' => 'password'], 'email'],
            'email array' => [['email' => ['bad'], 'password' => 'password'], 'email'],
            'missing password' => [['email' => 'customer@example.test'], 'password'],
            'password array' => [['email' => 'customer@example.test', 'password' => ['password']], 'password'],
            'overlong password' => [['email' => 'customer@example.test', 'password' => str_repeat('a', 73)], 'password'],
            'null byte' => [['email' => 'customer@example.test', 'password' => "pass\0word"], 'password'],
        ];
    }

    public function test_unknown_account_and_wrong_password_have_the_same_error_and_no_token(): void
    {
        $user = User::factory()->create();
        $unknown = $this->api('POST', '/api/login', ['email' => 'missing@example.test', 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $wrong = $this->api('POST', '/api/login', ['email' => $user->email, 'password' => 'incorrect-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($unknown->json(), $wrong->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('protectedRoutes')]
    public function test_protected_routes_reject_missing_and_malformed_tokens_as_json(string $method, string $uri): void
    {
        foreach ([null, 'not-a-token', '999999|invalid', ''] as $token) {
            $this->api($method, $uri, [], $token)
                ->assertUnauthorized()->assertHeader('Content-Type', 'application/json')
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function protectedRoutes(): array
    {
        return [['GET', '/api/user'], ['POST', '/api/logout']];
    }

    public function test_session_authenticated_users_cannot_access_bearer_only_routes(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/api/user')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->post('/api/logout')->assertUnauthorized();
    }

    public function test_expired_and_deleted_tokens_fail_at_the_expiry_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'));
        $token = $this->api('POST', '/api/register', $this->registration())->json('access_token');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 11:59:59', 'UTC'));
        $this->api('GET', '/api/user', [], $token)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
        $this->api('GET', '/api/user', [], $token)->assertUnauthorized();
        $this->api('POST', '/api/logout', [], $token)->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_token_bound_to_a_missing_user_is_rejected(): void
    {
        $token = $this->api('POST', '/api/register', $this->registration())->json('access_token');
        User::sole()->delete();
        $this->api('GET', '/api/user', [], $token)->assertUnauthorized();
    }

    public function test_duplicate_normalized_registration_does_not_change_the_original_account_or_issue_a_token(): void
    {
        $this->api('POST', '/api/register', $this->registration())->assertCreated();
        $this->api('POST', '/api/register', $this->registration(['email' => '  CUSTOMER@EXAMPLE.TEST  ', 'name' => 'Attacker']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame('Test Customer', User::sole()->name);
    }

    public function test_registration_converts_a_database_unique_race_into_validation(): void
    {
        $attributes = $this->registration();
        $this->app->make(AuthService::class)->register($attributes);

        try {
            $this->app->make(AuthService::class)->register($attributes);
            $this->fail('A duplicate email must be rejected by the authoritative unique index.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_registration_rolls_back_the_account_when_token_creation_fails(): void
    {
        Event::listen('eloquent.creating: '.PersonalAccessToken::class, function (): never {
            throw new RuntimeException('Simulated token storage failure');
        });
        $this->api('POST', '/api/register', $this->registration())->assertStatus(500);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_limited_by_normalized_identity_and_ip_and_recovers_after_a_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->api('POST', '/api/login', ['email' => '  CUSTOMER@EXAMPLE.TEST  ', 'password' => 'invalid'])
                ->assertUnprocessable();
        }
        $this->api('POST', '/api/login', ['email' => 'customer@example.test', 'password' => 'invalid'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->api('POST', '/api/login', ['email' => 'different@example.test', 'password' => 'invalid'])
            ->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->api('POST', '/api/login', ['email' => 'customer@example.test', 'password' => 'invalid'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_ip_limit_stops_rotating_email_bypass(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->api('POST', '/api/login', ['email' => "customer{$attempt}@example.test", 'password' => 'invalid'])
                ->assertUnprocessable();
        }
        $this->api('POST', '/api/login', ['email' => 'new-customer@example.test', 'password' => 'invalid'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_registration_is_limited_by_ip(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->api('POST', '/api/register', [])->assertUnprocessable();
        }
        $this->api('POST', '/api/register', [])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('users', 0);
    }
}
