# Event models and factories

[Issue #6](https://github.com/GLegg18/LeggTix/issues/6) builds on the physical schema delivered in #5. The [database design](database-design.md) and [ER diagram](diagrams/leggtix-database-er.svg) remain the field, constraint and workflow contract. This milestone adds the Eloquent model layer and test fixtures; it does not add event HTTP endpoints.

## Models and relationships

- `Event::owner()` belongs to `User` through `owner_id`; `User::ownedEvents()` is the inverse.
- `Event::eventType()` belongs to `EventType` through `event_type_id`; `EventType::events()` is the inverse.
- `EventStatus` contains exactly `draft`, `published`, `cancelled` and `completed`. An event starts as a draft with `confirmed_count = 0`.
- An event type is an editable catalogue row, with an active flag, rather than an enum or free-text category. Catalogue seeds and admin management remain separate work.

The existing migrations enforce positive unsigned capacity, a counter no larger than capacity, valid status values, an optional end later than start, the agreed listing indexes and restricted owner/type foreign keys. No new migration or rewrite of the applied schema is needed.

## Scheduling and protected fields

Event schedule instants are stored in UTC with microsecond precision. `timezone` identifies the IANA zone for display, such as `Europe/London`; it does not change the meaning of a stored UTC instant. Supply UTC values or explicit timezone offsets when assigning schedule instants. A future API accepting wall-clock local times must validate daylight-saving gaps and ambiguous times before assignment.

Capacity assignment requires a native PHP integer from `1` through `4294967295`; numeric strings, floats and booleans are rejected. A future request workflow must validate and explicitly normalize its input before assignment. This prevents silent truncation through an integer cast. The database check is retained for writes that bypass Eloquent. The event's timezone must be a valid IANA identifier.

Ownership, identity, lifecycle status, confirmed occupancy and timestamps are protected from general mass assignment. Trusted code must assign ownership through the relationship and manage lifecycle/inventory fields explicitly. Factories use Laravel's trusted fixture mechanism. This field protection is not an authorization policy: future event endpoints must still validate allowed request fields and authorize the actor.

## Read helpers and lifecycle boundaries

`isPast()` derives past state from `starts_at <= current UTC time`. Equality is already past. No `past` value is stored in the status column.

`isBookable()` identifies a published event whose start is strictly in the future. `Event::upcoming()` selects the same published/future class of events. These are eligibility read helpers; they do not allocate places, check or reserve remaining capacity, or authorize a customer. Retirement of a type does not invalidate an already published event.

The design permits draft to published/cancelled, and published to cancelled/completed. Publication requires a future start, completion requires a past start, and terminal events do not reopen. Mutating these states belongs to the later event management actions: they must lock and re-read the event, authorize the actor, verify an active type when publishing or assigning it, and update related records as specified in the design. The model read helpers do not perform those transactions. Booking actions must recheck eligibility under the inventory lock immediately before allocation.

## Factory fixtures

`EventType::factory()` provides catalogue fixtures, with `inactive()` for retirement scenarios. `Event::factory()` creates a future draft with an organiser owner and an active type. Use the named states `draft()`, `published()`, `cancelled()`, `completed()` and `past()` for the acceptance scenarios. `past()` creates a published event with a past schedule; `completed()` also uses a past schedule. Neither creates a stored `past` status.

For an isolated test fixture with explicit parents:

```php
$owner = User::factory()->organiser()->create();
$type = EventType::factory()->create();
$event = Event::factory()
    ->for($owner, 'owner')
    ->for($type, 'eventType')
    ->published()
    ->create();
```

These are fixture tools, not public create/publish workflows. Avoid creating a nonzero occupancy counter without matching confirmed reservation rows; the model layer does not reconcile inventory. Domain seeds are tracked separately from this ticket.

## Acceptance checks

Use the [isolated test runner](testing.md) to run `EventModelTest`, or run the complete suite. The [issue #6 verification report](issue-6-validation.md) records actual execution, review findings and remaining limits. The tester's [event manual plan](event-manual-test-plan.md) describes the model, factory, schedule and field-protection checks and expected outcomes.
