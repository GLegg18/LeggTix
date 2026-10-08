# Issue #6 verification report

Verified on 7 October 2026 for [event schema, model, factory and status rules #6](https://github.com/GLegg18/LeggTix/issues/6). The developer, tester and security reviewer roles worked on this milestone. This report records actual execution and limitations; the [event guide](events.md) describes the contracts and the [testing guide](testing.md) gives the normal Docker test command.

## Implemented scope and groundwork

The merged #5 work already supplied the `events` and `event_types` migrations, restricted owner/type foreign keys, exact status checks, positive unsigned capacity, bounded occupancy, end-after-start checks, microsecond UTC timestamp fields and the agreed indexes. This change reuses those migrations and the existing schema/migration tests. It adds no migration and does not alter the ER contract from #27.

New production files are `app/Enums/EventStatus.php`, `app/Models/Event.php`, `app/Models/EventType.php`, `database/factories/EventFactory.php` and `database/factories/EventTypeFactory.php`; `app/Models/User.php` adds inverse ownership. They supply backed status casting, relationships, defaults, immutable schedule casts, UTC storage, strict capacity/IANA timezone validation, protected server-owned fields and the five requested factory states. Upcoming/bookable read helpers use a strict future cutoff; past starts at equality.

The default event factory creates a future draft, an organiser owner, an active type and zero confirmed occupancy. Completed and past fixtures have past schedules; past is derived and the past fixture retains the published status. Type retirement remains independent of eligibility for already published events.

No route, public response contract, dependency, authorization policy, catalogue seed or booking workflow was added. Changes remain local and uncommitted; the pre-existing `.gitignore` edit is preserved.

## Executed checks

| Check | Result |
| --- | --- |
| Event model/factory acceptance | **44 tests, 326 assertions passed**, 3.90 seconds, exit `0`, with `--display-warnings --fail-on-warning`, using PHP 8.4.26 and MySQL 8.4.11. |
| Portable complete suite | **150 tests, 948 assertions passed**, 17.89 seconds, exit `0`, with `--display-warnings --fail-on-warning`, using the same isolated PHP/MySQL runtimes. |
| Docker complete suite | **150 tests, 948 assertions passed**, 10.13 seconds, runner exit `0`, with `--display-warnings --fail-on-warning`. The existing `scripts/test.ps1` built the current source/locked-dependency image, completed package discovery, ran the suite and cleaned up successfully. |
| Artisan/Tinker console acceptance | Passed, exit `0`: persisted factory and inverse relationships, eligibility/upcoming, microseconds, invalid capacity, guarded catalogue mutation, MySQL end/start check, past state and rollback of fixture writes. PsySH configuration was redirected into the temporary runtime after the default user configuration path was inaccessible. |
| PHP syntax | All six changed production files and the new test file passed PHP 8.4.26 `-l`. |
| Documentation and patch formatting | Local Markdown links in all seven new/edited guides/reports resolve; all 38 PowerShell code blocks parse; `git diff --check` passed. |
| Host configuration preservation | SHA256 snapshots of the host `.env`, `vendor/autoload.php` and bootstrap cache files matched before and after both portable and Docker verification. |
| Initial Docker runner attempt | The restricted-shell focused invocation returned exit `1` because executable discovery could not access the per-user Docker installation. This was a shell access failure, not a failed PHPUnit case or an absent installation. |
| Docker isolation and cleanup | Project `leggtix-test-71e645897d7a4021a9c98dd85a57e274` had no remaining containers, networks, volumes or generated images after the runner. Normal app/MySQL/Redis container IDs and start times were unchanged; app health returned `200` with `{"status":"ok"}` and Redis returned `PONG`. |
| Temporary runtime cleanup | The tester stopped the owned MySQL process, verified no MySQL process remained, and removed the exact issue #6 temporary data directory. |

The 44 acceptance cases cover every factory state and default, reused/empty parent relationships, protected server and catalogue fields, immutable UTC schedule/timestamp round trips with microseconds, strict cutoff boundaries including one microsecond before/after start, upcoming query precision, retired types, invalid/boundary capacity and invalid timezones. They complement the inherited schema and migration cases rather than replacing their direct-SQL constraint coverage.

## Findings and role outcomes

The developer completed the production layer and the timestamp fix below. The tester's focused portable suite and both complete portable/Docker suites passed with no implementation defect reported. Each complete run includes the inherited 106 authentication/schema/migration tests plus 44 event acceptance cases.

The security reviewer found one correctness defect, **fixed**: inherited EventType timestamp serialization discarded explicit offsets, storing the wrong UTC instant. For example, `19:30 +05:45` could persist as `19:30 UTC` instead of `13:45 UTC`. EventType now normalizes date instants to UTC before formatting. Read-only probes retested timestamp strings and immutable objects, and the persisted MySQL regression passed with microseconds preserved. No unresolved review finding has been reported.

The reviewer checked mass assignment, casts/defaults, IANA validation, strict unsigned integer capacity, relationship keys, immutable dates, UTC normalization and exact schedule boundaries. Those read-only probes did not perform DB/concurrency stress, Docker or a Composer audit. No dependency change was made.

## Tester manual plan

Follow the [event manual acceptance plan](event-manual-test-plan.md) for PowerShell/Tinker commands. It checks Docker prerequisites and normal app health, runs isolated focused/full acceptance, then creates transactional console fixtures to verify owner/type relationships, every factory state, eligibility, capacity/timezone validation, catalogue guards and the MySQL schedule check. Expected outcomes are recorded beside each step, with rollback restoring the initial record counts.

The tester exercised the portable Artisan/Tinker subset for relationships, eligibility, microseconds, invalid capacity, catalogue guards, invalid end time, past state and rollback. The automated cases covered all factory states and invalid timezones. The complete isolated Docker runner was subsequently exercised, and the coordinator checked normal app/Redis health. The full console walkthrough was not repeated inside Docker; no browser test was performed.

## Verification boundaries

Docker used the configured `php:8.4-cli-bookworm` image and MySQL 8.4, whose shared image metadata reported `MYSQL_VERSION=8.4.11-1.el9`. The exact PHP patch was not captured before the generated test image was removed. Image build, package discovery, MySQL tests and Compose cleanup were verified; browser flows and lifecycle/inventory concurrency were not.

For the portable run, the tester used the retained PHP/MySQL runtimes from #5 with a fresh temporary data directory and `leggtix_issue6_test` database on port `33316`. A restricted test user could access only that database. The normal application database was not selected, and the host `.env` was preserved.

`isBookable()` is a read predicate for status/schedule eligibility, including full published events. It does not allocate capacity or authorize a customer. The documented lifecycle transition table is still a contract for later mutating event management actions. Publication/type assignment requires the type lock and active-type check; cancellation/completion must update related records in the event transaction. Capacity edits and booking/waitlist writers must follow the common inventory lock protocol. This milestone does not establish those workflow guarantees or concurrency/load behavior.

Only native PHP integers are accepted for capacity assignment. Future request code must validate and explicitly normalize its input. UTC instant normalization does not implement parsing of ambiguous local wall-clock schedules; that belongs to the future request workflow.

Domain seeds, event CRUD/policies, reservation/waitlist models/actions, attendee counter reconciliation, inventory race/load tests, browser UI and deployment remain follow-on work. The earlier documented notification commit-to-enqueue reliability gap remains unresolved; no notification delivery is added here.
