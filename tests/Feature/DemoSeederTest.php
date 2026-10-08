<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-08 12:00:00.123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
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

    #[DataProvider('allowedEnvironments')]
    public function test_default_seeder_creates_valid_demo_accounts_catalogue_and_events_only(string $environment): void
    {
        $this->app['env'] = $environment;

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->app['env'] = 'testing';
        }

        $customer = User::where('email', 'demo.user@example.test')->sole();
        $owner = User::where('email', 'demo.owner@example.test')->sole();

        $this->assertSame(UserRole::RegularUser, $customer->role);
        $this->assertSame(UserRole::Organiser, $owner->role);
        $this->assertNotSame($customer->id, $owner->id);

        foreach ([$customer, $owner] as $user) {
            $this->assertNotSame('demo-password', $user->password);
            $this->assertTrue(Hash::check('demo-password', $user->password));
            $this->assertSame(self::NOW, $user->created_at->format('Y-m-d H:i:s.u'));
        }

        $this->assertEqualsCanonicalizing(['concert', 'workshop', 'meetup'], EventType::pluck('slug')->all());
        $this->assertSame(3, EventType::where('is_active', true)->count());

        $expected = [
            'Demo: Riverside Acoustic Night' => [EventStatus::Published, 'concert', false, true],
            'Demo: Laravel Makers Workshop' => [EventStatus::Published, 'workshop', false, true],
            'Demo: Community Coffee Meetup' => [EventStatus::Draft, 'meetup', false, false],
            'Demo: Garden Session' => [EventStatus::Cancelled, 'concert', false, false],
            'Demo: Intro to Web APIs' => [EventStatus::Completed, 'workshop', true, false],
            "Demo: Last Week's Community Meetup" => [EventStatus::Published, 'meetup', true, false],
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
            $this->assertSame(0, $event->confirmed_count);
            $this->assertNotEmpty($event->description);
            $this->assertNotEmpty($event->venue);
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('event_types', 3);
        $this->assertDatabaseCount('events', 6);
        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('waitlist_entries', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, $customer->ownedEvents()->count());
        $this->assertSame(6, $owner->ownedEvents()->count());
    }

    public static function allowedEnvironments(): array
    {
        return [['local'], ['testing']];
    }

    public function test_seeded_customer_and_owner_can_login_through_the_existing_api_with_their_own_roles(): void
    {
        $this->seed();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        foreach ([
            'demo.user@example.test' => 'regular_user',
            'demo.owner@example.test' => 'organiser',
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

        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
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
            'event_id' => $event->id, 'user_id' => $customer->id, 'status' => 'confirmed',
            'cancelled_at' => null, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
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
        $type->events()->delete();
        $type->delete();
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

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('event_types', 3);
        $this->assertDatabaseCount('events', 6);
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
        $this->assertDatabaseCount('events', 7);
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
        ];
    }

    public function test_missing_event_with_retired_type_refuses_and_rolls_back_other_missing_fixtures(): void
    {
        $this->seed();
        Event::where('name', 'Demo: Garden Session')->delete();
        EventType::where('slug', 'concert')->sole()->forceFill(['is_active' => false])->save();
        User::where('email', 'demo.user@example.test')->delete();
        $before = $this->snapshot();

        $this->assertRejected(fn () => $this->seed(),
            'Cannot create demo event Demo: Garden Session: event type concert is inactive.');

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('users', ['email' => 'demo.user@example.test']);
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
