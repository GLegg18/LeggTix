# Public events, models and factories

[Issue #6](https://github.com/GLegg18/LeggTix/issues/6) added the Eloquent model layer and test fixtures on the physical schema delivered in #5. [Issue #7](https://github.com/GLegg18/LeggTix/issues/7) adds public event discovery. The [database design](database-design.md) and [ER diagram](diagrams/leggtix-database-er.svg) remain the field, constraint and workflow contract.

[Issue #8 event management](event-management.md) now implements owner/admin creation, editing, publication, cancellation/completion and private event/attendee/waitlist inspection, with a [Nuxt workspace](event-management-ui.md). Public reads below retain their existing fields and published-only boundary. The [manual plan](event-management-manual-test-plan.md) and [validation report](issue-8-validation.md) cover the management milestone separately.

## Public API

Both reads work without authentication and return JSON, including errors when `Accept` is omitted. The [local Swagger explorer](http://localhost:8000/docs/api) and [OpenAPI JSON](http://localhost:8000/docs/api.json) describe the current contract.

- `GET /api/events` lists only published events with `starts_at` strictly after the current UTC time. Equality is excluded. Full events remain listed, and retiring an event type does not hide an existing published event.
- `GET /api/events/{event}` returns one published event, including a past event. Draft, cancelled, completed and missing IDs return the same generic `404` without an event payload. There is no owner/admin exception on this public endpoint.

The list returns `data`, `links` and `meta`; detail returns `data`. Both use the same explicit public event fields:

| Field | Meaning |
| --- | --- |
| `id`, `name`, `description`, `venue` | Event identity and public information. |
| `starts_at`, `ends_at` | UTC ISO 8601 instants with microseconds; `ends_at` can be null. |
| `timezone` | IANA display timezone, separate from the UTC instants. |
| `capacity`, `remaining_capacity` | Capacity and advisory capacity minus the current MySQL confirmed occupancy counter. |
| `status` | Always `published` for these endpoints. |
| `event_type` | Only `id`, `slug` and `name` of the classification. |

Owner/account data, attendee and waitlist records, raw `confirmed_count`, catalogue administration fields and internal timestamps are omitted. Availability uses the stored counter with no reservation scan or cache. It can change immediately after a read, and remaining capacity on a past event does not make it bookable. Only the [reservation transaction](reservations.md) can confirm a place. Successful event reads use `Cache-Control: no-store, private`.

### Cursor pagination

The list accepts only `per_page` (integer from 1 to 100, default 20) and `cursor` query parameters. Unsupported query parameters or invalid pagination return `422` with the usual validation errors; pagination values in a GET request body are ignored. Filtering/search remain separate work; writes use the private [management API](event-management.md).

Events are ordered by `starts_at ASC, id ASC`, supported by the existing `(status, starts_at, id)` index. Schedule microseconds and equal-time ID ties are preserved in both pagination directions. Follow `links.next` or `links.prev` unchanged. Alternatively copy `meta.next_cursor` or `meta.prev_cursor` to `cursor` and retain `per_page`. Cursors are opaque and are validated for their expected types and structure; they are not authorization credentials.

`meta` contains `path`, `per_page`, `next_cursor` and `prev_cursor`. The cursor links `first` and `last` are null; no total count or numbered pages are calculated. An empty result is `200` with `data: []` and null next/previous links and cursors. Each page uses the current schedule/status: events reaching their start or being edited can change later pages, so pagination is not a snapshot.

For the running local demo:

```powershell
$publicEvents = Invoke-RestMethod http://localhost:8000/api/events
$publicEvents.data | Select-Object id, name, capacity, remaining_capacity
$publicEvent = Invoke-RestMethod ('http://localhost:8000/api/events/' + $publicEvents.data[0].id)
$publicEvent.data
```

The [public event manual plan](public-events-manual-test-plan.md) covers the endpoints and expected outcomes. The [issue #7 validation report](issue-7-validation.md) records checks actually performed.

## Models and relationships

- `Event::owner()` belongs to `User` through `owner_id`; `User::ownedEvents()` is the inverse.
- `Event::eventType()` belongs to `EventType` through `event_type_id`; `EventType::events()` is the inverse.
- `EventStatus` contains exactly `draft`, `published`, `cancelled` and `completed`. An event starts as a draft with `confirmed_count = 0`.
- An event type is an editable catalogue row, with an active flag, rather than an enum or free-text category. The [local demo seeders](../README.md#demo-data-and-login) provide initial types; admin management remains separate work.

The existing migrations enforce positive unsigned capacity, a counter no larger than capacity, valid status values, an optional end later than start, the agreed listing indexes and restricted owner/type foreign keys. No new migration or rewrite of the applied schema is needed.

## Scheduling and protected fields

Event schedule instants are stored in UTC with microsecond precision. `timezone` identifies the IANA zone for display, such as `Europe/London`; it does not change the meaning of a stored UTC instant. Supply UTC values or explicit timezone offsets when assigning schedule instants. A future API accepting wall-clock local times must validate daylight-saving gaps and ambiguous times before assignment.

Capacity assignment requires a native PHP integer from `1` through `4294967295`; numeric strings, floats and booleans are rejected. Management requests validate native JSON integers before assignment. This prevents silent truncation through an integer cast. The database check is retained for writes that bypass Eloquent. The event's timezone must be a valid IANA identifier.

Ownership, identity, lifecycle status, confirmed occupancy and timestamps are protected from general mass assignment. Trusted code must assign ownership through the relationship and manage lifecycle/inventory fields explicitly. Factories use Laravel's trusted fixture mechanism. This field protection is not an authorization policy: management endpoints separately validate allowed request fields and enforce `EventPolicy` against the actor/current event.

## Read helpers and lifecycle boundaries

`isPast()` derives past state from `starts_at <= current UTC time`. Equality is already past. No `past` value is stored in the status column.

`isBookable()` identifies a published event whose start is strictly in the future. `Event::upcoming()` selects the same published/future class of events. These are eligibility read helpers; they do not allocate places, check or reserve remaining capacity, or authorize a customer. Retirement of a type does not invalidate an already published event.

The design permits draft to published/cancelled, and published to cancelled/completed. Publication requires a future start, completion requires a past start, and terminal events do not reopen. `ManageEventAction` implements these transitions under the event lock, authorises the current event, locks/verifies an active type when publishing or assigning it, and closes related attempts as specified in the [management guide](event-management.md). Metadata remains editable on terminal events; their schedule/type/capacity/status cannot change. The model read helpers do not perform these transactions. Booking and promotion recheck eligibility under the inventory lock immediately before allocation.

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

These are fixture tools, not public create/publish workflows. Avoid creating a nonzero occupancy counter without matching confirmed reservation rows; the model layer does not reconcile inventory. The [local demo seeders](../README.md#demo-data-and-login) provide persistent sample accounts, types and events for review.

## Acceptance checks

Use the [isolated test runner](testing.md) to run `EventApiTest`, `PublicEventBoundaryTest`, `ApiDocumentationTest` or `EventModelTest`, or run the complete suite. The [issue #6 verification report](issue-6-validation.md) records checks for the model milestone. The tester's [event model manual plan](event-manual-test-plan.md) describes the model, factory, schedule and field-protection checks and expected outcomes.
