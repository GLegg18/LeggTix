# Event owner management

[Issue #8](https://github.com/GLegg18/LeggTix/issues/8) adds the owner/admin workflow over the existing schema. The [database design](database-design.md) remains the lifecycle, authorisation and inventory contract. The [Nuxt workspace](event-management-ui.md) consumes these endpoints; the [manual acceptance plan](event-management-manual-test-plan.md) gives repeatable checks and expected outcomes. Executed checks and review results belong to the [validation report](issue-8-validation.md).

## Access and routes

Only current `organiser` and `admin` accounts can create events or list active event types. Creation sets `owner_id` from the authenticated actor. Organisers list, inspect and manage only their own events; admins may list and manage all events. A demoted organiser loses management access even if ownership remains. Registration does not grant these roles. There is no owner reassignment, organiser grant, individual attendee cancellation or catalogue-write endpoint here.

The bearer API uses Sanctum at `/api/manage`. Configured first-party browsers use the corresponding `/api/spa/manage` paths, with an HttpOnly session, allowed-origin checks and Laravel's CSRF protection. Initialise `/sanctum/csrf-cookie` and send the URL-decoded `XSRF-TOKEN` cookie in `X-XSRF-TOKEN` before writes, as described in the [frontend guide](frontend.md). A bearer token alone cannot authenticate a browser-only route.

| Method/path beneath either prefix | Behaviour |
| --- | --- |
| `GET /events` | Private event list, descending ID; own events for organiser, all for admin. |
| `POST /events` | Create an owned draft, or publish immediately with `status: published`. |
| `GET /events/{event}` | Private detail, including draft, cancelled, completed and past events. |
| `PATCH /events/{event}` | Edit listing fields or perform an allowed lifecycle transition. |
| `DELETE /events/{event}` | Cancel the event; retain its row and attempt history. Repeating a cancelled event's cancellation succeeds. |
| `GET /events/{event}/reservations` | All reservation attempts for that event, ascending ID. |
| `GET /events/{event}/waitlist` | All waitlist attempts for that event, ascending `joined_at`, then ID. |
| `GET /event-types` | Active type choices only: `id`, `slug`, `name`, ascending ID. |

Lists use `per_page` (integer 1–100, default 20) and an opaque `cursor` copied unchanged from the corresponding response. Other query fields and structurally invalid cursors return `422`. `data`, `links` and `meta` follow the public cursor-page shape, with no total count. Waitlist cursor times preserve microseconds. There is no absolute queue-position claim, snapshot guarantee, status filter or export. The type endpoint is also bounded; a UI follows successive pages to load all choices.

Public `/api/events` reads keep their existing published-only privacy boundary. A privileged user must use the management endpoint for a draft; their privileges do not change the public endpoint.

## Writes and returned fields

Create requires `event_type_id`, `name`, `description`, `venue`, `starts_at`, `timezone`, and `capacity`. `ends_at` is optional/nullable; `status` defaults to `draft` and may initially be `published`. PATCH accepts any subset of the same fields, including an explicit lifecycle status. Server-owned IDs, owner, occupancy, generated keys, timestamps and unknown fields are rejected, including empty overrides. Query strings cannot supply write fields.

POST/PATCH require a JSON object with a JSON content type. DELETE accepts no body or `{}`; a declared unsupported content type is rejected even for a bodyless cancellation. Malformed JSON returns `400`, unsupported media `415`, non-object/invalid/unknown fields `422`. Capacity and type IDs must be native JSON integers, not numeric strings, floats or booleans. Capacity is 1 through 4294967295. Names and venues are at most 255 characters. Description fits MySQL TEXT's 65535 UTF-8 byte budget, including multibyte text.

Schedule values are ISO 8601 instants with seconds and an explicit `Z` or numeric offset, optionally one through six fractional digits. Their normalized UTC years must fit MySQL DATETIME's 1000–9999 range. Invalid calendar dates, local times without an offset and UTC overflow are rejected. `timezone` is a valid IANA display zone; it does not reinterpret a schedule instant. A non-null end must be strictly after the combined current/new start, including on partial edits.

Success is `201` for creation and `200` for detail, edits and cancellation. Workflow validation uses `422` field errors. Guests receive `401`, unauthorised actors `403`, and missing resources `404`. Exhausted database concurrency retries return `503` with `code: event_busy` and `Retry-After: 1`; other database errors are not disguised as contention. Mutations share limits of 60/minute per account and 120/minute per IP across bearer/browser routes. Management successes and errors are private and `no-store`.

Management event data includes the existing explicit public event fields plus `owner_id`, `confirmed_count`, `has_history`, `created_at`, `updated_at`, and `event_type.is_active`. `has_history` includes any retained reservation/waitlist attempt, including cancelled/promoted attempts. Reservation/waitlist list rows include their attempt IDs, event/user IDs, status and lifecycle timestamps, plus only `user: {id, name}`. They omit emails, account roles, credentials, tokens, generated active keys and unrelated records. These private lists are inspection tools, not permission to change another user's reservation.

## Lifecycle and inventory

- Draft can publish or cancel. Publishing requires a future start and an active type, checked under the relevant locks. Changing classification also requires an active type. Retiring a type does not invalidate an existing published event's ordinary metadata/capacity changes or booking eligibility.
- Published can cancel or complete. Completion requires its start to have passed; it retains confirmed reservations/count as attendance history and cancels remaining waiters. Published events cannot unpublish.
- Cancelled/completed events cannot change status or reopen. Their `name`, `description` and `venue` remain editable. Actual changes to their schedule, timezone, classification or capacity are rejected; submitting an unchanged protected value is a no-op.
- After any reservation/waitlist history, actual changes to start/end/timezone/type are rejected with errors on affected fields. Rescheduling and its notification policy remain separate work. Ordinary listing metadata remains editable.
- Capacity cannot fall below the currently locked confirmed count. An increase fills current eligible FIFO waiters before newcomers can obtain places. Capacity is never decided by cached availability.
- Event cancellation cancels all currently confirmed reservations and waiting entries and sets the counter to zero atomically. Already cancelled/promoted history stays unchanged. Cancellation does not promote anyone; repeated cancellation changes nothing further. Completed events cannot subsequently cancel.

Every update/cancellation locks and re-reads the event first inside a transaction and reauthorises against that current event. Publishing/type assignment then locks the type before child history reads. Inventory and related attempt changes commit together, with three bounded transaction attempts for database concurrency errors. External email/queue work occurs after commit.

## Bounded capacity-increase promotion

`PromoteWaitlistAction` examines at most 50 entries per transaction, ordered by `joined_at`, then ID, using current locking reads without `SKIP LOCKED`. It checks published/future eligibility and spare capacity, then guards each counter increment with current MySQL time/status/capacity. Each allocation inserts an exact reservation and marks the exact waiter promoted atomically. A waiting entry already holding a confirmed reservation is closed as cancelled and logged after commit; it consumes the examination budget but no additional place.

When eligible backlog and spare capacity remain, `ContinueWaitlistPromotion` is queued after commit on `inventory`. Each run takes the same event lock and recomputes current state. Duplicate/retried runs allocate only actual spare capacity and cannot duplicate an active reservation. The local worker consumes `inventory,emails`. Ordinary direct booking retains its existing conservative refusal while waiters have priority; it cannot bypass a partially drained queue.

To resume after interruption or a lost enqueue, run one explicit batch against the desired event:

```powershell
docker compose exec app php artisan waitlist:promote EVENT_ID
```

The command uses the same action and queues another bounded continuation if needed. It never reopens or allocates for a draft, cancelled, completed or past event. It requires the ordinary application database configuration; do not use it to create test fixtures in development data.

Each promoted reservation queues the existing confirmation email after commit by its exact reservation ID; the action result also records the exact waitlist entry ID. The email job rechecks that exact reservation and event before sending. A dedicated promotion message/template, public join/leave and individual cancellation APIs remain downstream tickets. Cancellation/completion do not send new notifications in this milestone. This reuses the established [transactional email delivery limits](transactional-email.md): a process failure after commit but before enqueue can lose work, and transport retries can duplicate an email. The recovery command resumes allocation; there is no outbox or exactly-once delivery guarantee.

## Verification scope

`EventManagementAcceptanceTest` independently exercises API policies, payload/privacy/lifecycle boundaries and browser origin/session/CSRF behaviour. `EventManagementConcurrencyTest` coordinates independent PHP/MySQL sessions around the event lock for booking/edit/cancellation, stale snapshots, FIFO priority and busy responses. `EventManagementWorkflowTest` exercises bounded batches, deterministic ties, retry safety, inconsistent waiters, transactional rollback, exact-ID after-commit email dispatch and per-allocation start cutoffs. `ApiDocumentationTest` checks the generated operation inventory and management contracts. Use the [isolated test runner](testing.md), never the normal application database:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter='EventManagement|ApiDocumentationTest' --display-warnings --fail-on-warning
```

These are correctness cases, not a throughput benchmark. Full waitlist join/leave/customer-cancellation races and notification reliability remain follow-on work. The validation report records actual execution rather than inferring a pass from the existence of tests.
