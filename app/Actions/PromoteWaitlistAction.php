<?php

namespace App\Actions;

use App\Enums\EventStatus;
use App\Enums\ReservationStatus;
use App\Jobs\ContinueWaitlistPromotion;
use App\Models\Event;
use App\Models\Reservation;
use App\Services\TransactionalEmailDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class PromoteWaitlistAction
{
    public const BATCH_SIZE = 50;

    public function execute(int $eventId): array
    {
        return DB::transaction(function () use ($eventId): array {
            $event = Event::query()->lockForUpdate()->findOrFail($eventId);

            return $this->fillLocked($event);
        }, attempts: 3);
    }

    /** The caller must hold this event's inventory lock inside a transaction. */
    public function fillLocked(Event $event): array
    {
        $promotions = [];
        $anomalies = [];

        for ($examined = 0; $examined < self::BATCH_SIZE; $examined++) {
            if ($event->status !== EventStatus::Published || $event->confirmed_count >= $event->capacity) {
                break;
            }

            $now = CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC');
            if ($event->starts_at->lessThanOrEqualTo($now)) {
                break;
            }

            $waiter = DB::table('waitlist_entries')->where('event_id', $event->id)->where('status', 'waiting')
                ->orderBy('joined_at')->orderBy('id')->lockForUpdate()->first();
            if ($waiter === null) {
                break;
            }

            if (Reservation::query()->where('event_id', $event->id)->where('active_user_id', $waiter->user_id)
                ->lockForUpdate()->first() !== null) {
                DB::table('waitlist_entries')->where('id', $waiter->id)->update([
                    'status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now,
                ]);
                $anomalies[] = $waiter->id;

                continue;
            }

            $allocated = DB::table('events')->where('id', $event->id)->where('status', 'published')
                ->where('starts_at', '>', DB::raw('UTC_TIMESTAMP(6)'))
                ->whereColumn('confirmed_count', '<', 'capacity')->update([
                    'confirmed_count' => DB::raw('confirmed_count + 1'), 'updated_at' => DB::raw('UTC_TIMESTAMP(6)'),
                ]);
            if ($allocated !== 1) {
                break;
            }

            $reservation = new Reservation;
            $reservation->event_id = $event->id;
            $reservation->user_id = $waiter->user_id;
            $reservation->status = ReservationStatus::Confirmed;
            $reservation->created_at = $now;
            $reservation->updated_at = $now;
            if (! $reservation->save()) {
                throw new RuntimeException('The promoted reservation could not be saved.');
            }

            DB::table('waitlist_entries')->where('id', $waiter->id)->update([
                'status' => 'promoted', 'promoted_at' => $now, 'updated_at' => $now,
            ]);
            $event->confirmed_count++;
            $promotions[] = ['reservation_id' => $reservation->id, 'waitlist_entry_id' => $waiter->id];
        }

        $more = $event->status === EventStatus::Published && $event->confirmed_count < $event->capacity
            && $event->starts_at->greaterThan(CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC'))
            && DB::table('waitlist_entries')->where('event_id', $event->id)->where('status', 'waiting')
                ->orderBy('joined_at')->orderBy('id')->lockForUpdate()->first() !== null;

        // Callbacks are discarded on rollback, including a retried deadlock attempt.
        foreach ($promotions as $promotion) {
            app(TransactionalEmailDispatcher::class)->reservationConfirmed($promotion['reservation_id']);
        }
        DB::afterCommit(static function () use ($event, $more, $anomalies): void {
            try {
                foreach ($anomalies as $waitlistId) {
                    Log::warning('Closed waiting entry with an existing confirmed reservation.', [
                        'event_id' => $event->id, 'waitlist_entry_id' => $waitlistId,
                    ]);
                }
                if ($more) {
                    Queue::connection()->push(new ContinueWaitlistPromotion($event->id), '', 'inventory');
                }
            } catch (Throwable $exception) {
                // Inventory has committed. The explicit recovery command can resume lost work.
                try {
                    Log::error('Waitlist continuation could not be queued.', [
                        'event_id' => $event->id, 'exception_type' => $exception::class,
                    ]);
                } catch (Throwable) {
                }
            }
        });

        return ['promotions' => $promotions, 'more' => $more];
    }
}
