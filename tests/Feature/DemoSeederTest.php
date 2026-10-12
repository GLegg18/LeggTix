<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-08 12:00:00.123456';

    private CarbonImmutable $seedTime;

    protected function setUp(): void
    {
        parent::setUp();

        // Booking checks MySQL time, so keep future fixtures future on any run date.
        $this->seedTime = CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC');
        $this->travelTo($this->seedTime);
    }

    private function snapshot(): array
    {
        $snapshot = [];

        foreach (['users', 'event_types', 'events', 'reservations', 'waitlist_entries', 'personal_access_tokens'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function assertRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('The unsafe seed operation must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function book(Event $event, ?string $token = null, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('POST', '/api/events/'.$event->id.'/reservations', [], [], [], $headers,
            json_encode((object) $data, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('allowedEnvironments')]
    public function test_default_seeder_creates_valid_demo_accounts_catalogue_events_and_real_bookings(string $environment): void
    {
        Queue::fake();
        Notification::fake();
        $this->app['env'] = $environment;

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->app['env'] = 'testing';
        }

        $customer = User::where('email', 'demo.user@example.test')->sole();
        $owner = User::where('email', 'demo.owner@example.test')->sole();
        $admin = User::where('email', 'demo.admin@example.test')->sole();
        $attendeeOne = User::where('email', 'demo.attendee.one@example.test')->sole();
        $attendeeTwo = User::where('email', 'demo.attendee.two@example.test')->sole();

        $this->assertSame(UserRole::RegularUser, $customer->role);
        $this->assertSame(UserRole::Organiser, $owner->role);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserRole::RegularUser, $attendeeOne->role);
        $this->assertSame(UserRole::RegularUser, $attendeeTwo->role);
        $this->assertNotSame($customer->id, $owner->id);

        foreach ([$customer, $owner, $admin, $attendeeOne, $attendeeTwo] as $user) {
            $this->assertNotSame('demo-password', $user->password);
            $this->assertTrue(Hash::check('demo-password', $user->password));
            $this->assertSame($this->seedTime->format('Y-m-d H:i:s.u'), $user->created_at->format('Y-m-d H:i:s.u'));
        }

        $this->assertEqualsCanonicalizing(['concert', 'workshop', 'meetup', 'live-show', 'film-screening'], EventType::pluck('slug')->all());
        $this->assertSame(5, EventType::where('is_active', true)->count());

        $expected = [
            'Demo: Riverside Acoustic Night' => [EventStatus::Published, 'concert', false, true],
            'Demo: Laravel Makers Workshop' => [EventStatus::Published, 'workshop', false, true],
            'Demo: Community Coffee Meetup' => [EventStatus::Draft, 'meetup', false, false],
            'Demo: Garden Session' => [EventStatus::Cancelled, 'concert', false, false],
            'Demo: Intro to Web APIs' => [EventStatus::Completed, 'workshop', true, false],
            "Demo: Last Week's Community Meetup" => [EventStatus::Published, 'meetup', true, false],
            'Demo: Studio Live Showcase' => [EventStatus::Published, 'live-show', false, true],
            'Demo: Independent Film Screening' => [EventStatus::Published, 'film-screening', false, true],
        ];

        foreach ($expected as $name => [$status, $slug, $past, $bookable]) {
            $event = Event::where('name', $name)->sole();
            $this->assertSame($owner->id, $event->owner_id);
            $this->assertSame($status, $event->status);
            $this->assertSame($slug, $event->eventType->slug);
            $this->assertSame($past, $event->isPast());
            $this->assertSame($bookable, $event->isBookable());
            $this->assertSame('UTC', $event->starts_at->timezoneName);
            $this->assertSame('Europe/London', $event->timezone);
            $this->assertTrue($event->ends_at->greaterThan($event->starts_at));
            $this->assertGreaterThan(0, $event->capacity);
            $this->assertSame($event->reservations()->where('status', 'confirmed')->count(), $event->confirmed_count);
            $this->assertLessThanOrEqual($event->capacity, $event->confirmed_count);
            $this->assertNotEmpty($event->description);
            $this->assertNotEmpty($event->venue);
        }

        $available = Event::where('name', 'Demo: Riverside Acoustic Night')->sole();
        $full = Event::where('name', 'Demo: Studio Live Showcase')->sole();
        $this->assertSame(1, $available->confirmed_count);
        $this->assertGreaterThan($available->confirmed_count, $available->capacity);
        $this->assertSame(2, $full->capacity);
        $this->assertSame($full->capacity, $full->confirmed_count);
        $this->assertEqualsCanonicalizing([$attendeeOne->id, $attendeeTwo->id], $full->reservations()->pluck('user_id')->all());
        $this->assertDatabaseHas('reservations', [
            'event_id' => $available->id, 'user_id' => $customer->id, 'status' => 'confirmed', 'cancelled_at' => null,
        ]);
        foreach (Reservation::all() as $reservation) {
            $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
            $this->assertNull($reservation->cancelled_at);
        }

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('event_types', 5);
        $this->assertDatabaseCount('events', 8);
        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
        $this->assertDatabaseCount('reservations', 3);
        $this->assertDatabaseCount('waitlist_entries', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, $customer->ownedEvents()->count());
        $this->assertSame(8, $owner->ownedEvents()->count());
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public static function allowedEnvironments(): array
    {
        return [['local'], ['testing']];
    }

    public function test_demo_events_support_real_booking_guest_validation_replay_full_and_terminal_outcomes(): void
    {
        $this->seed();
        $screening = Event::where('name', 'Demo: Independent Film Screening')->sole();
        $full = Event::where('name', 'Demo: Studio Live Showcase')->sole();
        $customer = User::where('email', 'demo.user@example.test')->sole();
        $this->book($screening)->assertUnauthorized();
        $login = $this->postJson('/api/login', [
            'email' => $customer->email, 'password' => 'demo-password',
        ])->assertOk();
        $token = $login->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertOk();
        $before = $this->snapshot();
        $this->book($screening, $token, ['user_id' => $full->owner_id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame($before, $this->snapshot());

        $this->book($screening, $token)->assertCreated()
            ->assertJsonPath('data.user_id', $customer->id)
            ->assertJsonPath('data.event_id', $screening->id)
            ->assertJsonPath('data.status', 'confirmed');
        $this->assertSame(1, $screening->fresh()->confirmed_count);
        $before = $this->snapshot();

        $this->book($screening, $token)->assertConflict()->assertJsonPath('code', 'already_reserved');
        $this->book($full, $token)->assertConflict()->assertJsonPath('code', 'full');
        foreach (['Demo: Garden Session', 'Demo: Intro to Web APIs', "Demo: Last Week's Community Meetup", 'Demo: Community Coffee Meetup'] as $name) {
            $this->book(Event::where('name', $name)->sole(), $token)
                ->assertConflict()->assertJsonPath('code', 'event_not_bookable');
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('reservations', 4);
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_existing_legacy_demo_fixtures_are_extended_without_replacing_edits_credentials_or_unrelated_data(): void
    {
        $this->seed();
        // Recreate the old two-user/three-type/six-event dataset in this test DB.
        Reservation::query()->delete();
        DB::table('events')->update(['confirmed_count' => 0]);
        Event::whereIn('name', ['Demo: Studio Live Showcase', 'Demo: Independent Film Screening'])->delete();
        EventType::whereIn('slug', ['live-show', 'film-screening'])->delete();
        User::whereIn('email', ['demo.admin@example.test', 'demo.attendee.one@example.test', 'demo.attendee.two@example.test'])->delete();
        $customer = User::where('email', 'demo.user@example.test')->sole();
        $customer->forceFill(['name' => 'Legacy customer edit', 'password' => Hash::make('legacy-password')])->save();
        $customer->createToken('Legacy client');
        $unrelated = Event::factory()->published()->create(['name' => 'Unrelated local event']);
        Reservation::factory()->for($unrelated)->create();
        $riverside = Event::where('name', 'Demo: Riverside Acoustic Night')->sole();
        $riverside->forceFill(['description' => 'Legacy edited description'])->save();
        $before = $this->snapshot();

        $this->seed();

        foreach ($before as $table => $rows) {
            foreach ($rows as $row) {
                $after = (array) DB::table($table)->find($row['id']);
                if ($table === 'events' && $row['id'] === $riverside->id) {
                    $this->assertSame(1, $after['confirmed_count']);
                    unset($row['confirmed_count'], $row['updated_at'], $after['confirmed_count'], $after['updated_at']);
                }
                $this->assertSame($row, $after);
            }
        }
        $this->assertTrue(Hash::check('legacy-password', $customer->fresh()->password));
        $this->assertDatabaseCount('users', 7); // Five demo users plus unrelated owner/holder.
        $this->assertDatabaseCount('events', 9);
        $this->assertDatabaseCount('reservations', 4);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $after = $this->snapshot();
        $this->seed();
        $this->assertSame($after, $this->snapshot());
    }

    #[DataProvider('preservedHistories')]
    public function test_rerun_does_not_rebook_a_demo_customer_with_existing_reservation_or_waitlist_history(string $history): void
    {
        $this->seed();
        $event = Event::where('name', 'Demo: Riverside Acoustic Night')->sole();
        $customer = User::where('email', 'demo.user@example.test')->sole();
        $booking = $event->reservations()->where('user_id', $customer->id)->sole();
        $event->forceFill(['confirmed_count' => 0])->save();

        if ($history === 'reservation-cancelled') {
            $booking->forceFill(['status' => ReservationStatus::Cancelled, 'cancelled_at' => now()])->save();
        } else {
            $booking->delete();
            DB::table('waitlist_entries')->insert([
                'event_id' => $event->id, 'user_id' => $customer->id, 'status' => $history,
                'joined_at' => self::NOW,
                'promoted_at' => $history === 'promoted' ? self::NOW : null,
                'cancelled_at' => $history === 'cancelled' ? self::NOW : null,
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
        }
        $before = $this->snapshot();

        $this->seed();
        $this->seed(DemoSeeder::class);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertSame(0, $event->reservations()->where('status', 'confirmed')->count());
    }

    public static function preservedHistories(): array
    {
        return [
            'cancelled reservation' => ['reservation-cancelled'],
            'waiting entry' => ['waiting'],
            'promoted entry' => ['promoted'],
            'left waitlist entry' => ['cancelled'],
        ];
    }

    #[DataProvider('existingBookingConflicts')]
    public function test_missing_demo_booking_cannot_bypass_event_state_capacity_or_waitlist_and_rolls_back_new_fixtures(
        string $condition,
        string $reason,
    ): void {
        $this->seed();
        $event = Event::where('name', 'Demo: Riverside Acoustic Night')->sole();
        $customer = User::where('email', 'demo.user@example.test')->sole();
        $event->reservations()->where('user_id', $customer->id)->delete();
        $event->forceFill(['confirmed_count' => 0])->save();
        User::where('email', 'demo.admin@example.test')->delete();
        if ($condition === 'full') {
            $event->capacity = 1;
            $event->save();
            Reservation::factory()->for($event)->for(User::factory())->create();
        } elseif ($condition === 'waiting') {
            DB::table('waitlist_entries')->insert([
                'event_id' => $event->id, 'user_id' => User::factory()->create()->id,
                'status' => 'waiting', 'joined_at' => self::NOW,
                'promoted_at' => null, 'cancelled_at' => null,
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
        } elseif ($condition === 'past') {
            $event->starts_at = now()->subHour();
            $event->save();
        } else {
            $event->status = EventStatus::Cancelled;
            $event->save();
        }
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(), sprintf(
            'Cannot create demo reservation for %s at %s: %s; existing data was left unchanged.',
            $customer->email, $event->name, $reason,
        ));

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('users', ['email' => 'demo.admin@example.test']);
    }

    public static function existingBookingConflicts(): array
    {
        return [
            'existing full fixture' => ['full', 'full'],
            'existing queue has priority' => ['waiting', 'queue_has_priority'],
            'existing fixture has started' => ['past', 'event_not_bookable'],
            'existing fixture cancelled' => ['cancelled', 'event_not_bookable'],
        ];
    }

    #[DataProvider('counterDriftFixtures')]
    public function test_counter_drift_is_rejected_without_repair_even_when_no_booking_would_be_added(string $name, int $counter): void
    {
        $this->seed();
        Event::where('name', $name)->sole()->forceFill(['confirmed_count' => $counter])->save();
        User::where('email', 'demo.admin@example.test')->delete();
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(), sprintf(
            'Cannot seed demo event %s: confirmed_count does not match confirmed reservations; existing data was left unchanged.', $name,
        ));

        $this->assertSame($before, $this->snapshot());
    }

    public static function counterDriftFixtures(): array
    {
        return [
            'existing booking skipped' => ['Demo: Riverside Acoustic Night', 0],
            'no intended reservation' => ['Demo: Independent Film Screening', 1],
        ];
    }

    #[DataProvider('bookingFailures')]
    public function test_late_booking_failure_rolls_back_all_fixtures_and_previous_allocation(bool $veto, string $message): void
    {
        $bookings = 0;
        EventDispatcher::listen('eloquent.creating: '.Reservation::class, function () use (&$bookings, $veto): ?bool {
            if (++$bookings === 2) {
                if ($veto) {
                    return false;
                }
                throw new RuntimeException('Injected second demo booking failure');
            }

            return null;
        });
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(), $message);

        $this->assertSame(2, $bookings);
        $this->assertSame($before, $this->snapshot());
    }

    public static function bookingFailures(): array
    {
        return [
            'insertion exception' => [false, 'Injected second demo booking failure'],
            'observer veto' => [true, 'The reservation could not be saved.'],
        ];
    }

    public function test_all_seeded_accounts_can_login_through_the_existing_api_with_their_own_roles(): void
    {
        $this->seed();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        foreach ([
            'demo.user@example.test' => 'regular_user',
            'demo.owner@example.test' => 'organiser',
            'demo.admin@example.test' => 'admin',
            'demo.attendee.one@example.test' => 'regular_user',
            'demo.attendee.two@example.test' => 'regular_user',
        ] as $email => $role) {
            $this->app['auth']->forgetGuards();
            $response = $this->postJson('/api/login', ['email' => $email, 'password' => 'demo-password'])
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('user.email', $email)->assertJsonPath('user.role', $role)
                ->assertJsonPath('token_type', 'Bearer');
            $this->assertArrayNotHasKey('password', $response->json('user'));
            $this->assertNotEmpty($response->json('access_token'));

            $this->app['auth']->forgetGuards();
            $this->withToken($response->json('access_token'))->getJson('/api/user')
                ->assertOk()->assertJsonPath('data.email', $email)->assertJsonPath('data.role', $role);
        }

        $this->assertDatabaseCount('personal_access_tokens', 5);
        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
    }

    public function test_repeated_seed_is_a_true_noop_after_edits_credentials_type_retirement_and_booking_history(): void
    {
        $this->seed();
        $customer = User::where('email', 'demo.user@example.test')->sole();
        $owner = User::where('email', 'demo.owner@example.test')->sole();
        $customer->forceFill(['name' => 'Changed Customer', 'password' => Hash::make('changed-customer-password')])->save();
        $owner->forceFill(['name' => 'Changed Organiser', 'password' => Hash::make('changed-owner-password')])->save();
        $customer->createToken('Existing demo client', ['*'], now()->addDay());
        EventType::where('slug', 'concert')->sole()->forceFill([
            'name' => 'Renamed catalogue label', 'description' => 'Edited catalogue details', 'is_active' => false,
        ])->save();
        $event = Event::where('name', 'Demo: Riverside Acoustic Night')->sole();
        $event->forceFill([
            'description' => 'Edited event details', 'venue' => 'Changed venue', 'capacity' => 1,
            'confirmed_count' => 1, 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHour(),
            'timezone' => 'America/New_York',
        ])->save();
        Event::where('name', 'Demo: Community Coffee Meetup')->sole()->forceFill([
            'status' => EventStatus::Published, 'event_type_id' => EventType::where('slug', 'workshop')->value('id'),
        ])->save();
        DB::table('reservations')->insert([
            'event_id' => $event->id, 'user_id' => $customer->id, 'status' => 'cancelled',
            'cancelled_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $waiter = User::factory()->create();
        DB::table('waitlist_entries')->insert([
            'event_id' => $event->id, 'user_id' => $waiter->id, 'status' => 'waiting', 'joined_at' => self::NOW,
            'promoted_at' => null, 'cancelled_at' => null, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $before = $this->snapshot();

        $this->travel(40)->days();
        $this->seed();
        $this->seed(DemoSeeder::class);

        $this->assertSame($before, $this->snapshot());
        $this->assertTrue(Hash::check('changed-customer-password', $customer->fresh()->password));
        $this->assertTrue(Hash::check('changed-owner-password', $owner->fresh()->password));
        $this->assertFalse(Hash::check('demo-password', $customer->fresh()->password));
        $this->assertSame(
            DB::table('reservations')->where('event_id', $event->id)->where('status', 'confirmed')->count(),
            $event->fresh()->confirmed_count,
        );
    }

    public function test_rerun_creates_missing_accounts_types_and_events_without_changing_surviving_records(): void
    {
        $this->seed();
        $type = EventType::where('slug', 'concert')->sole();
        $removedEventNames = $type->events()->pluck('name')->all();
        Reservation::whereIn('event_id', $type->events()->select('id'))->delete();
        $type->events()->delete();
        $type->delete();
        Reservation::where('user_id', User::where('email', 'demo.user@example.test')->value('id'))->delete();
        User::where('email', 'demo.user@example.test')->delete();
        $survivors = $this->snapshot();

        $this->travel(2)->days();
        $this->seed();

        foreach ($survivors as $table => $rows) {
            foreach ($rows as $row) {
                $this->assertSame($row, (array) DB::table($table)->find($row['id']));
            }
        }

        foreach ($removedEventNames as $name) {
            $this->assertSame(1, Event::where('name', $name)->count());
        }

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('event_types', 5);
        $this->assertDatabaseCount('events', 8);
        $this->assertDatabaseCount('reservations', 3);
        $this->assertTrue(EventType::where('slug', 'concert')->sole()->is_active);
        $this->assertTrue(Hash::check('demo-password', User::where('email', 'demo.user@example.test')->sole()->password));
    }

    public function test_renaming_an_event_preserves_it_and_creates_the_missing_original_fixture_identity(): void
    {
        $this->seed();
        $original = Event::where('name', 'Demo: Riverside Acoustic Night')->sole();
        $original->name = 'My renamed concert';
        $original->save();
        $before = (array) DB::table('events')->find($original->id);

        $this->seed();

        $this->assertSame($before, (array) DB::table('events')->find($original->id));
        $this->assertSame(1, Event::where('name', 'Demo: Riverside Acoustic Night')->count());
        $this->assertDatabaseCount('events', 9);
    }

    public function test_ambiguous_owner_and_event_name_refuses_without_selecting_an_arbitrary_fixture(): void
    {
        $this->seed();
        $event = Event::where('name', 'Demo: Riverside Acoustic Night')->sole();
        $duplicate = $event->replicate();
        $duplicate->confirmed_count = 0;
        $duplicate->save();
        User::where('email', 'demo.admin@example.test')->delete();
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(),
            'Multiple demo events match Demo: Riverside Acoustic Night; existing data was left unchanged.');

        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('forbiddenEnvironments')]
    public function test_default_command_and_direct_demo_class_refuse_nonlocal_environments_even_with_force(
        string $environment,
        bool $direct,
    ): void {
        $before = $this->snapshot();
        $this->app['env'] = $environment;

        try {
            $this->assertRejected(function () use ($direct): void {
                if ($direct) {
                    $this->app->make(DemoSeeder::class)->run();
                } else {
                    $this->artisan('db:seed', ['--force' => true])->run();
                }
            }, 'Demo data may only be seeded in local or testing environments.');
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame($before, $this->snapshot());
    }

    public static function forbiddenEnvironments(): array
    {
        return [
            'forced production command' => ['production', false],
            'forced staging command' => ['staging', false],
            'forced development command' => ['development', false],
            'direct production class' => ['production', true],
            'direct staging class' => ['staging', true],
            'direct development class' => ['development', true],
        ];
    }

    #[DataProvider('roleCollisions')]
    public function test_existing_demo_email_with_wrong_role_refuses_without_escalation_or_partial_writes(
        string $email,
        UserRole $existingRole,
        UserRole $expectedRole,
    ): void {
        $existing = User::factory()->create(['email' => $email, 'role' => $existingRole]);
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(), sprintf(
            'Demo account %s must have role %s; existing account was left unchanged.',
            $email, $expectedRole->value,
        ));

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($existingRole, $existing->fresh()->role);
    }

    public static function roleCollisions(): array
    {
        return [
            'customer identity is organiser' => ['demo.user@example.test', UserRole::Organiser, UserRole::RegularUser],
            'customer identity is admin' => ['demo.user@example.test', UserRole::Admin, UserRole::RegularUser],
            'owner identity is customer' => ['demo.owner@example.test', UserRole::RegularUser, UserRole::Organiser],
            'owner identity is admin' => ['demo.owner@example.test', UserRole::Admin, UserRole::Organiser],
            'admin identity is customer' => ['demo.admin@example.test', UserRole::RegularUser, UserRole::Admin],
            'admin identity is organiser' => ['demo.admin@example.test', UserRole::Organiser, UserRole::Admin],
            'first attendee is organiser' => ['demo.attendee.one@example.test', UserRole::Organiser, UserRole::RegularUser],
            'second attendee is admin' => ['demo.attendee.two@example.test', UserRole::Admin, UserRole::RegularUser],
        ];
    }

    public function test_missing_event_with_retired_type_refuses_and_rolls_back_other_missing_fixtures(): void
    {
        $this->seed();
        Event::where('name', 'Demo: Garden Session')->delete();
        EventType::where('slug', 'concert')->sole()->forceFill(['is_active' => false])->save();
        User::where('email', 'demo.admin@example.test')->delete();
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(),
            'Cannot create demo event Demo: Garden Session: event type concert is inactive.');

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('users', ['email' => 'demo.admin@example.test']);
        $this->assertSame(0, Event::where('name', 'Demo: Garden Session')->count());
    }

    public function test_late_event_creation_failure_rolls_back_accounts_types_and_preceding_events(): void
    {
        $created = 0;
        EventDispatcher::listen('eloquent.creating: '.Event::class, function () use (&$created): void {
            if (++$created === 4) {
                throw new RuntimeException('Simulated late demo event failure');
            }
        });
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(), 'Simulated late demo event failure');

        $this->assertSame(4, $created);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('observerVetoes')]
    public function test_observer_veto_is_reported_and_rolls_back_the_entire_seed(
        string $model,
        int $vetoAt,
        string $message,
    ): void {
        $created = 0;
        EventDispatcher::listen('eloquent.creating: '.$model, function () use (&$created, $vetoAt): ?bool {
            return ++$created === $vetoAt ? false : null;
        });
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(), $message);

        $this->assertSame($vetoAt, $created);
        $this->assertSame($before, $this->snapshot());
    }

    public static function observerVetoes(): array
    {
        return [
            'owner creation veto' => [User::class, 2, 'Could not create demo account demo.owner@example.test.'],
            'type creation veto' => [EventType::class, 3, 'Could not create demo event type meetup.'],
            'late event creation veto' => [Event::class, 4, 'Could not create demo event Demo: Garden Session.'],
        ];
    }
}
