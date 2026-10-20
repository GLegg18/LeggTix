<?php

namespace Tests\Feature;

use App\Actions\ReserveEventAction;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 14)->setTime(12, 0, 0, 123456));
    }

    public function test_guest_list_contains_only_strictly_future_published_events_in_stable_order(): void
    {
        Event::factory()->draft()->create();
        Event::factory()->cancelled()->create();
        Event::factory()->completed()->create();
        Event::factory()->past()->create();
        Event::factory()->published()->create(['starts_at' => now('UTC')]);
        Event::factory()->published()->create(['starts_at' => now('UTC')->subMicrosecond()]);
        $later = Event::factory()->published()->create(['starts_at' => now('UTC')->addDay()]);
        $first = Event::factory()->published()->create(['starts_at' => now('UTC')->addMicrosecond()]);
        $tied = Event::factory()->published()->create(['starts_at' => $first->starts_at]);

        $response = $this->get('/api/events')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame([$first->id, $tied->id, $later->id], array_column($response->json('data'), 'id'));
        $this->assertSame(20, $response->json('meta.per_page'));
        $this->assertNull($response->json('links.next'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_guest_detail_and_list_share_an_explicit_public_resource_and_retired_type_remains_visible(): void
    {
        // Booking uses real database time; the serialization fixture must remain
        // bookable even after the separate frozen PHP calendar date has passed.
        $futureYear = (int) DB::scalar('SELECT YEAR(UTC_TIMESTAMP())') + 1;
        $type = EventType::factory()->inactive()->create(['slug' => 'live-show', 'name' => 'Live show']);
        $event = Event::factory()->for($type, 'eventType')->published()->create([
            'name' => 'Public concert', 'description' => 'Event information', 'venue' => 'Town Hall',
            'starts_at' => $futureYear.'-07-15T19:30:00.123456+01:00',
            'ends_at' => $futureYear.'-07-15T21:30:00.654321+01:00',
            'timezone' => 'Europe/London', 'capacity' => 2,
        ]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $expected = [
            'id' => $event->id, 'name' => 'Public concert', 'description' => 'Event information',
            'venue' => 'Town Hall', 'starts_at' => $futureYear.'-07-15T18:30:00.123456Z',
            'ends_at' => $futureYear.'-07-15T20:30:00.654321Z', 'timezone' => 'Europe/London',
            'capacity' => 2, 'remaining_capacity' => 1, 'status' => 'published',
            'event_type' => ['id' => $type->id, 'slug' => 'live-show', 'name' => 'Live show'],
        ];

        $this->get('/api/events/'.$event->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => $expected]);
        $this->assertSame([$expected], $this->get('/api/events')->assertOk()->json('data'));

        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $this->get('/api/events/'.$event->id)->assertOk()->assertJsonPath('data.remaining_capacity', 0);
        $this->get('/api/events')->assertOk()->assertJsonPath('data.0.remaining_capacity', 0);
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_past_published_detail_remains_public_but_is_absent_from_list_and_nullable_end_is_preserved(): void
    {
        $past = Event::factory()->past()->create(['ends_at' => null]);
        $startingNow = Event::factory()->published()->create(['starts_at' => now('UTC')]);

        foreach ([$past, $startingNow] as $event) {
            $this->get('/api/events/'.$event->id)->assertOk()->assertJsonPath('data.id', $event->id)
                ->assertJsonPath('data.ends_at', null);
        }
        $this->get('/api/events')->assertOk()->assertJsonPath('data', []);
    }

    #[DataProvider('hiddenStatuses')]
    public function test_hidden_status_detail_matches_missing_not_found_response(string $status): void
    {
        $event = Event::factory()->state(['status' => $status])->create();
        $missing = $this->get('/api/events/999999')->assertNotFound()->json();

        $this->get('/api/events/'.$event->id)->assertNotFound()->assertExactJson($missing);
        $this->get('/api/events')->assertOk()->assertJsonPath('data', []);
    }

    public static function hiddenStatuses(): array
    {
        return ['draft' => ['draft'], 'cancelled' => ['cancelled'], 'completed' => ['completed']];
    }

    public function test_missing_or_malformed_detail_ids_are_json_not_found_without_accept_header(): void
    {
        foreach (['999999', 'not-an-event', '-1', '1.5', '99999999999999999999999999999'] as $id) {
            $this->get('/api/events/'.$id)->assertNotFound()->assertJsonStructure(['message']);
        }
    }

    public function test_cursor_pages_preserve_microseconds_and_tied_ids_in_both_directions(): void
    {
        $first = Event::factory()->published()->create(['starts_at' => '2026-10-15 12:00:00.100001']);
        $second = Event::factory()->published()->create(['starts_at' => '2026-10-15 12:00:00.100002']);
        $third = Event::factory()->published()->create(['starts_at' => $second->starts_at]);
        $fourth = Event::factory()->published()->create(['starts_at' => '2026-10-15 12:00:00.100003']);

        $pageOne = $this->get('/api/events?per_page=2')->assertOk();
        $this->assertSame([$first->id, $second->id], array_column($pageOne->json('data'), 'id'));
        $this->assertNull($pageOne->json('links.prev'));
        $this->assertStringContainsString('per_page=2', $pageOne->json('links.next'));
        $pageTwo = $this->get($pageOne->json('links.next'))->assertOk();
        $this->assertSame([$third->id, $fourth->id], array_column($pageTwo->json('data'), 'id'));
        $this->assertNull($pageTwo->json('links.next'));
        $back = $this->get($pageTwo->json('links.prev'))->assertOk();
        $this->assertSame([$first->id, $second->id], array_column($back->json('data'), 'id'));
    }

    public function test_default_page_is_bounded_and_empty_page_has_standard_cursor_metadata(): void
    {
        $empty = $this->get('/api/events')->assertOk();
        $this->assertSame(['data', 'links', 'meta'], array_keys($empty->json()));
        $empty->assertJsonPath('data', [])->assertJsonPath('links.next', null)
            ->assertJsonPath('links.prev', null)->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.prev_cursor', null)->assertJsonPath('meta.per_page', 20);
        Event::factory()->published()->count(21)->create();
        $this->get('/api/events')->assertOk()->assertJsonCount(20, 'data');
        $this->get('/api/events?per_page=100')->assertOk()->assertJsonCount(21, 'data');
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_pagination_or_unsupported_query_is_validation_error(string $query, string $field): void
    {
        $this->get('/api/events?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidQueries(): array
    {
        $encode = fn (mixed $value): string => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $valid = ['cursor_starts_at' => '2026-10-15 12:00:00.123456', 'id' => 1, '_pointsToNextItems' => true];

        return [
            'zero' => ['per_page=0', 'per_page'],
            'negative' => ['per_page=-1', 'per_page'],
            'over limit' => ['per_page=101', 'per_page'],
            'fraction' => ['per_page=1.5', 'per_page'],
            'text' => ['per_page=all', 'per_page'],
            'empty size' => ['per_page=', 'per_page'],
            'array size' => ['per_page[]=2', 'per_page'],
            'unsupported status' => ['status=draft', 'status'],
            'unsupported page' => ['page=2', 'page'],
            'empty cursor' => ['cursor=', 'cursor'],
            'array cursor' => ['cursor[]=abc', 'cursor'],
            'malformed encoding' => ['cursor=%25%25%25', 'cursor'],
            'overlong cursor' => ['cursor='.str_repeat('a', 513), 'cursor'],
            'scalar cursor' => ['cursor='.$encode('cursor'), 'cursor'],
            'missing cursor keys' => ['cursor='.$encode(['_pointsToNextItems' => true]), 'cursor'],
            'extra cursor key' => ['cursor='.$encode($valid + ['status' => 'draft']), 'cursor'],
            'invalid date' => ['cursor='.$encode(array_replace($valid, ['cursor_starts_at' => '2026-02-30 00:00:00.000000'])), 'cursor'],
            'missing microseconds' => ['cursor='.$encode(array_replace($valid, ['cursor_starts_at' => '2026-10-15 12:00:00'])), 'cursor'],
            'array date' => ['cursor='.$encode(array_replace($valid, ['cursor_starts_at' => []])), 'cursor'],
            'negative id' => ['cursor='.$encode(array_replace($valid, ['id' => -1])), 'cursor'],
            'array id' => ['cursor='.$encode(array_replace($valid, ['id' => []])), 'cursor'],
            'invalid direction' => ['cursor='.$encode(array_replace($valid, ['_pointsToNextItems' => 'false'])), 'cursor'],
        ];
    }

    public function test_forged_valid_cursor_cannot_include_hidden_or_past_events(): void
    {
        Event::factory()->draft()->create();
        Event::factory()->past()->create();
        $future = Event::factory()->published()->create();
        $cursor = (new Cursor(['cursor_starts_at' => '2000-01-01 00:00:00.000000', 'id' => 1]))->encode();

        $this->get('/api/events?cursor='.$cursor)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $future->id);
    }

    public function test_json_get_body_cannot_bypass_validated_query_pagination(): void
    {
        $event = Event::factory()->published()->create();

        $this->json('GET', '/api/events?per_page=1', ['per_page' => 100000, 'cursor' => ['id' => 1]])
            ->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.id', $event->id);
    }

    public function test_list_eager_loads_types_without_counting_or_loading_attendees(): void
    {
        Event::factory()->published()->count(3)->create();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->get('/api/events')->assertOk()->assertJsonCount(3, 'data');

        $this->assertCount(2, $queries);
        $this->assertStringContainsString('from `events`', $queries[0]);
        $this->assertStringContainsString('from `event_types`', $queries[1]);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('count(', $query);
            $this->assertStringNotContainsString('reservations', $query);
            $this->assertStringNotContainsString('waitlist_entries', $query);
        }
    }
}
