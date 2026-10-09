<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReservationModelTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('factoryStates')]
    public function test_factory_states_preserve_status_history_and_exact_inventory(?string $state, ReservationStatus $status): void
    {
        $factory = Reservation::factory();

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        $reservation = $factory->create()->fresh();

        $this->assertSame($status, $reservation->status);
        $this->assertIsInt($reservation->event_id);
        $this->assertIsInt($reservation->user_id);
        $this->assertInstanceOf(CarbonImmutable::class, $reservation->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $reservation->updated_at);
        $this->assertSame('UTC', $reservation->created_at->timezoneName);
        $this->assertSame($status === ReservationStatus::Confirmed ? 1 : 0, $reservation->event->confirmed_count);

        if ($status === ReservationStatus::Cancelled) {
            $this->assertInstanceOf(CarbonImmutable::class, $reservation->cancelled_at);
            $this->assertNull(DB::table('reservations')->find($reservation->id)->active_user_id);
        } else {
            $this->assertNull($reservation->cancelled_at);
            $this->assertSame($reservation->user_id, DB::table('reservations')->find($reservation->id)->active_user_id);
        }

        $this->assertTrue($reservation->event->reservations->contains($reservation));
        $this->assertTrue($reservation->user->reservations->contains($reservation));
        $this->assertDatabaseCount('reservations', 1);
    }

    public static function factoryStates(): array
    {
        return [
            'default' => [null, ReservationStatus::Confirmed],
            'confirmed' => ['confirmed', ReservationStatus::Confirmed],
            'cancelled' => ['cancelled', ReservationStatus::Cancelled],
        ];
    }

    public function test_relationships_isolate_other_actors_and_events_and_allow_empty_history(): void
    {
        $actor = User::factory()->create();
        $otherActor = User::factory()->create();
        $emptyActor = User::factory()->create();
        $event = Event::factory()->published()->create(['capacity' => 3]);
        $otherEvent = Event::factory()->published()->create();
        $emptyEvent = Event::factory()->create();
        $first = Reservation::factory()->for($actor)->for($event)->create();
        $history = Reservation::factory()->cancelled()->for($actor)->for($event)->create();
        $second = Reservation::factory()->for($otherActor)->for($event)->create();
        $third = Reservation::factory()->for($actor)->for($otherEvent)->create();

        $this->assertEqualsCanonicalizing([$first->id, $history->id, $third->id], $actor->reservations->modelKeys());
        $this->assertSame([$second->id], $otherActor->reservations->modelKeys());
        $this->assertEqualsCanonicalizing([$first->id, $history->id, $second->id], $event->reservations->modelKeys());
        $this->assertSame([$third->id], $otherEvent->reservations->modelKeys());
        $this->assertTrue($emptyActor->reservations->isEmpty());
        $this->assertTrue($emptyEvent->reservations->isEmpty());
        $this->assertSame(2, $event->fresh()->confirmed_count);
        $this->assertSame(1, $otherEvent->fresh()->confirmed_count);
    }

    public function test_general_mass_assignment_cannot_allocate_move_cancel_or_forge_reservation(): void
    {
        $reservation = Reservation::factory()->create()->fresh();
        $original = $reservation->getAttributes();

        try {
            $reservation->fill([
                'id' => 999999, 'event_id' => 999999, 'user_id' => 999999,
                'status' => 'cancelled', 'cancelled_at' => '1999-01-01',
                'active_user_id' => 999999, 'created_at' => '1999-01-01', 'updated_at' => '1999-01-01',
            ])->save();
            $this->fail('Reservation workflow fields must remain guarded.');
        } catch (MassAssignmentException $exception) {
            $this->assertStringContainsString(Reservation::class, $exception->getMessage());
        }

        $this->assertSame($original, $reservation->fresh()->getAttributes());
        $this->assertSame(1, $reservation->event->confirmed_count);
    }

    public function test_terminal_history_dates_preserve_utc_instants_and_microseconds(): void
    {
        $reservation = Reservation::factory()->cancelled()->create([
            'created_at' => '2026-10-06T10:00:00.654321+05:30',
            'updated_at' => '2026-10-07T11:00:00.654322+05:30',
            'cancelled_at' => CarbonImmutable::parse('2026-10-07T11:00:00.654322+05:30'),
        ])->fresh();

        $row = DB::table('reservations')->find($reservation->id);
        $this->assertSame('2026-10-06 04:30:00.654321', $row->created_at);
        $this->assertSame('2026-10-07 05:30:00.654322', $row->updated_at);
        $this->assertSame('2026-10-07 05:30:00.654322', $row->cancelled_at);
        $this->assertSame('UTC', $reservation->cancelled_at->timezoneName);
        $this->assertSame('2026-10-07T05:30:00.654322Z', $reservation->toArray()['cancelled_at']);
        $this->assertArrayNotHasKey('active_user_id', $reservation->toArray());
    }

    public function test_factory_model_event_veto_rolls_back_its_inventory_increment(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $actor = User::factory()->create();
        Reservation::creating(fn (): bool => false);

        try {
            Reservation::factory()->for($event)->for($actor)->create();
            $this->fail('A factory fixture veto must not commit allocated inventory.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The reservation fixture could not be saved.', $exception->getMessage());
        } finally {
            Reservation::flushEventListeners();
        }

        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_confirmed_fixture_cannot_exceed_capacity_or_drift_counter(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $first = Reservation::factory()->for($event)->create();

        try {
            Reservation::factory()->for($event)->create();
            $this->fail('A full event must reject another confirmed fixture.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The reservation fixture would exceed event capacity.', $exception->getMessage());
        }

        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame([$first->id], $event->reservations()->pluck('id')->all());
    }
}
