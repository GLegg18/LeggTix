# Project documentation

For endpoint reference and interactive requests, open the running app's [Swagger API explorer](http://localhost:8000/docs/api). The [OpenAPI JSON](http://localhost:8000/docs/api.json) is generated from Laravel with explicit corrections for the implemented response and strict booking-input contracts. The explorer uses local browser assets and is restricted to local/test environments. Markdown notes below cover design decisions, setup and verification history.

Start with the [database design decision and research note](database-design.md), written for [issue #27](https://github.com/GLegg18/LeggTix/issues/27). It includes the MVP tables, permissions, lifecycle rules, concurrency protocol, and implementation test plan.

The [ER diagram](diagrams/leggtix-database-er.svg) is a portable visual of the five MVP domain tables. The note remains the source for complete fields and constraints.

The [authentication API guide](authentication.md) documents registration, login, current-user and logout from [issue #5](https://github.com/GLegg18/LeggTix/issues/5), expanded in [issue #4](https://github.com/GLegg18/LeggTix/issues/4) with pre-account email verification, emailed login challenges and optional seven-day trusted browsers. It includes token expiry, rate limits and the MySQL/SQLite verification boundary.

The [Nuxt frontend guide](frontend.md) covers [issue #20](https://github.com/GLegg18/LeggTix/issues/20): Docker setup, Sanctum SPA session authentication, the homepage/account shell, responsive styling, dark mode and the SVG logo options. Event and reservation interactions belong to [#21](https://github.com/GLegg18/LeggTix/issues/21).

The [local authentication performance investigation](auth-performance-validation.md) records the Windows dependency-storage bottleneck, shared Docker dependency volume, real-browser before/after timings and unchanged authentication protections.

The [event guide](events.md) documents public upcoming-list and published-detail endpoints from [issue #7](https://github.com/GLegg18/LeggTix/issues/7), their explicit public fields, advisory availability and stable cursor pagination. The [public event manual plan](public-events-manual-test-plan.md) supplies expected outcomes, and the [issue #7 verification report](issue-7-validation.md) records checks actually performed. The guide also covers models, relationships, protected fields, UTC scheduling, lifecycle read helpers and factory states from [issue #6](https://github.com/GLegg18/LeggTix/issues/6); the [event model manual plan](event-manual-test-plan.md) and [issue #6 verification report](issue-6-validation.md) retain that milestone's checks.

The [event management guide](event-management.md) covers [issue #8](https://github.com/GLegg18/LeggTix/issues/8): owner/admin policies, private event/attendee/waitlist lists, explicit event writes, lifecycle and inventory locks, event cancellation/completion, and bounded capacity-increase promotion. The [Nuxt organiser workspace](event-management-ui.md) uses the corresponding session-authenticated browser routes. The [management manual plan](event-management-manual-test-plan.md) supplies expected outcomes; the [issue #8 report](issue-8-validation.md) records actual checks and developer/tester/security findings. Customer cancellation, public waitlist join/leave and dedicated promotion notifications remain separate tickets.

The [booking guide](reservations.md) documents the reservation model, authenticated creation endpoint, MySQL transaction, retry outcomes and staged waitlist limitation for [issue #9](https://github.com/GLegg18/LeggTix/issues/9). The [reservation manual plan](reservation-manual-test-plan.md) gives repeatable checks; the [issue #9 validation report](issue-9-validation.md) records execution and review findings.

The [review follow-up checklist](issue-9-review-manual-checks.md) covers equivalent-email login throttling, current returned inventory, the pinned Redis runtime, hosted CI enforcement and the remaining morning Swagger checks. The latest follow-up in the validation report records which checks have actually run.

The [testing guide](testing.md) explains the disposable Docker runner, argument forwarding, cleanup and troubleshooting. Follow the [manual acceptance checks](manual-test-plan.md) for copy/paste PowerShell steps and expected responses from the live app.

The [local email preview guide](../README.md#local-email-preview) documents Mailpit's inbox, Laravel SMTP configuration and the isolated queued-mail smoke check. The [issue #4 verification report](issue-4-validation.md) records acceptance, security review and manual checks for that local service.

The [transactional email guide](transactional-email.md) covers signup-code, login-code, welcome and booking-confirmation templates, successful-workflow hooks, the dedicated worker and delivery limits. The [email validation report](issue-4-email-validation.md) records the earlier signup/booking milestone; login acceptance is recorded separately below.

The [email verification and login security scope note](mfa-assessment.md) records required pre-account ownership, emailed login challenges for all accounts and optional seven-day trusted browsers. The trusted-device cookie still requires a password; welcome emails are separate from authentication codes.

The [registration email verification guide](registration-email-verification.md) details the three-step signup contract, code/proof expiry, mailbox/IP limits, database locking and temporary-state cleanup.

The [login security guide](login-email-verification.md) covers emailed login codes, the seven-day trusted-device cookie, revocation and the API-token rollout policy. The [login validation report](issue-4-login-validation.md) records tests, review findings and manual acceptance.

The [issue #5 verification report](issue-5-validation.md) records executed checks, review findings and verification limits. The guides describe how to repeat the checks; the report records what was verified.

The [local demo setup](../README.md#demo-data-and-login) gives local-only customer/organiser/admin credentials, sample events and confirmed reservations. The [issue #3 verification report](issue-3-validation.md) records acceptance checks, the manual plan and the cancellation/waitlist history dependencies. The [earlier demo report](demo-data-validation.md) records the original six-event dataset.

Read [reservation races](reservation-races.md) for two-customer timelines covering the last place, the same named seat, cancellation and retry behaviour. The [ticketing extension plan](ticketing-extensions.md) adds staged diagrams and research for venues, seating, organiser teams, overlapping roles and payments. It links future GitHub tickets separately from the MVP work.

| Diagram | What to look for |
| --- | --- |
| [Last-place race](diagrams/leggtix-last-place-race.svg) | A holds the event lock; B waits and re-reads; commit/rollback outcomes. |
| [Same-seat race](diagrams/leggtix-seat-race.svg) | One live holder, exact hold/version checks, safe expiry and late-payment compensation. |
| [Venues and occurrence seating](diagrams/leggtix-venues-seating-er.svg) | Reusable physical layout versus sale inventory for one occurrence. |
| [Teams and optional role mappings](diagrams/leggtix-teams-roles-er.svg) | Explicit event grants; customer capabilities stay available to organisers. |
| [Paid checkout and admission](diagrams/leggtix-paid-checkout-er.svg) | Holds, orders, provider facts, refunds and admission tickets have separate lifecycles. |

The future backlog is [#28](https://github.com/GLegg18/LeggTix/issues/28) through [#35](https://github.com/GLegg18/LeggTix/issues/35), with dependencies and activation decisions in the extension plan. Existing MVP race verification remains [#12](https://github.com/GLegg18/LeggTix/issues/12).

The five domain migrations, authentication, public event discovery, event models/factories, local demo seeders, reservation creation and owner/admin event management are implemented. Booking and management share the event-first inventory protocol. Capacity increases can promote current FIFO waiters through bounded resumable batches; direct bookings refuse queue bypass. Customer reservation cancellation/history, public waitlist join/leave, their full promotion integration and dedicated notifications remain downstream work. The extension diagrams describe future scope.
