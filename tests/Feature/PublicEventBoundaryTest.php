<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicEventBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('hiddenStatuses')]
    public function test_public_routes_hide_nonpublic_events_even_from_owner_and_admin(string $status): void
    {
        $owner = User::factory()->organiser()->create();
        $hidden = Event::factory()->for($owner, 'owner')->create(['status' => $status]);
        $published = Event::factory()->published()->create();

        foreach ([$owner, User::factory()->admin()->create(), User::factory()->create()] as $actor) {
            $headers = ['Authorization' => 'Bearer '.$actor->createToken('public-event-boundary')->plainTextToken];
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/user', $headers)->assertOk()->assertJsonPath('data.id', $actor->id);
            $missing = $this->getJson('/api/events/999999', $headers)->assertNotFound()->json();

            $this->getJson('/api/events/'.$hidden->id, $headers)->assertNotFound()->assertExactJson($missing);
            $this->getJson('/api/events', $headers)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $published->id);
        }
    }

    public static function hiddenStatuses(): array
    {
        return [['draft'], ['cancelled'], ['completed']];
    }

    public function test_cursor_rechecks_publication_and_time_after_the_previous_page_was_read(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 14)->setTime(12, 0, 0, 123456));
        $anchor = Event::factory()->published()->create(['starts_at' => now('UTC')->addHours(1)]);
        $cancelled = Event::factory()->published()->create(['starts_at' => now('UTC')->addHours(2)]);
        $expired = Event::factory()->published()->create(['starts_at' => now('UTC')->addHours(3)]);
        $visible = Event::factory()->published()->create(['starts_at' => now('UTC')->addHours(4)]);
        $page = $this->getJson('/api/events?per_page=1')->assertOk()->assertJsonPath('data.0.id', $anchor->id);

        // Simulate publication and schedule changes committed between requests.
        $anchor->forceFill(['status' => EventStatus::Cancelled])->saveOrFail();
        $cancelled->forceFill(['status' => EventStatus::Cancelled])->saveOrFail();
        $expired->starts_at = now('UTC');
        $expired->saveOrFail();

        $this->getJson($page->json('links.next'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)->assertJsonPath('links.next', null);
        $this->getJson('/api/events/'.$cancelled->id)->assertNotFound();
        $this->getJson('/api/events/'.$expired->id)->assertOk()->assertJsonPath('data.id', $expired->id);
    }

    public function test_repeated_public_reads_reflect_real_booking_and_leave_inventory_and_queues_unchanged(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $actor = User::factory()->create();
        $token = $actor->createToken('event-capacity-review')->plainTextToken;
        $uri = '/api/events/'.$event->id;
        $this->call('POST', $uri.'/reservations', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], '{}')->assertCreated();
        $before = [];
        foreach (['events', 'reservations', 'waitlist_entries'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Queue::fake();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->getJson($uri)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.remaining_capacity', 0);
            $this->getJson('/api/events')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.remaining_capacity', 0);
        }
        $this->getJson('/api/events?per_page=0')->assertUnprocessable();
        $this->getJson('/api/events/999999')->assertNotFound();

        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table.' changed during public reads');
        }
        Queue::assertNothingPushed();
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame(1, $event->reservations()->where('status', 'confirmed')->count());
    }
}
