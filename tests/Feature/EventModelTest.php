<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventModelTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-07 12:00:00.123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    #[DataProvider('factoryStates')]
    public function test_event_factory_states_persist_valid_records_with_expected_schedule_and_status(
        ?string $state,
        EventStatus $status,
        bool $past,
        bool $bookable,
    ): void {
        $factory = Event::factory();

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        $event = $factory->create()->fresh();

        $this->assertSame($status, $event->status);
        $this->assertSame($past, $event->isPast());
        $this->assertSame($bookable, $event->isBookable());
        $this->assertSame(UserRole::Organiser, $event->owner->role);
        $this->assertTrue($event->eventType->is_active);
        $this->assertGreaterThan(0, $event->capacity);
        $this->assertIsInt($event->capacity);
        $this->assertSame(0, $event->confirmed_count);
        $this->assertContains($event->timezone, timezone_identifiers_list());
        $this->assertNotEmpty($event->name);
        $this->assertNotEmpty($event->description);
        $this->assertNotEmpty($event->venue);
        $this->assertInstanceOf(CarbonImmutable::class, $event->starts_at);
        $this->assertSame('UTC', $event->starts_at->timezoneName);
        $this->assertInstanceOf(CarbonImmutable::class, $event->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $event->updated_at);
        $this->assertSame(self::NOW, $event->created_at->format('Y-m-d H:i:s.u'));
        $this->assertSame(self::NOW, $event->updated_at->format('Y-m-d H:i:s.u'));

        if ($past) {
            $this->assertInstanceOf(CarbonImmutable::class, $event->ends_at);
            $this->assertTrue($event->ends_at->greaterThan($event->starts_at));
            $this->assertTrue($event->ends_at->lessThan(now('UTC')));
        } else {
            $this->assertNull($event->ends_at);
        }

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'owner_id' => $event->owner->id,
            'event_type_id' => $event->eventType->id,
            'status' => $status->value,
            'confirmed_count' => 0,
        ]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('event_types', 1);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public static function factoryStates(): array
    {
        return [
            'default draft' => [null, EventStatus::Draft, false, false],
            'explicit draft' => ['draft', EventStatus::Draft, false, false],
            'published' => ['published', EventStatus::Published, false, true],
            'cancelled' => ['cancelled', EventStatus::Cancelled, false, false],
            'completed' => ['completed', EventStatus::Completed, true, false],
            'past published' => ['past', EventStatus::Published, true, false],
        ];
    }

    public function test_owner_and_event_type_relationships_support_reused_parents_and_empty_collections(): void
    {
        $owner = User::factory()->organiser()->create();
        $otherOwner = User::factory()->organiser()->create();
        $emptyOwner = User::factory()->create();
        $type = EventType::factory()->create();
        $otherType = EventType::factory()->create();
        $emptyType = EventType::factory()->create();

        $first = Event::factory()->for($owner, 'owner')->for($type, 'eventType')->create();
        $second = Event::factory()->for($owner, 'owner')->for($otherType, 'eventType')->create();
        $third = Event::factory()->for($otherOwner, 'owner')->for($type, 'eventType')->create();

        $first->refresh()->load(['owner', 'eventType']);
        $this->assertTrue($first->owner->is($owner));
        $this->assertTrue($first->eventType->is($type));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $owner->ownedEvents->modelKeys());
        $this->assertSame([$third->id], $otherOwner->ownedEvents->modelKeys());
        $this->assertEqualsCanonicalizing([$first->id, $third->id], $type->events->modelKeys());
        $this->assertSame([$second->id], $otherType->events->modelKeys());
        $this->assertTrue($emptyOwner->ownedEvents->isEmpty());
        $this->assertTrue($emptyType->events->isEmpty());
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('event_types', 3);
    }

    public function test_event_fill_changes_listing_fields_but_cannot_assign_owner_status_counter_or_timestamps(): void
    {
        $event = Event::factory()->create();
        $otherOwner = User::factory()->admin()->create();
        $otherType = EventType::factory()->create();
        $id = $event->id;
        $ownerId = $event->owner_id;
        $createdAt = $event->created_at;

        $event->fill([
            'id' => 999999,
            'owner_id' => $otherOwner->id,
            'status' => EventStatus::Published,
            'confirmed_count' => 2,
            'created_at' => '1999-01-01 00:00:00',
            'updated_at' => '1999-01-01 00:00:00',
            'event_type_id' => $otherType->id,
            'name' => 'Changed event title',
            'description' => 'Changed description',
            'venue' => 'Changed hall',
            'starts_at' => '2026-11-01 10:00:00.123456',
            'ends_at' => '2026-11-01 11:00:00.123456',
            'timezone' => 'America/New_York',
            'capacity' => 3,
        ])->save();

        $event->refresh();
        $this->assertSame($id, $event->id);
        $this->assertSame($ownerId, $event->owner_id);
        $this->assertSame(EventStatus::Draft, $event->status);
        $this->assertSame(0, $event->confirmed_count);
        $this->assertTrue($event->created_at->equalTo($createdAt));
        $this->assertSame(self::NOW, $event->updated_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($otherType->id, $event->event_type_id);
        $this->assertSame('Changed event title', $event->name);
        $this->assertSame('Changed description', $event->description);
        $this->assertSame('Changed hall', $event->venue);
        $this->assertSame('2026-11-01 10:00:00.123456', $event->starts_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-11-01 11:00:00.123456', $event->ends_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertSame(3, $event->capacity);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_event_dates_preserve_offset_instants_and_microseconds_as_immutable_utc_values(): void
    {
        $start = CarbonImmutable::parse('2026-11-01T10:00:00.654321+05:30');
        $event = Event::factory()->create([
            'starts_at' => $start,
            'ends_at' => '2026-11-01T11:00:00.654322+05:30',
            'timezone' => 'Asia/Kolkata',
        ])->fresh();

        $this->assertSame('2026-11-01 04:30:00.654321', DB::table('events')->find($event->id)->starts_at);
        $this->assertSame('2026-11-01 05:30:00.654322', DB::table('events')->find($event->id)->ends_at);
        $this->assertSame('UTC', $event->starts_at->timezoneName);
        $this->assertSame('UTC', $event->ends_at->timezoneName);
        $this->assertInstanceOf(CarbonImmutable::class, $event->ends_at);
        $this->assertTrue($event->starts_at->equalTo($start));
        $this->assertSame('2026-11-01 10:00:00.654321 +05:30', $start->format('Y-m-d H:i:s.u P'));
        $this->assertSame('2026-11-01T04:30:00.654321Z', $event->toArray()['starts_at']);
        $this->assertSame('2026-11-01T05:30:00.654322Z', $event->toArray()['ends_at']);

        $event->starts_at->addHour();
        $this->assertSame('2026-11-01 04:30:00.654321', $event->starts_at->format('Y-m-d H:i:s.u'));
        $event->ends_at = null;
        $event->save();
        $this->assertNull($event->fresh()->ends_at);
    }

    #[DataProvider('eligibilityBoundaries')]
    public function test_schedule_helpers_observe_exact_start_cutoff_without_changing_persisted_status(
        EventStatus $status,
        string $start,
        bool $past,
        bool $bookable,
    ): void {
        $event = Event::factory()->create(['status' => $status, 'starts_at' => $start]);

        $this->assertSame($past, $event->isPast());
        $this->assertSame($bookable, $event->isBookable());
        $this->assertSame($status, $event->fresh()->status);
    }

    public static function eligibilityBoundaries(): array
    {
        $cases = [];

        foreach (EventStatus::cases() as $status) {
            $cases[$status->value.' before cutoff'] = [$status, '2026-10-07 12:00:00.123455', true, false];
            $cases[$status->value.' at cutoff'] = [$status, self::NOW, true, false];
            $cases[$status->value.' after cutoff'] = [$status, '2026-10-07 12:00:00.123457', false, $status === EventStatus::Published];
        }

        return $cases;
    }

    public function test_schedule_helpers_return_false_for_an_unscheduled_model(): void
    {
        $event = new Event;
        $event->status = EventStatus::Published;

        $this->assertFalse($event->isPast());
        $this->assertFalse($event->isBookable());
    }

    public function test_upcoming_scope_matches_published_future_eligibility_at_microsecond_cutoff(): void
    {
        $first = Event::factory()->published()->create(['starts_at' => '2026-10-07 12:00:00.123457']);
        $later = Event::factory()->published()->create(['starts_at' => '2026-10-08 12:00:00']);
        Event::factory()->published()->create(['starts_at' => '2026-10-07 12:00:00.123455']);
        Event::factory()->published()->create(['starts_at' => self::NOW]);
        Event::factory()->past()->create();
        Event::factory()->draft()->create();
        Event::factory()->cancelled()->create();
        Event::factory()->completed()->create();

        $upcoming = Event::upcoming()->orderBy('starts_at')->orderBy('id')->get();

        $this->assertSame([$first->id, $later->id], $upcoming->modelKeys());
        $this->assertTrue($upcoming->every(fn (Event $event): bool => $event->isBookable()));
        $this->assertSame([$later->id], Event::upcoming()->where('owner_id', $later->owner_id)->pluck('id')->all());
    }

    public function test_retired_event_type_does_not_remove_existing_published_events_from_schedule_eligibility(): void
    {
        $type = EventType::factory()->inactive()->create();
        $event = Event::factory()->published()->for($type, 'eventType')->create(['capacity' => 1, 'confirmed_count' => 1]);

        // Schedule eligibility is not an inventory allocation or publication authorization.
        $this->assertTrue($event->isBookable());
        $this->assertSame([$event->id], Event::upcoming()->pluck('id')->all());
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    #[DataProvider('invalidCapacities')]
    public function test_model_rejects_invalid_capacity_before_persistence(mixed $capacity): void
    {
        $event = Event::factory()->create(['capacity' => 2]);

        try {
            $event->capacity = $capacity;
            $event->save();
            $this->fail('Invalid capacity must raise validation errors.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }

        $this->assertSame(2, $event->fresh()->capacity);
        $this->assertDatabaseCount('events', 1);
    }

    public static function invalidCapacities(): array
    {
        return [
            'missing' => [null],
            'zero' => [0],
            'negative' => [-1],
            'overflow' => [4294967296],
            'fraction' => [1.5],
            'whole float' => [1.0],
            'numeric string' => ['2'],
            'boolean' => [true],
            'array' => [[]],
        ];
    }

    public function test_model_persists_minimum_and_unsigned_maximum_capacity_without_rounding(): void
    {
        $minimum = Event::factory()->create(['capacity' => 1]);
        $maximum = Event::factory()->create(['capacity' => 4294967295]);

        $this->assertSame(1, $minimum->fresh()->capacity);
        $this->assertSame(4294967295, $maximum->fresh()->capacity);
    }

    #[DataProvider('invalidTimezones')]
    public function test_model_rejects_invalid_timezone_without_changing_persisted_event(mixed $timezone): void
    {
        $event = Event::factory()->create(['timezone' => 'Europe/London']);

        try {
            $event->timezone = $timezone;
            $event->save();
            $this->fail('Invalid timezone must raise validation errors.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timezone', $exception->errors());
        }

        $this->assertSame('Europe/London', $event->fresh()->timezone);
    }

    public static function invalidTimezones(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'unknown' => ['Not/A_Timezone'],
            'offset' => ['+01:00'],
            'overlong' => [str_repeat('a', 65)],
            'integer' => [123],
            'array' => [[]],
        ];
    }

    public function test_event_type_factories_persist_distinct_slugs_boolean_activity_and_nullable_description(): void
    {
        $active = EventType::factory()->create(['description' => null]);
        $inactive = EventType::factory()->inactive()->create();
        $more = EventType::factory()->count(3)->create();

        $this->assertTrue($active->fresh()->is_active);
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertNull($active->fresh()->description);
        $this->assertCount(5, EventType::query()->pluck('slug')->unique());

        foreach (collect([$active, $inactive])->concat($more) as $type) {
            $this->assertLessThanOrEqual(64, strlen($type->slug));
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $type->slug);
            $this->assertNotEmpty($type->name);
            $this->assertInstanceOf(CarbonImmutable::class, $type->fresh()->created_at);
            $this->assertSame(self::NOW, $type->created_at->format('Y-m-d H:i:s.u'));
            $this->assertSame(self::NOW, $type->updated_at->format('Y-m-d H:i:s.u'));
        }
    }

    public function test_event_type_catalogue_fields_cannot_be_mass_assigned(): void
    {
        $type = EventType::factory()->create(['slug' => 'original-concert', 'name' => 'Concert']);

        try {
            $type->fill(['slug' => 'client-slug', 'name' => 'Client title', 'is_active' => false])->save();
            $this->fail('Catalogue fields must remain guarded until an authorized management workflow exists.');
        } catch (MassAssignmentException $exception) {
            $this->assertStringContainsString(EventType::class, $exception->getMessage());
        }

        $this->assertDatabaseHas('event_types', [
            'id' => $type->id, 'slug' => 'original-concert', 'name' => 'Concert', 'is_active' => true,
        ]);
        $this->assertDatabaseCount('event_types', 1);
    }

    public function test_event_type_timestamps_preserve_offset_instants_in_utc_with_microseconds(): void
    {
        $type = EventType::factory()->create([
            'created_at' => CarbonImmutable::parse('2026-10-06T10:00:00.654321+05:30'),
            'updated_at' => '2026-10-07T11:00:00.654322+05:30',
        ])->fresh();

        $row = DB::table('event_types')->find($type->id);
        $this->assertSame('2026-10-06 04:30:00.654321', $row->created_at);
        $this->assertSame('2026-10-07 05:30:00.654322', $row->updated_at);
        $this->assertSame('UTC', $type->created_at->timezoneName);
        $this->assertSame('UTC', $type->updated_at->timezoneName);
        $this->assertSame('2026-10-06T04:30:00.654321Z', $type->toArray()['created_at']);
        $this->assertSame('2026-10-07T05:30:00.654322Z', $type->toArray()['updated_at']);
    }
}
