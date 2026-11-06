<?php

namespace App\Actions;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ManageEventAction
{
    public function __construct(private readonly PromoteWaitlistAction $promote) {}

    public function create(User $actor, array $data): Event
    {
        return DB::transaction(function () use ($actor, $data): Event {
            Gate::forUser($actor)->authorize('create', Event::class);
            $status = EventStatus::from($data['status'] ?? 'draft');
            if (! in_array($status, [EventStatus::Draft, EventStatus::Published], true)) {
                $this->invalid('status', 'Create an event as draft or published.');
            }

            $this->activeType($data['event_type_id']);
            $event = new Event;
            $event->owner()->associate($actor);
            $this->assign($event, $data);
            $event->status = $status;
            $this->validateSchedule($event);
            if ($status === EventStatus::Published) {
                $this->validateFuture($event);
            }
            $this->save($event);

            return $event;
        }, attempts: 3);
    }

    public function update(User $actor, int $eventId, array $data): Event
    {
        return DB::transaction(function () use ($actor, $eventId, $data): Event {
            $event = Event::query()->lockForUpdate()->findOrFail($eventId);
            Gate::forUser($actor)->authorize('update', $event);

            $previousStatus = $event->status;
            $previousCapacity = $event->capacity;
            $this->assign($event, $data);
            $status = isset($data['status']) ? EventStatus::from($data['status']) : $previousStatus;
            if (in_array($previousStatus, [EventStatus::Cancelled, EventStatus::Completed], true)) {
                foreach (['starts_at', 'ends_at', 'timezone', 'event_type_id', 'capacity'] as $field) {
                    if ($event->isDirty($field)) {
                        $this->invalid($field, 'Only listing metadata can change on a terminal event.');
                    }
                }
                if ($status !== $previousStatus) {
                    $this->invalid('status', 'Terminal events cannot change status or reopen.');
                }
                $this->save($event);

                return $event;
            }
            if ($event->isDirty('event_type_id') || ($status === EventStatus::Published && $previousStatus === EventStatus::Draft)) {
                $this->activeType($event->event_type_id);
            }
            if ($this->hasHistory($event)) {
                $errors = [];
                foreach (['starts_at', 'ends_at', 'timezone', 'event_type_id'] as $field) {
                    if ($event->isDirty($field)) {
                        $errors[$field] = ['Schedule and event type cannot change after reservation or waitlist history exists.'];
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            if ($event->capacity < $event->confirmed_count) {
                $this->invalid('capacity', 'Capacity cannot be lower than current confirmed occupancy.');
            }
            $this->validateSchedule($event);
            if ($status !== $previousStatus) {
                $this->transition($event, $status);
            } elseif ($status === EventStatus::Published && $event->isDirty('starts_at')) {
                $this->validateFuture($event);
            }
            $this->save($event);

            if ($event->capacity > $previousCapacity && $event->status === EventStatus::Published) {
                $this->promote->fillLocked($event);
                $event->refresh();
            }

            return $event;
        }, attempts: 3);
    }

    public function cancel(User $actor, int $eventId): Event
    {
        return DB::transaction(function () use ($actor, $eventId): Event {
            $event = Event::query()->lockForUpdate()->findOrFail($eventId);
            Gate::forUser($actor)->authorize('cancel', $event);
            if ($event->status === EventStatus::Cancelled) {
                return $event;
            }
            if ($event->status === EventStatus::Completed) {
                $this->invalid('status', 'Completed events cannot be cancelled.');
            }
            $this->transition($event, EventStatus::Cancelled);
            $this->save($event);

            return $event;
        }, attempts: 3);
    }

    private function assign(Event $event, array $data): void
    {
        foreach (['event_type_id', 'name', 'description', 'venue', 'timezone', 'capacity'] as $field) {
            if (array_key_exists($field, $data)) {
                $event->setAttribute($field, $data[$field]);
            }
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $event->setAttribute($field, $data[$field] === null ? null : CarbonImmutable::parse($data[$field])->utc());
            }
        }
    }

    private function activeType(int $id): void
    {
        $type = EventType::query()->lockForUpdate()->find($id);
        if ($type === null || ! $type->is_active) {
            $this->invalid('event_type_id', 'Choose an active event type.');
        }
    }

    private function hasHistory(Event $event): bool
    {
        return $event->reservations()->orderBy('id')->lockForUpdate()->first() !== null
            || DB::table('waitlist_entries')->where('event_id', $event->id)->orderBy('id')->lockForUpdate()->first() !== null;
    }

    private function validateSchedule(Event $event): void
    {
        if ($event->ends_at !== null && $event->ends_at->lessThanOrEqualTo($event->starts_at)) {
            $this->invalid('ends_at', 'The end must be later than the start.');
        }
    }

    private function validateFuture(Event $event): void
    {
        if ($event->starts_at->lessThanOrEqualTo(CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC'))) {
            $this->invalid('starts_at', 'Publication requires a start strictly in the future.');
        }
    }

    private function transition(Event $event, EventStatus $status): void
    {
        if ($status === EventStatus::Draft || ($event->status === EventStatus::Draft && $status === EventStatus::Completed)) {
            $this->invalid('status', 'This event status transition is not permitted.');
        }
        if ($status === EventStatus::Published) {
            $this->validateFuture($event);
        }
        if ($status === EventStatus::Completed && $event->starts_at->greaterThan(CarbonImmutable::parse(DB::scalar('SELECT UTC_TIMESTAMP(6)'), 'UTC'))) {
            $this->invalid('status', 'Only an event whose start has passed can be completed.');
        }
        if (in_array($status, [EventStatus::Cancelled, EventStatus::Completed], true)) {
            $now = DB::raw('UTC_TIMESTAMP(6)');
            if ($status === EventStatus::Cancelled) {
                DB::table('reservations')->where('event_id', $event->id)->where('status', 'confirmed')
                    ->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
                $event->confirmed_count = 0;
            }
            DB::table('waitlist_entries')->where('event_id', $event->id)->where('status', 'waiting')
                ->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
        }
        $event->status = $status;
    }

    private function save(Event $event): void
    {
        if (! $event->save()) {
            throw new RuntimeException('The event could not be saved.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
