<?php

namespace Tests\Feature;

use App\Actions\ReserveEventAction;
use App\Enums\EventStatus;
use App\Enums\ReservationRejection;
use App\Enums\ReservationStatus;
use App\Exceptions\ReservationRejectedException;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReservationBookingTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('bookingRoles')]
    public function test_each_role_can_book_one_available_place_with_exact_ownership_and_counter(string $role): void
    {
        Queue::fake();
        Notification::fake();
        $actor = User::factory()->state(['role' => $role])->create();
        $event = Event::factory()->published()->create(['capacity' => 2]);

        $reservation = app(ReserveEventAction::class)->execute($actor, $event->id);

        $this->assertTrue($reservation->wasRecentlyCreated);
        $this->assertSame($event->id, $reservation->event_id);
        $this->assertSame($actor->id, $reservation->user_id);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNull($reservation->cancelled_at);
        $this->assertTrue($reservation->event->is($event));
        $this->assertTrue($reservation->user->is($actor));
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame(1, $event->reservations()->where('status', 'confirmed')->count());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('waitlist_entries', 0);
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public static function bookingRoles(): array
    {
        return ['customer' => ['regular_user'], 'organiser' => ['organiser'], 'admin' => ['admin']];
    }

    public function test_full_event_refuses_another_actor_without_counter_drift_or_implicit_waitlist(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $first = app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);

        $this->assertRejected(ReservationRejection::Full, User::factory()->create(), $event);

        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame([$first->id], $event->reservations()->pluck('id')->all());
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_committed_request_replay_refuses_duplicate_without_an_extra_place(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 2]);
        $actor = User::factory()->create();
        $first = app(ReserveEventAction::class)->execute($actor, $event->id);

        $this->assertRejected(ReservationRejection::AlreadyReserved, $actor, $event);

        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame([$first->id], $actor->reservations()->pluck('id')->all());
    }

    #[DataProvider('ineligibleEvents')]
    public function test_only_persisted_published_future_events_accept_bookings(EventStatus $status, int $seconds): void
    {
        $event = Event::factory()->create(['status' => $status]);
        DB::table('events')->where('id', $event->id)->update([
            'starts_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL '.$seconds.' SECOND)'),
        ]);

        $this->assertRejected(ReservationRejection::EventUnavailable, User::factory()->create(), $event);

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function ineligibleEvents(): array
    {
        return [
            'draft future' => [EventStatus::Draft, 3600],
            'cancelled future' => [EventStatus::Cancelled, 3600],
            'completed future' => [EventStatus::Completed, 3600],
            'published past' => [EventStatus::Published, -1],
            'published at cutoff' => [EventStatus::Published, 0],
        ];
    }

    public function test_stale_in_memory_event_cannot_bypass_persisted_cancellation(): void
    {
        $event = Event::factory()->published()->create();
        DB::table('events')->where('id', $event->id)->update(['status' => 'cancelled']);

        $this->assertSame(EventStatus::Published, $event->status);
        $this->assertRejected(ReservationRejection::EventUnavailable, User::factory()->create(), $event);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_retired_type_does_not_invalidate_existing_published_event(): void
    {
        $event = Event::factory()->published()->for(EventType::factory()->inactive(), 'eventType')->create();

        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);

        $this->assertSame(1, $event->fresh()->confirmed_count);
    }

    public function test_cancelled_history_allows_new_attempt_id_and_preserves_previous_attempt(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $history = Reservation::factory()->cancelled()->for($actor)->for($event)->create();

        $new = app(ReserveEventAction::class)->execute($actor, $event->id);

        $this->assertNotSame($history->id, $new->id);
        $this->assertSame(ReservationStatus::Cancelled, $history->fresh()->status);
        $this->assertNotNull($history->fresh()->cancelled_at);
        $this->assertSame(ReservationStatus::Confirmed, $new->status);
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_active_waitlisted_actor_cannot_become_both_waiting_and_confirmed(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        $this->waitlist($event, $actor);

        $this->assertRejected(ReservationRejection::AlreadyWaitlisted, $actor, $event);

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseHas('waitlist_entries', ['event_id' => $event->id, 'user_id' => $actor->id, 'status' => 'waiting']);
    }

    public function test_newcomer_cannot_take_free_place_ahead_of_waiting_backlog(): void
    {
        $event = Event::factory()->published()->create();
        $this->waitlist($event, User::factory()->create());

        $this->assertRejected(ReservationRejection::QueueHasPriority, User::factory()->create(), $event);

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('waitlist_entries', 1);
    }

    public function test_terminal_waitlist_history_does_not_block_new_booking(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        $this->waitlist($event, $actor, 'cancelled');
        $this->waitlist($event, User::factory()->create(), 'promoted');

        app(ReserveEventAction::class)->execute($actor, $event->id);

        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('waitlist_entries', 2);
    }

    public function test_missing_event_is_not_found_and_creates_no_domain_state(): void
    {
        try {
            app(ReserveEventAction::class)->execute(User::factory()->create(), 999999);
            $this->fail('Missing events must throw ModelNotFoundException.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Event::class, $exception->getModel());
        }

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_insert_failure_rolls_back_counter_after_guarded_increment(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $actor = User::factory()->create();
        $observedCount = null;
        Reservation::creating(function () use ($event, &$observedCount): void {
            $observedCount = Event::findOrFail($event->id)->confirmed_count;
            throw new RuntimeException('Injected reservation insertion failure');
        });

        try {
            app(ReserveEventAction::class)->execute($actor, $event->id);
            $this->fail('Injected insertion failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected reservation insertion failure', $exception->getMessage());
        } finally {
            Reservation::flushEventListeners();
        }

        $this->assertSame(1, $observedCount, 'Failure must occur after allocation, not before it.');
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
        $replacement = app(ReserveEventAction::class)->execute($actor, $event->id);
        $this->assertSame(ReservationStatus::Confirmed, $replacement->status);
        $this->assertSame(1, $event->fresh()->confirmed_count);
    }

    private function assertRejected(ReservationRejection $reason, User $actor, Event $event): void
    {
        try {
            app(ReserveEventAction::class)->execute($actor, $event->id);
            $this->fail('Booking must reject with '.$reason->value.'.');
        } catch (ReservationRejectedException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_model_event_veto_rolls_back_allocation_instead_of_returning_unsaved_success(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $actor = User::factory()->create();
        Reservation::creating(fn (): bool => false);

        try {
            app(ReserveEventAction::class)->execute($actor, $event->id);
            $this->fail('A model event veto must not be successful booking.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The reservation could not be saved.', $exception->getMessage());
        } finally {
            Reservation::flushEventListeners();
        }

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    private function waitlist(Event $event, User $actor, string $status = 'waiting'): void
    {
        DB::table('waitlist_entries')->insert([
            'event_id' => $event->id, 'user_id' => $actor->id, 'status' => $status,
            'joined_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            'promoted_at' => $status === 'promoted' ? now('UTC') : null,
            'cancelled_at' => $status === 'cancelled' ? now('UTC') : null,
        ]);
    }
}
