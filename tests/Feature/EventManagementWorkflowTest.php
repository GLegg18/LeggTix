<?php

namespace Tests\Feature;

use App\Actions\ManageEventAction;
use App\Actions\PromoteWaitlistAction;
use App\Actions\ReserveEventAction;
use App\Enums\EventStatus;
use App\Jobs\ContinueWaitlistPromotion;
use App\Jobs\SendReservationConfirmationEmail;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class EventManagementWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function waiter(Event $event, User $user): int
    {
        $now = DB::scalar('SELECT UTC_TIMESTAMP(6)');

        return DB::table('waitlist_entries')->insertGetId([
            'event_id' => $event->id, 'user_id' => $user->id, 'status' => 'waiting',
            'joined_at' => '2026-01-01 00:00:00.123456', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_capacity_increase_fills_fifo_in_bounded_resumable_batches_and_retries_are_safe(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $users = User::factory()->count(PromoteWaitlistAction::BATCH_SIZE + 3)->create();
        $waiters = $users->map(fn (User $user): int => $this->waiter($event, $user));

        $updated = app(ManageEventAction::class)->update($event->owner, $event->id, ['capacity' => $users->count() + 1]);
        $this->assertSame(51, $updated->confirmed_count);
        $this->assertSame(50, DB::table('waitlist_entries')->where('status', 'promoted')->count());
        $this->assertSame($waiters->take(50)->values()->all(), DB::table('waitlist_entries')->where('status', 'promoted')->orderBy('id')->pluck('id')->all());
        Queue::assertPushed(ContinueWaitlistPromotion::class, 1);
        Queue::assertPushed(SendReservationConfirmationEmail::class, 50);

        (new ContinueWaitlistPromotion($event->id))->handle(app(PromoteWaitlistAction::class));
        (new ContinueWaitlistPromotion($event->id))->handle(app(PromoteWaitlistAction::class));
        $this->assertSame(54, $event->fresh()->confirmed_count);
        $this->assertSame(54, $event->reservations()->where('status', 'confirmed')->count());
        $this->assertSame(53, DB::table('waitlist_entries')->where('status', 'promoted')->count());
        Queue::assertPushed(SendReservationConfirmationEmail::class, 53);
        $ids = $event->reservations()->whereIn('user_id', $users->pluck('id'))->pluck('id')->all();
        foreach ($ids as $id) {
            Queue::assertPushed(SendReservationConfirmationEmail::class, fn ($job): bool => $job->reservationId === $id);
        }
    }

    public function test_existing_confirmed_waiter_is_closed_and_does_not_consume_the_new_place(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $holder = User::factory()->create();
        app(ReserveEventAction::class)->execute($holder, $event->id);
        $bad = $this->waiter($event, $holder);
        $next = $this->waiter($event, User::factory()->create());

        app(ManageEventAction::class)->update($event->owner, $event->id, ['capacity' => 2]);

        $this->assertDatabaseHas('waitlist_entries', ['id' => $bad, 'status' => 'cancelled']);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $next, 'status' => 'promoted']);
        $this->assertSame(2, $event->fresh()->confirmed_count);
        $this->assertSame(2, $event->reservations()->where('status', 'confirmed')->count());
        Queue::assertPushed(SendReservationConfirmationEmail::class, 1);
    }

    public function test_anomaly_examination_is_bounded_and_continuation_can_reach_the_next_eligible_waiter(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 51]);
        $holders = User::factory()->count(50)->create();
        foreach ($holders as $holder) {
            app(ReserveEventAction::class)->execute($holder, $event->id);
        }
        foreach ($holders as $holder) {
            $this->waiter($event, $holder);
        }
        $last = $this->waiter($event, User::factory()->create());

        $batch = app(PromoteWaitlistAction::class)->execute($event->id);
        $this->assertSame([], $batch['promotions']);
        $this->assertTrue($batch['more']);
        $this->assertSame(50, $event->fresh()->confirmed_count);
        Queue::assertPushed(ContinueWaitlistPromotion::class, 1);
        $next = app(PromoteWaitlistAction::class)->execute($event->id);
        $this->assertCount(1, $next['promotions']);
        $this->assertSame($last, $next['promotions'][0]['waitlist_entry_id']);
        $this->assertFalse($next['more']);
        $this->assertSame(51, $event->fresh()->confirmed_count);
    }

    public function test_failed_promoted_reservation_rolls_back_edit_counter_and_queue_state(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $waiter = $this->waiter($event, User::factory()->create());
        Reservation::creating(static fn (): bool => false);
        try {
            app(ManageEventAction::class)->update($event->owner, $event->id, ['capacity' => 2]);
            $this->fail('Expected promotion save failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The promoted reservation could not be saved.', $exception->getMessage());
        } finally {
            Reservation::flushEventListeners();
        }
        $this->assertSame(1, $event->fresh()->capacity);
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame(1, $event->reservations()->count());
        $this->assertDatabaseHas('waitlist_entries', ['id' => $waiter, 'status' => 'waiting']);
        Queue::assertNothingPushed();
    }

    public function test_rollback_discards_all_post_commit_email_and_continuation_callbacks(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $waiter = $this->waiter($event, User::factory()->create());
        DB::beginTransaction();
        app(ManageEventAction::class)->update($event->owner, $event->id, ['capacity' => 2]);
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertSame(1, $event->fresh()->capacity);
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $waiter, 'status' => 'waiting']);
    }

    public function test_promotion_rechecks_start_between_allocations(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $first = $this->waiter($event, User::factory()->create());
        $second = $this->waiter($event, User::factory()->create());
        DB::table('events')->where('id', $event->id)->update(['starts_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)'), 'ends_at' => null]);
        Reservation::creating(static function (): void { usleep(1100000); });
        try {
            app(ManageEventAction::class)->update($event->owner, $event->id, ['capacity' => 3]);
        } finally {
            Reservation::flushEventListeners();
        }
        $this->assertSame(2, $event->fresh()->confirmed_count);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $first, 'status' => 'promoted']);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $second, 'status' => 'waiting']);
        Queue::assertNotPushed(ContinueWaitlistPromotion::class);
    }

    public function test_continuation_after_cancellation_cannot_create_a_reservation_or_send_confirmation(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $this->waiter($event, User::factory()->create());
        app(ManageEventAction::class)->cancel($event->owner, $event->id);
        (new ContinueWaitlistPromotion($event->id))->handle(app(PromoteWaitlistAction::class));
        $this->assertSame(EventStatus::Cancelled, $event->fresh()->status);
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertSame(0, $event->reservations()->count());
        Queue::assertNothingPushed();
    }
}
