# Organiser workspace for issue #8

The existing Nuxt account shell now includes a small organiser workspace. Owners can exercise the management API with the same session and email-factor login. Laravel remains responsible for every permission and business rule.

Open [Manage events](http://localhost:3000/manage/events) after signing in as an organiser or admin. The [API guide](event-management.md), [manual plan](event-management-manual-test-plan.md) and [validation report](issue-8-validation.md) document the contract, expected outcomes and executed checks. The local Swagger explorer can exercise the backend independently.

## Implemented screens

- **Manage events** links in the header and account page for organisers/admins. Organisers see their own events; admins see all events. Guests see a sign-in prompt, ordinary customers see an access-denied explanation, and API failures offer retry.
- `/manage/events` lists bounded pages with name, schedule, status and confirmed occupancy/capacity. Private detail remains available for drafts, cancelled and completed events.
- `/manage/events/new` creates drafts using active type choices, name, description, venue, capacity, explicit schedule instants and an IANA display timezone. Publication follows review of the draft.
- `/manage/events/{id}` edits listing information and capacity. History freezes schedule/type; closed events permit name/description/venue corrections only. Only changed fields are sent, so an unchanged retired type does not block ordinary edits. Laravel returns the final validation outcome because bookings/state can change while a form is open.
- Publish drafts, mark past published events completed, and cancel eligible events. Cancellation confirmation names the event and describes the effect on reservations/waiters. Save and lifecycle controls coordinate their pending states to prevent overlapping UI submissions.
- Read-only paginated reservation and waitlist history shows deliberate account IDs/names, attempt references, status and timestamps. No private account email/credential fields are requested or rendered.

The workspace reuses the light/dark theme, form errors, loading states and SPA cookie/CSRF client. Private records stay in component memory and clear when account/session state changes. Request revisions discard stale responses. Narrow screens use a stacked layout, labelled form controls, keyboard-accessible actions and announced errors.

Schedule entry uses explicit UTC/offset values. The browser does not silently reinterpret a wall-clock time in its own timezone. A later local-time picker needs deliberate daylight-saving gap/ambiguity handling before sending an unambiguous instant to Laravel.

## Boundaries

The live GitHub roadmap was checked on 27 October 2026. [#20](https://github.com/GLegg18/LeggTix/issues/20) is the completed Nuxt/auth shell. [#21](https://github.com/GLegg18/LeggTix/issues/21) covers customer discovery, personal reservations and reserve/cancel/join interactions; none of those screens is claimed here. [#22](https://github.com/GLegg18/LeggTix/issues/22) is an optional internal Filament area and overlaps the event-management capabilities, but remains a separate optional surface for staff/system administration. It must reuse these policies/actions rather than introduce a second inventory writer. The user approved the Nuxt organiser workspace during #8 after asking for this overlap check; no later ticket was closed or rewritten.

The active-type lookup is sufficient for this form. Catalogue writes, role assignment, ownership transfer, Filament installation, teams, seating, payments, media uploads and rescheduling remain separate work. Capacity-increase allocation supplies a shared foundation for [#14](https://github.com/GLegg18/LeggTix/issues/14); customer cancellation/join/leave and the dedicated [#15 promotion notification](https://github.com/GLegg18/LeggTix/issues/15) remain unimplemented.

## Acceptance

Exercise the workspace at desktop and mobile widths with organiser, admin and ordinary-customer sessions. Verify direct navigation, private draft detail, next/previous pages, create/edit/publish/cancel, frozen schedule/type after history, capacity validation, stale-session recovery and server-side IDOR denial. Browser tests with mocked responses check interaction only; also perform the real Laravel/Mailpit sign-in and persisted-data walkthrough.
