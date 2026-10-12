# Issue #3: reproducible demo dataset

Implementation and verification record for [issue #3](https://github.com/GLegg18/LeggTix/issues/3). Implementation checks ran on 9 October 2026; live manual checks and a focused regression rerun ran on 12 October 2026. The [README demo setup](../README.md#demo-data-and-login) is the guide for local commands, credentials and the event matrix. The [earlier report](demo-data-validation.md) records the initial seeders before reservations were included.

## Changes and scope

`DatabaseSeeder` delegates to `DemoSeeder`, which rejects every environment except `local` and `testing` before database access. A fresh seed creates five users (three regular users, one organiser, one admin), five active event types, eight organiser-owned events and three confirmed reservations. All credentials are known local-only examples; password hashes are created normally and no access token is seeded.

The original six events and concert/workshop/meetup catalogue remain. Live-show and film-screening types and two events are added. Demo User holds one of 120 places at Riverside Acoustic Night. Demo Attendees One and Two fill Studio Live Showcase's two places. Independent Film Screening is available with 30 places. Draft, cancelled, completed and past-published examples remain available. UTC instants and the London display timezone follow the existing event model.

Bookings use `ReserveEventAction`; the seed never inserts a confirmed reservation or increments inventory directly. All fixtures share one outer transaction with bounded retries. Event-row locks and current locking reads validate that `confirmed_count` matches confirmed rows. A failed allocation, inconsistent occupancy, wrong-role account collision, inactive type needed for a new event, ambiguous event identity or vetoed save aborts the transaction rather than forcing existing records into the fixture shape.

## Repeat and upgrade behavior

Stable emails, type slugs and owner/event names identify fixture records. Existing passwords, field edits, schedules, retired types, unrelated rows and attempt histories are retained. Plain `db:seed` upgrades the previous dataset by creating missing fixtures and the three missing demo bookings. A booking is skipped if any reservation or waitlist attempt already exists for that exact event/user pair, including cancelled/terminal history; replay never reactivates an old attempt or books a former waiter automatically.

Existing schedules age in place. Replaying a completed seed remains safe after its dates pass; a missing booking on an expired legacy event cannot be allocated and causes a descriptive failure. Existing full events or waiters may similarly prevent a missing fixture booking. Use the README's explicit destructive reset only when deleting local records is intended. Renaming/deleting a fixture identity can recreate its original sample; the seeder is not a reset or reconciliation command.

## Acceptance and deferred design handoff

The branch implements the four seed acceptance criteria: a documented command creates users/events/reservations, available/full/cancelled/past examples exist, replay is documented without duplicate attempts, and credentials are local-only. It also adds all roles and the requested catalogue types.

The extended database-design handoff additionally asks for cancelled/rebooked and waiting/promoted/left/rejoined demo attempts. These are **deferred to [#10](https://github.com/GLegg18/LeggTix/issues/10), [#13](https://github.com/GLegg18/LeggTix/issues/13) and [#14](https://github.com/GLegg18/LeggTix/issues/14)** because their production cancellation/join/leave/promotion actions do not exist. This branch does not invent those workflows or bypass them with direct inventory writes. Extend the seed through those actions when they are available; the full design handoff is not yet complete.

## Executed checks and review outcomes

### 9 October implementation checks

| Check | Result |
| --- | --- |
| Focused seed acceptance | `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=DemoSeederTest`: **40 tests, 545 assertions passed**, 2.34 seconds, exit `0`. |
| Full isolated Docker/MySQL suite | The same runner without the filter: **277 tests, 2,174 assertions passed**, 23.45 seconds, exit `0`. |
| Disposable cleanup | Both projects (`leggtix-test-7d7b874537b84d86b6e60ef0e7b1772e` and `leggtix-test-f0584391adc748b1817d40212e5458fd`) removed their MySQL containers, generated images and networks successfully. The normal development database was not seeded or reset. |
| PHP syntax/runtime | `docker compose exec -T app php -l database/seeders/DemoSeeder.php` passed under PHP 8.4.26 CLI. |
| Pre-database environment refusal | Independent read-only probe set the default connection to a deliberately nonexistent connection and invoked both seed entry points under production, staging, development and empty environment. All eight combinations refused with the expected local/testing error before any database access. |
| Documentation | All 55 local file links across the five updated/new guides and reports resolve. `git diff --check` passed. |

The tester exercised initial local/testing creation, all five account logins, the exact fixture matrix and inventory equality, and Laravel HTTP feature requests for authentication, holder-field rejection, successful booking, duplicate/full rejection and non-bookable event states. Tests also cover upgrading the previous dataset while preserving credentials/details/unrelated data, exact replay snapshots, cancelled and waiting/promoted/left history preservation, missing-fixture repair, ambiguous identities, occupancy drift, role/type conflicts and non-local refusal. Injected exceptions and vetoed saves on the second reservation prove rollback includes the earlier successful allocation and all new fixture records. No warnings appeared in returned test output; strict `--fail-on-warning` was not enabled for these runs.

- **Developer:** implementation complete for the seed acceptance criteria. Production changes are confined to `DemoSeeder`; no migration, API, dependency or business action changed.
- **Tester:** focused and full acceptance passed. No implementation defect was reproduced or handed back for a fix. Test setup now anchors fixture time to MySQL UTC, matching the existing booking action's database-clock cutoff and keeping future fixtures valid as calendar dates advance.
- **Security reviewer:** reviewed the environment/role boundaries, password and token handling, exact event identity, current event/history/counter reads, cross-table active checks, transaction rollback and installed Laravel nested retry behavior. No actionable code issue or unresolved security/data-integrity finding was identified. One documentation accuracy finding was **fixed**: the upgrade plan now states that new bookings update existing event occupancy/timestamps rather than promising every existing column remains unchanged. The reviewer rechecked the wording; the legacy-upgrade test verifies the preserved fields separately from those inventory updates.

The full suite includes the existing independent reservation race tests. Concurrent seeder-versus-seeder or seeder-versus-booking stress tests were not added or run; seeder concurrency conclusions are based on the event-first lock protocol and source review. At the 9 October handoff, no browser walkthrough, real network API walkthrough, normal-app seed/reset, hosted CI execution or dependency audit had been performed. Those test HTTP requests ran through Laravel's feature-test kernel in disposable MySQL. The 12 October follow-up below records the later normal-app and network checks. The deferred cancellation/waitlist demo histories above remain the outstanding design-handoff gap.

### 12 October live manual checks

The existing Docker stack was already healthy, so no rebuild, application-key replacement, service shutdown or destructive reset was needed. The executable resolved to `C:\Users\georg\AppData\Local\Programs\DockerDesktop\resources\bin\docker.exe`; Docker Compose was v5.5.1 with Linux containers. Sandbox restrictions blocked the Docker named pipe and localhost socket initially; the authorized commands succeeded with elevated sandbox access. The local application used PHP 8.4.26 and MySQL 8.4.11.

| Check actually run | Result |
| --- | --- |
| Local preflight | Confirmed `APP_ENV=local`, MySQL host `mysql`, database `leggtix`, five expected demo accounts with correct roles, and all eight demo events. The database already contained the extended dataset: six total users (including one unrelated user), five types, eight events, three confirmed reservations, zero waitlist entries/tokens/failed jobs and seven migrations. |
| `docker compose exec -T app php artisan migrate --seed --no-interaction` | Exit `0`; nothing to migrate. Per-row hashes across all eight inspected tables matched the preflight exactly, preserving the unrelated user and all existing records. |
| `docker compose exec -T app php artisan db:seed --no-interaction` | Exit `0`; exact unchanged per-row hashes, IDs, timestamps, password hashes, counters and histories. |
| Health and API explorer over real HTTP | `/up`, `/docs/api` and `/docs/api.json` returned `200`; the schema contained `/events/{event}/reservations` with server prefix `/api`. The first probe incorrectly expected the prefix inside the path key; correcting that assertion required no application change. |
| Five account login/profile/logout checks | Each README email with `demo-password` logged in with `200`, returned its expected email/role from `/api/user` with `200`, and logged out with `204`. Tokens stayed in process memory and were omitted from command logs. Final token count returned to the initial zero. |
| Authentication and writable-field boundary | Guest booking returned `401`; authenticated `{"user_id":999999}` returned `422`, without an allocation. |
| Available booking | Demo User booked Independent Film Screening: `201`, confirmed reservation `17`, event `50`, holder `9`. The database has one confirmed row and occupancy `1/30`. |
| Duplicate and full rejection | Screening replay and the existing Riverside booking returned `409 already_reserved`; Studio Live Showcase returned `409 full` and retained `2/2` occupancy. |
| Non-bookable event rejection | Cancelled Garden Session, completed Intro to Web APIs, past published Community Meetup and draft Coffee Meetup each returned `409 event_not_bookable`. |
| Final database invariants and preservation | Zero counter mismatches/oversells, duplicate active reservation pairs, duplicate waiting pairs or confirmed-plus-waiting conflicts. Only reservation `17` and screening occupancy/update timestamp differed from the preflight; every existing event's other details and all other existing rows were unchanged. |
| Replay after the manual booking | A further `db:seed --no-interaction` returned `0` and preserved exact table row hashes, including the new manual reservation. |
| Operational checks | `php artisan queue:failed` reported `No failed jobs found.` The app console log showed the expected requests and no application error. Services were left running. |
| Fresh isolated automated acceptance | `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 '--filter=DemoSeederTest\|ReservationConcurrencyTest' --display-warnings --fail-on-warning`: **48 tests, 642 assertions passed**, 9.96 seconds, exit `0`, with warnings treated as failures. This covers the 40 seed cases (including legacy upgrade and refusal/rollback cases) and eight independent-process MySQL reservation-race cases. |
| Disposable cleanup | Project `leggtix-test-8f7b169594984400a59196f8a1f81a2f` removed its temporary MySQL container, generated image and network successfully. |

The successful manual booking is deliberately retained: the current local database has four confirmed reservations, with screening occupancy `1/30`. This is expected activity after seeding; a fresh disposable dataset still starts with the documented three reservations. All five login tokens were revoked. No production source or test code changed during these checks, and no new implementation bug was found. The full suite was not repeated because source was unchanged; its 9 October result remains above.

The tester executed the isolated follow-up, and the coordinator executed the real local HTTP/console walkthrough and state comparisons. The earlier developer/security findings remain as recorded; no new security review was run for this documentation-only follow-up. Browser clicking/console inspection, destructive reset, hosted CI and concurrent-seeder stress testing were not performed. Cancellation/rebooking and waitlist/promotion demo generation remain deferred as documented above.

## Manual plan and expected outcomes

Use a disposable local database when exercising reset or negative cases. These steps are the reproduction plan; the records above distinguish normal-app manual checks from isolated automated acceptance. Existing local data may include later bookings, so exact initial counts apply to a fresh database only.

1. Follow the README first-run migration/seeding steps. Expect five demo users, five types, eight events, three confirmed reservations, no waitlist entries and no tokens on a fresh database. Every event's counter must equal its confirmed row count and remain at most capacity.
2. Run `docker compose exec app php artisan db:seed` again. Expect the same row IDs, timestamps, hashes, booking histories and counters; unrelated records remain unchanged.
3. Log in using each README account and `demo-password`. Expect `200` and the documented role. Existing modified passwords remain modified on replay. Seeding itself creates no token; ordinary login does.
4. As Demo User, book Independent Film Screening with `{}`. Expect `201`, then `409 already_reserved` on retry. Attempt Studio Live Showcase: expect `409 full`. Riverside already has this user's booking, so expect `409 already_reserved` immediately. Counters and committed rows must still agree.
5. On a copy of the earlier six-event dataset, run plain seeding. Expect the new fixture records and missing bookings to be added without overwriting existing credentials, event details or history. The ordinary booking action updates occupancy and event timestamps for newly allocated places. If the legacy Riverside event is already past, full or queued, expect a descriptive error with no partial additions; deliberately reset only an expendable local database.
6. Exercise wrong-role collision, occupancy mismatch and failed reservation save in the isolated test runner. Expect refusal and a fully rolled-back fixture set. Non-local/testing environments must reject seeding, including `--force`, before database writes.
7. After #10/#13/#14 implement their workflows, add seeded cancellation/rebooking and FIFO promotion/leave/rejoin scenarios. Verify distinct historical attempt IDs, no cross-table active conflict, exact counter equality and replay without double allocation.
