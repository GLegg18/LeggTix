# Project documentation

For endpoint reference and interactive requests, open the running app's [Swagger API explorer](http://localhost:8000/docs/api). The [OpenAPI JSON](http://localhost:8000/docs/api.json) is generated from Laravel with explicit corrections for the implemented response and strict booking-input contracts. The explorer uses local browser assets and is restricted to local/test environments. Markdown notes below cover design decisions, setup and verification history.

Start with the [database design decision and research note](database-design.md), written for [issue #27](https://github.com/GLegg18/LeggTix/issues/27). It includes the MVP tables, permissions, lifecycle rules, concurrency protocol, and implementation test plan.

The [ER diagram](diagrams/leggtix-database-er.svg) is a portable visual of the five MVP domain tables. The note remains the source for complete fields and constraints.

The [authentication API guide](authentication.md) documents the implemented registration, login, current-user and logout flow for [issue #5](https://github.com/GLegg18/LeggTix/issues/5), including token expiry, rate limits and the MySQL/SQLite verification boundary.

The [event model guide](events.md) documents the models, relationships, protected fields, UTC scheduling, lifecycle read helpers and factory states for [issue #6](https://github.com/GLegg18/LeggTix/issues/6). The tester's [event manual plan](event-manual-test-plan.md) supplies console checks and expected outcomes. The [issue #6 verification report](issue-6-validation.md) records the checks and review outcomes for that model layer.

The [booking guide](reservations.md) documents the reservation model, authenticated creation endpoint, MySQL transaction, retry outcomes and staged waitlist limitation for [issue #9](https://github.com/GLegg18/LeggTix/issues/9). The [reservation manual plan](reservation-manual-test-plan.md) gives repeatable checks; the [issue #9 validation report](issue-9-validation.md) records execution and review findings.

The [testing guide](testing.md) explains the disposable Docker runner, argument forwarding, cleanup and troubleshooting. Follow the [manual acceptance checks](manual-test-plan.md) for copy/paste PowerShell steps and expected responses from the live app.

The [issue #5 verification report](issue-5-validation.md) records executed checks, review findings and verification limits. The guides describe how to repeat the checks; the report records what was verified.

The [local demo setup](../README.md#demo-data-and-login) gives seeded customer/organiser credentials and sample event data. The [demo data verification report](demo-data-validation.md) records seeder acceptance checks and the manual plan.

Read [reservation races](reservation-races.md) for two-customer timelines covering the last place, the same named seat, cancellation and retry behaviour. The [ticketing extension plan](ticketing-extensions.md) adds staged diagrams and research for venues, seating, organiser teams, overlapping roles and payments. It links future GitHub tickets separately from the MVP work.

| Diagram | What to look for |
| --- | --- |
| [Last-place race](diagrams/leggtix-last-place-race.svg) | A holds the event lock; B waits and re-reads; commit/rollback outcomes. |
| [Same-seat race](diagrams/leggtix-seat-race.svg) | One live holder, exact hold/version checks, safe expiry and late-payment compensation. |
| [Venues and occurrence seating](diagrams/leggtix-venues-seating-er.svg) | Reusable physical layout versus sale inventory for one occurrence. |
| [Teams and optional role mappings](diagrams/leggtix-teams-roles-er.svg) | Explicit event grants; customer capabilities stay available to organisers. |
| [Paid checkout and admission](diagrams/leggtix-paid-checkout-er.svg) | Holds, orders, provider facts, refunds and admission tickets have separate lifecycles. |

The future backlog is [#28](https://github.com/GLegg18/LeggTix/issues/28) through [#35](https://github.com/GLegg18/LeggTix/issues/35), with dependencies and activation decisions in the extension plan. Existing MVP race verification remains [#12](https://github.com/GLegg18/LeggTix/issues/12).

The five domain migrations, authentication, event models/factories, local demo seeders and reservation creation are implemented. Booking uses the event-first concurrency protocol and conservatively protects existing waitlist priority. Event management, cancellation/history and waitlist workflows remain downstream work; the extension diagrams describe future scope.
