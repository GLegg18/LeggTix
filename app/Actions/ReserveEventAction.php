<?php

namespace App\Actions;

use App\Enums\EventStatus;
use App\Enums\ReservationRejection;
use App\Enums\ReservationStatus;
use App\Exceptions\ReservationRejectedException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReserveEventAction
{
    public function execute(User $user, int $eventId): Reservation
    {
        return DB::transaction(function () use ($user, $eventId): Reservation {
            $event = Event::query()->lockForUpdate()->findOrFail($eventId);

            // Sample database time after waiting for the event lock, not before it.
            $now = CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC');

            if ($event->status !== EventStatus::Published || $event->starts_at->lessThanOrEqualTo($now)) {
                throw new ReservationRejectedException(ReservationRejection::EventUnavailable);
            }

            // Locking generated-key lookups see commits newer than an earlier snapshot.
            if (Reservation::query()->where('event_id', $eventId)
                ->where('active_user_id', $user->getKey())->lockForUpdate()->first() !== null) {
                throw new ReservationRejectedException(ReservationRejection::AlreadyReserved);
            }

            if (DB::table('waitlist_entries')->where('event_id', $eventId)
                ->where('waiting_user_id', $user->getKey())->lockForUpdate()->first() !== null) {
                throw new ReservationRejectedException(ReservationRejection::AlreadyWaitlisted);
            }

            // Promotion is a separate workflow; preserve its FIFO priority in the meantime.
            if (DB::table('waitlist_entries')->where('event_id', $eventId)->where('status', 'waiting')
                ->orderBy('joined_at')->orderBy('id')->lockForUpdate()->first() !== null) {
                throw new ReservationRejectedException(ReservationRejection::QueueHasPriority);
            }

            if ($event->confirmed_count >= $event->capacity) {
                throw new ReservationRejectedException(ReservationRejection::Full);
            }

            $allocated = DB::table('events')->where('id', $eventId)
                ->where('status', EventStatus::Published->value)
                ->where('starts_at', '>', DB::raw('UTC_TIMESTAMP(6)'))
                ->whereColumn('confirmed_count', '<', 'capacity')
                ->update([
                    'confirmed_count' => DB::raw('confirmed_count + 1'),
                    'updated_at' => DB::raw('UTC_TIMESTAMP(6)'),
                ]);

            if ($allocated !== 1) {
                // With the event locked and space checked, only the start cutoff can change.
                throw new ReservationRejectedException(ReservationRejection::EventUnavailable);
            }

            $reservation = new Reservation;
            $reservation->event()->associate($event);
            $reservation->user()->associate($user);
            $reservation->status = ReservationStatus::Confirmed;
            $reservation->created_at = CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC');
            $reservation->updated_at = $reservation->created_at;
            if (! $reservation->save()) {
                throw new RuntimeException('The reservation could not be saved.');
            }

            return $reservation;
        }, attempts: 3);
    }
}
