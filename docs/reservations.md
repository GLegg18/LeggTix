# Booking a place

[Issue #9](https://github.com/GLegg18/LeggTix/issues/9) adds the reservation model, event/user relationships, and a concurrency-safe booking workflow over the schema already delivered in #5. Each successful booking reserves one free general-admission place. Regular users, organisers and admins may all book.

The local [Swagger API explorer](http://localhost:8000/docs/api) provides the request/response reference and **Try it out**. This note explains the transaction, integration limits and fixture behavior.

## API contract

Send `POST /api/events/{event}/reservations` with the Sanctum bearer token returned by login and either no body or the empty JSON object `{}`. Omit `Content-Type` for a bodyless request or use a JSON content type; any declared unsupported type is rejected, including form/multipart types whose raw bytes PHP may hide. A nonempty body requires a JSON content type. The route identifies the event and the authenticated user becomes the holder. The API rejects supplied fields rather than allowing callers to choose a holder, lifecycle status or inventory value.

The route accepts numeric event IDs and limits booking attempts to 30 per minute per authenticated user and 120 per minute per IP. The configured cache store backs throttling; it does not allocate inventory.

The success response contains only the reservation's deliberate public fields. It does not include the generated active key, other users' contact details or credentials. Success, business-conflict and busy responses use `Cache-Control: no-store`.

| Outcome | HTTP status / machine code |
| --- | --- |
| Created | `201`, `data` with `id`, `event_id`, `user_id`, `status`, `cancelled_at`, `created_at`, `updated_at` |
| Event missing | `404` |
| Guest or invalid/expired token | `401` |
| Malformed JSON | `400` |
| Declared unsupported content type, or nonempty body without a JSON type | `415` |
| JSON array/scalar/null or supplied body/query fields | `422` |
| Draft, cancelled, completed or started event | `409`, `event_not_bookable` |
| Existing confirmed reservation | `409`, `already_reserved` |
| Capacity exhausted | `409`, `full` |
| Holder currently waiting | `409`, `already_waitlisted` |
| Another active waiter has priority | `409`, `queue_has_priority` |
| Rate limit reached | `429` |
| Concurrency retries exhausted | `503`, `reservation_busy`, `Retry-After: 1` |

Business conflicts contain `message` and `code`. A client receiving `503` can retry after the indicated delay; the server has exhausted its bounded retries without reporting a sold-out result. Unexpected database errors remain server errors.

Repeated booking has a conflict outcome and never allocates another place. A lost successful response can therefore be retried safely without increasing occupancy, although this is business duplicate protection rather than a general request-idempotency API.

## Inventory transaction

1. Begin a MySQL transaction and lock the event primary key using `FOR UPDATE`. Fetch current state inside this transaction; a controller-loaded event or earlier availability response cannot allocate capacity.
2. Recheck publication and the strict future start cutoff. A retired event type does not invalidate an already published event.
3. Read the actor's active reservation and waiting entry with current locking reads using the generated-key indexes. Check the oldest active waiter by `joined_at, id`; no newcomer may bypass the queue.
4. Increment `confirmed_count` only when the event is still published, `starts_at > UTC_TIMESTAMP(6)`, and `confirmed_count < capacity`. The database time guard checks eligibility immediately at allocation, including time spent waiting on other queries.
5. Insert a fresh confirmed attempt for the authenticated holder and commit both writes together. Any failed insert or other exception rolls back the increment. Return success only after the action's transaction completes.

The event lock serializes competing writers even before child rows exist. The unique `(event_id, active_user_id)` key independently prevents two confirmed attempts for the same user/event. Cancelled history is retained, and a later booking receives a new ID. Capacity is decided from the locked MySQL counter, without scanning all confirmed rows or consulting Redis.

Whole-transaction retries are bounded to three attempts for concurrency errors recognized by Laravel. Each retry recomputes state. No email, Redis or provider calls occur inside the closure. Exhausted retries are an operational busy outcome, never evidence that an event is full.

## Waitlist integration and limits

This stage refuses direct booking if the holder is waiting or any active waiter has priority, even when capacity is available. It does not silently enrol a rejected customer, promote anyone, or remove waiting history. [#13](https://github.com/GLegg18/LeggTix/issues/13) implements join/leave; [#14](https://github.com/GLegg18/LeggTix/issues/14) must replace the conservative queue gate with the bounded promotion path in the [MVP design](database-design.md#reserve-and-join). Until then, an existing backlog can leave spare capacity unallocated.

Every future cancellation, promotion, capacity edit, event closure and administrative writer must use the same event-first protocol and maintain counter/attempt changes together. The database check bounds the counter; it cannot enforce equality with rows in another table. Direct SQL, fixture insertion and trusted model assignment can bypass the workflow. Before adopting existing data, check that each event's counter equals its confirmed rows. An event-locked diagnostic/reconciliation command remains follow-up work with the fuller integrity checks in [#12](https://github.com/GLegg18/LeggTix/issues/12); do not silently repair inventory from an earlier snapshot.

One busy event remains a serialized capacity pool. These checks establish correctness for exercised races; they do not establish a throughput target or fairness between simultaneous HTTP requests. Eligibility is checked at allocation, without promising that commit or network delivery finishes before the event starts.

## Model and fixtures

`Reservation` belongs to its event and holder; `Event::reservations()` and `User::reservations()` expose historical attempts. `ReservationStatus` casts `confirmed` and `cancelled`. Lifecycle timestamps round-trip as immutable UTC instants with microseconds. All attributes are guarded from general mass assignment, and `active_user_id` is hidden from model serialization. Trusted assignment is still possible and must respect the inventory protocol.

`ReserveEventAction::execute(User $user, int $eventId)` returns the reservation after its transaction succeeds or throws a `ReservationRejectedException` carrying its typed reason. A caller wrapping the action in a larger transaction must still commit that outer transaction before treating the booking as durable. Missing events use Laravel's model-not-found exception. The API supplies the user from Sanctum.

`Reservation::factory()` creates a confirmed fixture on a published event by default. Its storage transaction locks the event and updates the counter with the fixture insert; a failed insertion or exceeded capacity rolls back that fixture's allocation. `cancelled()` creates historical attempts without increasing occupancy. These fixture tools deliberately permit confirmed attendance on terminal events and do not implement public booking eligibility or waitlist rules. `make()` creates no database inventory. A collection of fixtures is stored one fixture at a time, not as one atomic batch.

Cancellation [#10](https://github.com/GLegg18/LeggTix/issues/10), reservation history [#11](https://github.com/GLegg18/LeggTix/issues/11), the complete multi-workflow race/load suite [#12](https://github.com/GLegg18/LeggTix/issues/12), and waitlist notifications remain separate tickets. No event-management endpoint, payment, named seat or frontend is introduced by booking.

## Verification

Run the [disposable Docker/MySQL test workflow](testing.md). The booking acceptance suite checks business outcomes, API boundaries and atomic rollback. The concurrency suite uses committed fixtures and independent PHP/MySQL processes coordinated around a held event lock. It checks committed inventory after competitors finish; SQLite and sequential requests cannot demonstrate these races.

The [reservation manual plan](reservation-manual-test-plan.md) supplies repeatable checks and expected outcomes. The [issue #9 validation report](issue-9-validation.md) records checks actually executed, agent findings and remaining verification limits.
