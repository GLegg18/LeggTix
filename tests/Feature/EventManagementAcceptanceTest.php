<?php

namespace Tests\Feature;

use App\Actions\ReserveEventAction;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventManagementAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function api(string $method, string $path, ?User $actor, array $body = [], ?string $raw = null, string $contentType = 'application/json'): TestResponse
    {
        Auth::forgetGuards();
        $headers = ['CONTENT_TYPE' => $contentType];
        if ($actor !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$actor->createToken('management-acceptance')->plainTextToken;
        }

        return $this->call($method, $path, [], [], [], $headers, $raw ?? json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return [
            'event_type_id' => EventType::factory()->create()->id,
            'name' => 'Independent acceptance event', 'description' => 'An ordinary event.',
            'venue' => 'Acceptance room', 'starts_at' => now('UTC')->addMonth()->toISOString(),
            'ends_at' => null, 'timezone' => 'Europe/London', 'capacity' => 3,
        ];
    }

    private function waiter(Event $event, User $user, string $status = 'waiting', ?string $joined = null): int
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');

        return DB::table('waitlist_entries')->insertGetId([
            'event_id' => $event->id, 'user_id' => $user->id, 'status' => $status,
            'joined_at' => $joined ?? $now,
            'promoted_at' => $status === 'promoted' ? $now : null,
            'cancelled_at' => $status === 'cancelled' ? $now : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_privileged_creator_is_owner_and_regular_or_guest_cannot_create(): void
    {
        $body = $this->payload();
        $this->api('POST', '/api/manage/events', null, $body)->assertUnauthorized();
        $this->api('POST', '/api/manage/events', User::factory()->create(), $body)->assertForbidden();
        foreach ([User::factory()->organiser()->create(), User::factory()->admin()->create()] as $actor) {
            $this->api('POST', '/api/manage/events', $actor, $body)->assertCreated()
                ->assertJsonPath('data.owner_id', $actor->id)->assertJsonPath('data.status', 'draft')
                ->assertJsonPath('data.confirmed_count', 0);
        }
        $this->assertDatabaseCount('events', 2);
    }

    public function test_demotion_removes_management_even_though_event_ownership_is_retained(): void
    {
        $owner = User::factory()->organiser()->create();
        $event = Event::factory()->for($owner, 'owner')->create();
        $token = $owner->createToken('before-demotion')->plainTextToken;
        DB::table('users')->where('id', $owner->id)->update(['role' => 'regular_user']);
        foreach (['/api/manage/events', '/api/manage/event-types'] as $path) {
            Auth::forgetGuards();
            $this->call('GET', $path, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token])->assertForbidden();
        }
        foreach ([['GET', ''], ['PATCH', ''], ['DELETE', ''], ['GET', '/reservations'], ['GET', '/waitlist']] as [$method, $suffix]) {
            Auth::forgetGuards();
            $this->call($method, '/api/manage/events/'.$event->id.$suffix, [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], $method === 'PATCH' ? '{"name":"Hijacked"}' : '{}')->assertForbidden();
        }
        $this->assertSame($owner->id, $event->fresh()->owner_id);
        $this->assertSame(EventStatus::Draft, $event->fresh()->status);
    }

    public function test_other_organiser_cannot_read_private_data_or_mutate_but_admin_can_manage(): void
    {
        $event = Event::factory()->create();
        $other = User::factory()->organiser()->create();
        foreach (['', '/reservations', '/waitlist'] as $suffix) {
            $this->api('GET', '/api/manage/events/'.$event->id.$suffix, $other)->assertForbidden();
        }
        $this->api('PATCH', '/api/manage/events/'.$event->id, $other, ['name' => 'Hijacked'])->assertForbidden();
        $this->api('DELETE', '/api/manage/events/'.$event->id, $other)->assertForbidden();
        $admin = User::factory()->admin()->create();
        $this->api('PATCH', '/api/manage/events/'.$event->id, $admin, ['name' => 'Admin correction'])->assertOk();
        $this->assertSame('Admin correction', $event->fresh()->name);
        $this->assertNotSame($admin->id, $event->fresh()->owner_id);
    }

    #[DataProvider('invalidWriteBodies')]
    public function test_malformed_or_nonobject_or_nonjson_mutation_cannot_change_event(string $raw, string $type, int $status): void
    {
        $event = Event::factory()->create();
        $original = $event->fresh()->getAttributes();
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, raw: $raw, contentType: $type)->assertStatus($status);
        $this->assertSame($original, $event->fresh()->getAttributes());
    }

    public static function invalidWriteBodies(): array
    {
        return [
            'broken JSON' => ['{"name":', 'application/json', 400],
            'array' => ['[]', 'application/json', 422],
            'null' => ['null', 'application/json', 422],
            'string' => ['"event"', 'application/json', 422],
            'form' => ['name=Changed', 'application/x-www-form-urlencoded', 415],
            'plain' => ['{"name":"Changed"}', 'text/plain', 415],
        ];
    }

    public function test_protected_fields_and_query_overrides_are_rejected_and_native_integer_required(): void
    {
        $event = Event::factory()->create();
        foreach (['owner_id' => 123, 'confirmed_count' => 2, 'id' => 123, 'created_at' => '2020-01-01'] as $field => $value) {
            $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->api('PATCH', '/api/manage/events/'.$event->id.'?capacity=4', $event->owner, ['name' => 'Query rejected'])
            ->assertUnprocessable();
        foreach (['3', 3.5, true, 0, 4294967296] as $capacity) {
            $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['capacity' => $capacity])
                ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        }
        $this->assertSame($event->name, $event->fresh()->name);
        $this->assertSame($event->capacity, $event->fresh()->capacity);
    }

    public function test_ambiguous_or_invalid_schedule_never_changes_an_event(): void
    {
        $event = Event::factory()->create();
        foreach (['2027-03-28T01:30:00', '2027-02-30T12:00:00Z', 'not-a-date', '1000-01-01T00:00:00+14:00', '9999-12-31T23:59:59-14:00'] as $date) {
            $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['starts_at' => $date])
                ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        }
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['ends_at' => $event->starts_at->subSecond()->toISOString()])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['timezone' => 'Europe/Invented'])
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->assertTrue($event->starts_at->equalTo($event->fresh()->starts_at));
    }

    public function test_multibyte_description_cannot_overflow_mysql_text_storage(): void
    {
        $event = Event::factory()->create();
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['description' => str_repeat('界', 30000)])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertSame($event->description, $event->fresh()->description);
    }

    public function test_cancelled_history_freezes_schedule_type_and_timezone_but_allows_metadata(): void
    {
        $event = Event::factory()->published()->create();
        Reservation::factory()->for($event)->cancelled()->create();
        $this->waiter($event, User::factory()->create(), 'cancelled');
        foreach ([
            'starts_at' => $event->starts_at->addDay()->toISOString(),
            'ends_at' => $event->starts_at->addDays(2)->toISOString(),
            'timezone' => 'America/New_York', 'event_type_id' => EventType::factory()->create()->id,
        ] as $field => $value) {
            $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['name' => 'A corrected title', 'venue' => 'Room B'])
            ->assertOk()->assertJsonPath('data.has_history', true);
        $this->assertSame('A corrected title', $event->fresh()->name);
    }

    public function test_retired_type_blocks_new_use_and_publication_but_preserves_published_metadata_edits(): void
    {
        $type = EventType::factory()->inactive()->create();
        $draft = Event::factory()->for($type, 'eventType')->create();
        $this->api('PATCH', '/api/manage/events/'.$draft->id, $draft->owner, ['status' => 'published'])->assertUnprocessable();
        $published = Event::factory()->for($type, 'eventType')->published()->create();
        $this->api('PATCH', '/api/manage/events/'.$published->id, $published->owner, ['name' => 'Retirement is not cancellation'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->api('POST', '/api/manage/events', $published->owner, array_replace($this->payload(), ['event_type_id' => $type->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('event_type_id');
        $this->getJson('/api/events/'.$published->id)->assertOk();
    }

    public function test_event_cancellation_closes_only_active_attempts_and_is_replay_safe(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 3]);
        $holder = User::factory()->create();
        $reservation = app(ReserveEventAction::class)->execute($holder, $event->id);
        $cancelled = Reservation::factory()->for($event)->cancelled()->create();
        $waiting = $this->waiter($event, User::factory()->create());
        $promoted = $this->waiter($event, User::factory()->create(), 'promoted');
        $oldReservation = $cancelled->fresh()->getAttributes();
        $oldPromoted = DB::table('waitlist_entries')->find($promoted);
        $this->api('DELETE', '/api/manage/events/'.$event->id, $event->owner)->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.confirmed_count', 0);
        $cancelledAt = $reservation->fresh()->cancelled_at->toISOString();
        $this->api('DELETE', '/api/manage/events/'.$event->id, $event->owner)->assertOk();
        $this->assertSame($cancelledAt, $reservation->fresh()->cancelled_at->toISOString());
        $this->assertSame($oldReservation, $cancelled->fresh()->getAttributes());
        $this->assertEquals($oldPromoted, DB::table('waitlist_entries')->find($promoted));
        $this->assertDatabaseHas('waitlist_entries', ['id' => $waiting, 'status' => 'cancelled']);
        $this->assertSame(0, $event->reservations()->where('status', 'confirmed')->count());
        $this->assertSame(0, DB::table('waitlist_entries')->where('event_id', $event->id)->where('status', 'waiting')->count());
        $this->assertDatabaseCount('reservations', 2);
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['name' => 'Cancelled event correction'])
            ->assertOk()->assertJsonPath('data.name', 'Cancelled event correction')->assertJsonPath('data.status', 'cancelled');
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['status' => 'published'])->assertUnprocessable();
    }

    public function test_completion_keeps_confirmed_attendance_and_closes_waiters_without_promotion(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 2]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $waiting = $this->waiter($event, User::factory()->create());
        DB::table('events')->where('id', $event->id)->update(['starts_at' => now('UTC')->subDay()->format('Y-m-d H:i:s.u'), 'ends_at' => null]);
        $this->api('PATCH', '/api/manage/events/'.$event->id, $event->owner, ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.confirmed_count', 1);
        $this->assertSame(1, $event->reservations()->where('status', 'confirmed')->count());
        $this->assertDatabaseHas('waitlist_entries', ['id' => $waiting, 'status' => 'cancelled']);
        $this->api('DELETE', '/api/manage/events/'.$event->id, $event->owner)->assertUnprocessable();
    }

    public function test_private_lists_use_stable_cursors_include_history_and_hide_contact_secrets(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 2]);
        $first = Reservation::factory()->for($event)->cancelled()->create();
        $second = app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $firstWaiter = $this->waiter($event, User::factory()->create(), 'cancelled', '2026-01-01 00:00:00.000001');
        $secondWaiter = $this->waiter($event, User::factory()->create(), 'waiting', '2026-01-01 00:00:00.000001');
        foreach (['reservations' => [$first->id, $second->id], 'waitlist' => [$firstWaiter, $secondWaiter]] as $list => $expectedIds) {
            $response = $this->api('GET', '/api/manage/events/'.$event->id.'/'.$list.'?per_page=1', $event->owner)
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $expectedIds[0]);
            $entry = $response->json('data.0');
            $this->assertSame(['id', 'name'], array_keys($entry['user']));
            foreach (['email', 'password', 'active_user_id', 'waiting_user_id', 'remember_token'] as $secret) {
                $this->assertStringNotContainsString('"'.$secret.'"', $response->getContent());
            }
            $cursor = $response->json('meta.next_cursor');
            $this->assertIsString($cursor);
            $this->api('GET', '/api/manage/events/'.$event->id.'/'.$list.'?per_page=1&cursor='.urlencode($cursor), $event->owner)
                ->assertOk()->assertJsonPath('data.0.id', $expectedIds[1])->assertJsonPath('meta.next_cursor', null);
            $this->api('GET', '/api/manage/events/'.$event->id.'/'.$list.'?cursor=garbage', $event->owner)->assertUnprocessable();
            $this->api('GET', '/api/manage/events/'.$event->id.'/'.$list.'?user_id=123', $event->owner)->assertUnprocessable();
        }
    }

    public function test_management_index_scopes_owners_and_active_type_choices_are_private(): void
    {
        $actor = User::factory()->organiser()->create();
        $own = Event::factory()->for($actor, 'owner')->create();
        Event::factory()->create();
        $this->api('GET', '/api/manage/events', $actor)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->api('GET', '/api/manage/events', User::factory()->admin()->create())->assertOk()->assertJsonCount(2, 'data');
        $inactive = EventType::factory()->inactive()->create();
        $this->api('GET', '/api/manage/event-types', null)->assertUnauthorized();
        $this->api('GET', '/api/manage/event-types', User::factory()->create())->assertForbidden();
        $types = $this->api('GET', '/api/manage/event-types', $actor)->assertOk()->json('data');
        $this->assertNotContains($inactive->id, array_column($types, 'id'));
        foreach ($types as $type) {
            $this->assertSame(['id', 'slug', 'name'], array_keys($type));
        }
    }

    public function test_real_spa_login_requires_csrf_for_management_mutation_and_rejects_foreign_origin(): void
    {
        config([
            'sanctum.stateful' => ['localhost:3000'], 'cors.allowed_origins' => ['http://localhost:3000'],
            'sanctum.middleware.validate_csrf_token' => EnforceManagementCsrf::class, 'session.secure' => false,
        ]);
        $actor = User::factory()->organiser()->create();
        $event = Event::factory()->for($actor, 'owner')->create();
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $challenge = $this->browser('POST', '/api/spa/login', ['email' => $actor->email, 'password' => 'password'])->assertStatus(202);
        $code = Crypt::decryptString(DB::table('login_email_verifications')->where('user_id', $actor->id)->value('encrypted_code'));
        $this->browser('POST', '/api/spa/login/email-verifications/'.$challenge->json('challenge_id').'/verify', ['code' => $code])->assertOk();
        $this->browser('PATCH', '/api/spa/manage/events/'.$event->id, ['name' => 'CSRF refused'], false)->assertStatus(419);
        $this->assertSame($event->name, $event->fresh()->name);
        $this->browser('PATCH', '/api/spa/manage/events/'.$event->id, ['name' => 'Secure browser edit'])->assertOk();
        $this->browser('GET', '/api/spa/manage/events/'.$event->id, origin: 'http://untrusted.example')->assertForbidden();
        $this->assertSame('Secure browser edit', $event->fresh()->name);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function browser(string $method, string $path, array $body = [], bool $csrf = true, string $origin = 'http://localhost:3000'): TestResponse
    {
        Auth::forgetGuards();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => $origin];
        if ($csrf && isset($this->cookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        $response = $this->call($method, $path, [], $this->cookies, [], $server, json_encode((object) $body, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }
}

class EnforceManagementCsrf extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
