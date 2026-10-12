<?php

namespace Database\Seeders;

use App\Actions\ReserveEventAction;
use App\Enums\EventStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Exceptions\ReservationRejectedException;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data may only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            $customer = $this->demoUser('Demo User', 'demo.user@example.test', UserRole::RegularUser);
            // Every run locks the same owner before checking the event fixture names.
            $owner = $this->demoUser('Demo Organiser', 'demo.owner@example.test', UserRole::Organiser);
            $this->demoUser('Demo Admin', 'demo.admin@example.test', UserRole::Admin);
            $firstAttendee = $this->demoUser('Demo Attendee One', 'demo.attendee.one@example.test', UserRole::RegularUser);
            $secondAttendee = $this->demoUser('Demo Attendee Two', 'demo.attendee.two@example.test', UserRole::RegularUser);

            $types = [];
            foreach ([
                'concert' => ['name' => 'Concert', 'description' => 'Live music and performances.'],
                'workshop' => ['name' => 'Workshop', 'description' => 'Practical learning in a small group.'],
                'meetup' => ['name' => 'Meetup', 'description' => 'Community conversations and shared interests.'],
                'live-show' => ['name' => 'Live Show', 'description' => 'Live theatre, comedy and stage performances.'],
                'film-screening' => ['name' => 'Film Screening', 'description' => 'Shared cinema and independent film screenings.'],
            ] as $slug => $attributes) {
                $type = EventType::query()->where('slug', $slug)->lockForUpdate()->first();

                if ($type === null) {
                    $type = new EventType;
                    $type->forceFill(['slug' => $slug, ...$attributes, 'is_active' => true]);

                    if (! $type->save()) {
                        throw new RuntimeException('Could not create demo event type '.$slug.'.');
                    }
                }

                $types[$slug] = $type;
            }

            $events = [];
            foreach ($this->demoEvents() as $attributes) {
                $existing = $owner->ownedEvents()->where('name', $attributes['name'])->lockForUpdate()->get();

                if ($existing->count() > 1) {
                    throw new RuntimeException('Multiple demo events match '.$attributes['name'].'; existing data was left unchanged.');
                }

                if ($existing->isNotEmpty()) {
                    $events[$attributes['name']] = $existing->first();

                    continue;
                }

                $type = $types[$attributes['type_slug']];

                if (! $type->is_active) {
                    throw new RuntimeException(sprintf(
                        'Cannot create demo event %s: event type %s is inactive.',
                        $attributes['name'], $type->slug,
                    ));
                }

                unset($attributes['type_slug']);
                $event = new Event;
                $event->forceFill([...$attributes, 'timezone' => 'Europe/London', 'confirmed_count' => 0]);
                $event->owner()->associate($owner);
                $event->eventType()->associate($type);

                if (! $event->save()) {
                    throw new RuntimeException('Could not create demo event '.$event->name.'.');
                }

                $events[$event->name] = $event;
            }

            $reservations = [
                'Demo: Riverside Acoustic Night' => [$customer],
                'Demo: Studio Live Showcase' => [$firstAttendee, $secondAttendee],
            ];

            foreach ($events as $name => $event) {
                $this->demoReservations($event, $reservations[$name] ?? []);
            }
        }, attempts: 3);
    }

    /** @param list<User> $attendees */
    private function demoReservations(Event $event, array $attendees): void
    {
        $event = Event::query()->lockForUpdate()->findOrFail($event->getKey());
        // A locking read sees bookings committed after any earlier MySQL snapshot.
        $confirmed = $event->reservations()->where('status', ReservationStatus::Confirmed)
            ->lockForUpdate()->pluck('id')->count();

        if ($event->confirmed_count !== $confirmed) {
            throw new RuntimeException(sprintf(
                'Cannot seed demo event %s: confirmed_count does not match confirmed reservations; existing data was left unchanged.',
                $event->name,
            ));
        }

        foreach ($attendees as $attendee) {
            // Any previous attempt means a rerun must respect the user's later choices.
            if (Reservation::query()->where('event_id', $event->getKey())->where('user_id', $attendee->getKey())
                ->lockForUpdate()->first() !== null
                || DB::table('waitlist_entries')->where('event_id', $event->getKey())->where('user_id', $attendee->getKey())
                    ->lockForUpdate()->first() !== null) {
                continue;
            }

            try {
                app(ReserveEventAction::class)->execute($attendee, $event->getKey());
            } catch (ReservationRejectedException $exception) {
                throw new RuntimeException(sprintf(
                    'Cannot create demo reservation for %s at %s: %s; existing data was left unchanged.',
                    $attendee->email, $event->name, $exception->reason->value,
                ), previous: $exception);
            }
        }
    }

    private function demoUser(string $name, string $email, UserRole $role): User
    {
        $user = User::query()->where('email', $email)->lockForUpdate()->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => $name, 'email' => $email,
                'password' => Hash::make('demo-password'), 'role' => $role,
            ]);

            if (! $user->save()) {
                throw new RuntimeException('Could not create demo account '.$email.'.');
            }
        } elseif ($user->role !== $role) {
            throw new RuntimeException(sprintf(
                'Demo account %s must have role %s; existing account was left unchanged.',
                $email, $role->value,
            ));
        }

        return $user;
    }

    private function demoEvents(): array
    {
        $now = now('UTC')->toImmutable();
        $concertStart = $now->addDays(14)->setTime(19, 0);
        $workshopStart = $now->addDays(21)->setTime(10, 0);
        $meetupStart = $now->addDays(28)->setTime(10, 0);
        $cancelledStart = $now->addDays(35)->setTime(18, 0);
        $completedStart = $now->subDays(14)->setTime(10, 0);
        $pastStart = $now->subDays(7)->setTime(18, 0);
        $showcaseStart = $now->addDays(18)->setTime(19, 0);
        $screeningStart = $now->addDays(25)->setTime(18, 0);

        return [
            [
                'name' => 'Demo: Riverside Acoustic Night', 'type_slug' => 'concert',
                'description' => 'An evening of acoustic music from local performers.',
                'venue' => 'Riverside Hall, London',
                'starts_at' => $concertStart, 'ends_at' => $concertStart->addHours(2),
                'capacity' => 120, 'status' => EventStatus::Published,
            ],
            [
                'name' => 'Demo: Laravel Makers Workshop', 'type_slug' => 'workshop',
                'description' => 'Build a small Laravel feature together with guided exercises.',
                'venue' => 'Makers Studio, London',
                'starts_at' => $workshopStart, 'ends_at' => $workshopStart->addHours(3),
                'capacity' => 24, 'status' => EventStatus::Published,
            ],
            [
                'name' => 'Demo: Community Coffee Meetup', 'type_slug' => 'meetup',
                'description' => 'A planned community coffee morning, still in draft.',
                'venue' => 'Neighbourhood Cafe, London',
                'starts_at' => $meetupStart, 'ends_at' => $meetupStart->addHours(2),
                'capacity' => 40, 'status' => EventStatus::Draft,
            ],
            [
                'name' => 'Demo: Garden Session', 'type_slug' => 'concert',
                'description' => 'A cancelled outdoor music session for a terminal-state example.',
                'venue' => 'Community Garden, London',
                'starts_at' => $cancelledStart, 'ends_at' => $cancelledStart->addHours(2),
                'capacity' => 80, 'status' => EventStatus::Cancelled,
            ],
            [
                'name' => 'Demo: Intro to Web APIs', 'type_slug' => 'workshop',
                'description' => 'A completed introduction to practical web API design.',
                'venue' => 'Makers Studio, London',
                'starts_at' => $completedStart, 'ends_at' => $completedStart->addHours(3),
                'capacity' => 20, 'status' => EventStatus::Completed,
            ],
            [
                'name' => "Demo: Last Week's Community Meetup", 'type_slug' => 'meetup',
                'description' => 'A past meetup that remains published to show the start-time cutoff.',
                'venue' => 'Neighbourhood Cafe, London',
                'starts_at' => $pastStart, 'ends_at' => $pastStart->addHours(2),
                'capacity' => 30, 'status' => EventStatus::Published,
            ],
            [
                'name' => 'Demo: Studio Live Showcase', 'type_slug' => 'live-show',
                'description' => 'A small live performance with both places already reserved.',
                'venue' => 'Studio Theatre, London',
                'starts_at' => $showcaseStart, 'ends_at' => $showcaseStart->addHours(2),
                'capacity' => 2, 'status' => EventStatus::Published,
            ],
            [
                'name' => 'Demo: Independent Film Screening', 'type_slug' => 'film-screening',
                'description' => 'An independent film screening with places available to reserve.',
                'venue' => 'Neighbourhood Cinema, London',
                'starts_at' => $screeningStart, 'ends_at' => $screeningStart->addHours(2),
                'capacity' => 30, 'status' => EventStatus::Published,
            ],
        ];
    }
}
