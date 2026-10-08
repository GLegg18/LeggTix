<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Event> */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->organiser(),
            'event_type_id' => EventType::factory(),
            'name' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'venue' => fake()->streetAddress(),
            'starts_at' => now('UTC')->addDays(7),
            'ends_at' => null,
            'timezone' => 'Europe/London',
            'capacity' => fake()->numberBetween(1, 500),
            'confirmed_count' => 0,
            'status' => EventStatus::Draft,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => EventStatus::Draft]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => EventStatus::Published]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => EventStatus::Cancelled]);
    }

    public function completed(): static
    {
        return $this->past()->state(fn (array $attributes): array => ['status' => EventStatus::Completed]);
    }

    public function past(): static
    {
        return $this->state(function (array $attributes): array {
            $startsAt = now('UTC')->subDays(7);

            return [
                'status' => EventStatus::Published,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(2),
            ];
        });
    }
}
