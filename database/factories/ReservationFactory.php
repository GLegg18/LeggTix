<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use RuntimeException;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory()->published(),
            'user_id' => User::factory(),
            'status' => ReservationStatus::Confirmed,
            'cancelled_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReservationStatus::Confirmed,
            'cancelled_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now('UTC'),
        ]);
    }

    protected function store(Collection $results)
    {
        // Fixture writes must preserve occupancy too, including when insertion fails.
        $results->each(function (Reservation $reservation): void {
            $connection = $reservation->getConnection();

            $connection->transaction(function () use ($reservation, $connection): void {
                Event::on($connection->getName())->lockForUpdate()->findOrFail($reservation->event_id);

                if ($reservation->status === ReservationStatus::Confirmed) {
                    $allocated = $connection->table('events')->where('id', $reservation->event_id)
                        ->whereColumn('confirmed_count', '<', 'capacity')->increment('confirmed_count');

                    if ($allocated !== 1) {
                        throw new RuntimeException('The reservation fixture would exceed event capacity.');
                    }
                }

                parent::store(new Collection([$reservation]));

                if (! $reservation->exists) {
                    throw new RuntimeException('The reservation fixture could not be saved.');
                }
            });
        });
    }
}
