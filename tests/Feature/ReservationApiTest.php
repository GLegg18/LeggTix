<?php

namespace Tests\Feature;

use App\Actions\ReserveEventAction;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    private function api(string $uri, ?string $token = null, array $data = [], ?string $rawBody = null, string $contentType = 'application/json'): TestResponse
    {
        // Real bearer token path, with fresh guards and no fake authentication.
        $this->app['auth']->forgetGuards();
        $server = ['CONTENT_TYPE' => $contentType];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('POST', $uri, [], [], [], $server,
            $rawBody ?? json_encode((object) $data, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('bookingRoles')]
    public function test_authenticated_role_can_create_only_own_reservation_and_receives_safe_resource(string $role): void
    {
        $actor = User::factory()->state(['role' => $role])->create();
        $event = Event::factory()->published()->create(['capacity' => 2]);
        $token = $actor->createToken('reservation-test', ['*'], now()->addHour())->plainTextToken;

        $response = $this->api('/api/events/'.$event->id.'/reservations', $token);

        $reservation = Reservation::sole();
        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson([
            'data' => [
                'id' => $reservation->id, 'event_id' => $event->id, 'user_id' => $actor->id,
                'status' => 'confirmed', 'cancelled_at' => null,
                'created_at' => $reservation->created_at->toISOString(),
                'updated_at' => $reservation->updated_at->toISOString(),
            ],
        ]);
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertNotSame($event->owner_id, $reservation->user_id);
    }

    public static function bookingRoles(): array
    {
        return ['customer' => ['regular_user'], 'organiser' => ['organiser'], 'admin' => ['admin']];
    }

    #[DataProvider('invalidTokens')]
    public function test_guest_or_invalid_token_is_rejected_before_allocation(?string $token): void
    {
        $event = Event::factory()->published()->create();

        $this->api('/api/events/'.$event->id.'/reservations', $token)->assertUnauthorized();

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function invalidTokens(): array
    {
        return ['guest' => [null], 'invalid bearer' => ['not-a-valid-token']];
    }

    public function test_expired_token_is_rejected_before_allocation(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        $token = $actor->createToken('expired', ['*'], now()->subMinute())->plainTextToken;

        $this->api('/api/events/'.$event->id.'/reservations', $token)->assertUnauthorized();

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    #[DataProvider('protectedFields')]
    public function test_body_override_or_unknown_field_is_rejected_without_booking(string $field, mixed $value): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->published()->create();
        $token = $actor->createToken('reservation-test')->plainTextToken;

        $this->api('/api/events/'.$event->id.'/reservations', $token, [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertTrue($other->reservations->isEmpty());
    }

    public static function protectedFields(): array
    {
        return [
            'holder' => ['user_id', 999999],
            'event' => ['event_id', 999999],
            'identity' => ['id', 999999],
            'status' => ['status', 'confirmed'],
            'null status' => ['status', null],
            'generated key' => ['active_user_id', 999999],
            'cancelled time' => ['cancelled_at', '1999-01-01'],
            'timestamp' => ['created_at', '1999-01-01'],
            'quantity' => ['quantity', 2],
            'unknown nested' => ['extra', ['user_id' => 999999]],
        ];
    }

    public function test_query_string_cannot_override_authenticated_holder(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->published()->create();

        $this->api('/api/events/'.$event->id.'/reservations?user_id='.$other->id, $actor->createToken('test')->plainTextToken)
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_malformed_json_is_client_error_and_cannot_allocate_a_place(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        $response = $this->api('/api/events/'.$event->id.'/reservations', $actor->createToken('test')->plainTextToken,
            rawBody: '{"user_id":');

        $response->assertStatus(400);
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    #[DataProvider('nonObjectJson')]
    public function test_nonobject_json_is_validation_error_and_cannot_allocate_a_place(string $body): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();

        $this->api('/api/events/'.$event->id.'/reservations', $actor->createToken('test')->plainTextToken, rawBody: $body)
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function nonObjectJson(): array
    {
        return ['array' => ['[]'], 'null' => ['null'], 'number' => ['1'], 'string' => ['"text"'], 'boolean' => ['true']];
    }

    public function test_nonjson_payload_is_unsupported_and_cannot_allocate_a_place(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();

        $this->api('/api/events/'.$event->id.'/reservations', $actor->createToken('test')->plainTextToken,
            rawBody: 'user_id=999999', contentType: 'text/plain')->assertStatus(415);

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_no_request_body_is_accepted_and_allocates_one_place(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();

        $this->api('/api/events/'.$event->id.'/reservations', $actor->createToken('test')->plainTextToken, rawBody: '')
            ->assertCreated();

        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_declared_multipart_is_rejected_even_when_php_hides_its_raw_body(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        $this->app['auth']->forgetGuards();

        $this->call('POST', '/api/events/'.$event->id.'/reservations', [], [], [], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=test-boundary',
            'CONTENT_LENGTH' => '80',
            'HTTP_AUTHORIZATION' => 'Bearer '.$actor->createToken('test')->plainTextToken,
        ], '')->assertStatus(415);

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_bodyless_request_with_no_content_type_is_accepted(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        $this->app['auth']->forgetGuards();

        // Symfony Request::create (used by call()) supplies a form media type
        // for POST. Construct directly to model a truly absent header.
        $request = new Request([], [], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/events/'.$event->id.'/reservations',
            'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80',
            'HTTP_AUTHORIZATION' => 'Bearer '.$actor->createToken('test')->plainTextToken,
        ], '');
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        TestResponse::fromBaseResponse($response)->assertCreated();

        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_unknown_numeric_event_and_malformed_route_are_not_found_without_side_effects(): void
    {
        $actor = User::factory()->create();
        $token = $actor->createToken('test')->plainTextToken;

        foreach (['999999', 'not-an-event', '-1', '1.5'] as $id) {
            $this->api('/api/events/'.$id.'/reservations', $token)->assertNotFound();
        }

        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_replay_and_full_response_keep_existing_holder_and_do_not_join_waitlist(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $uri = '/api/events/'.$event->id.'/reservations';
        $token = $actor->createToken('test')->plainTextToken;
        $created = $this->api($uri, $token)->assertCreated();

        $this->api($uri, $token)->assertConflict()->assertJsonPath('code', 'already_reserved');
        $this->api($uri, $other->createToken('test')->plainTextToken)->assertConflict()->assertJsonPath('code', 'full');

        $this->assertSame([$created->json('data.id')], $event->reservations()->pluck('id')->all());
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertTrue($other->reservations->isEmpty());
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_ineligible_event_returns_explicit_conflict_without_state_change(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->draft()->create();

        $this->api('/api/events/'.$event->id.'/reservations', $actor->createToken('test')->plainTextToken)
            ->assertConflict()->assertJsonPath('code', 'event_not_bookable');

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_exhausted_database_contention_is_retryable_busy_and_never_reported_as_full(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        $pdo = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
        $pdo->errorInfo = ['40001', 1213, $pdo->getMessage()];
        $action = Mockery::mock(ReserveEventAction::class);
        $action->shouldReceive('execute')->once()->andThrow(new QueryException('mysql', 'SELECT ... FOR UPDATE', [], $pdo));
        $this->app->instance(ReserveEventAction::class, $action);

        $this->api('/api/events/'.$event->id.'/reservations', $actor->createToken('test')->plainTextToken)
            ->assertStatus(503)->assertJsonPath('code', 'reservation_busy')->assertHeader('Retry-After', '1');

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_rate_limit_blocks_more_than_thirty_actor_requests_without_allocating(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->draft()->create();
        $token = $actor->createToken('test')->plainTextToken;
        $uri = '/api/events/'.$event->id.'/reservations';

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->api($uri, $token)->assertConflict();
        }

        $this->api($uri, $token)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }
}
