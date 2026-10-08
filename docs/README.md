# Project documentation

Start with the [database design decision and research note](database-design.md), written for [issue #27](https://github.com/GLegg18/LeggTix/issues/27). It includes the MVP tables, permissions, lifecycle rules, concurrency protocol, and implementation test plan.

The [ER diagram](diagrams/leggtix-database-er.svg) is a portable visual of the five MVP domain tables. The note remains the source for complete fields and constraints.

The [authentication API guide](authentication.md) documents the implemented registration, login, current-user and logout flow for [issue #5](https://github.com/GLegg18/LeggTix/issues/5), including token expiry, rate limits and the MySQL/SQLite verification boundary.

The [event model guide](events.md) documents the models, relationships, protected fields, UTC scheduling, lifecycle read helpers and factory states for [issue #6](https://github.com/GLegg18/LeggTix/issues/6). The tester's [event manual plan](event-manual-test-plan.md) supplies console checks and expected outcomes. The [issue #6 verification report](issue-6-validation.md) records the checks and review outcomes for that model layer.

The [testing guide](testing.md) explains the disposable Docker runner, argument forwarding, cleanup and troubleshooting. Follow the [manual acceptance checks](manual-test-plan.md) for copy/paste PowerShell steps and expected responses from the live app.

The [issue #5 verification report](issue-5-validation.md) records executed checks, review findings and verification limits. The guides describe how to repeat the checks; the report records what was verified.

Read [reservation races](reservation-races.md) for two-customer timelines covering the last place, the same named seat, cancellation and retry behaviour. The [ticketing extension plan](ticketing-extensions.md) adds staged diagrams and research for venues, seating, organiser teams, overlapping roles and payments. It links future GitHub tickets separately from the MVP work.

| Diagram | What to look for |
| --- | --- |
| [Last-place race](diagrams/leggtix-last-place-race.svg) | A holds the event lock; B waits and re-reads; commit/rollback outcomes. |
| [Same-seat race](diagrams/leggtix-seat-race.svg) | One live holder, exact hold/version checks, safe expiry and late-payment compensation. |
| [Venues and occurrence seating](diagrams/leggtix-venues-seating-er.svg) | Reusable physical layout versus sale inventory for one occurrence. |
| [Teams and optional role mappings](diagrams/leggtix-teams-roles-er.svg) | Explicit event grants; customer capabilities stay available to organisers. |
| [Paid checkout and admission](diagrams/leggtix-paid-checkout-er.svg) | Holds, orders, provider facts, refunds and admission tickets have separate lifecycles. |

The future backlog is [#28](https://github.com/GLegg18/LeggTix/issues/28) through [#35](https://github.com/GLegg18/LeggTix/issues/35), with dependencies and activation decisions in the extension plan. Existing MVP race verification remains [#12](https://github.com/GLegg18/LeggTix/issues/12).

The five domain migrations, authentication, and event models/factories are implemented. Event management, reservation and waitlist workflows, their ownership policies and the documented concurrency protocol remain downstream work; the extension diagrams describe future scope.
