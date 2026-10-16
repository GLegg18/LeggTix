# Issue #7 verification report

Verification on 14 October 2026 for [public event list and detail endpoints #7](https://github.com/GLegg18/LeggTix/issues/7), using the developer, tester and security reviewer flow. The coordinator also reviewed the repository documentation and exercised the running local API. This report records actual checks; the [event guide](events.md) defines the API contract and the [manual plan](public-events-manual-test-plan.md) provides repeatable acceptance steps.

## Behavior and implementation

The user confirmed the visibility rule: `GET /api/events` lists strictly future published events, while `GET /api/events/{event}` also returns past published events. Draft, cancelled, completed and missing IDs produce the same generic JSON `404`, including for owners and admins using the public routes. Full events and already published events whose type has been retired remain visible.

Both reads use an explicit resource containing `id`, `name`, `description`, `venue`, UTC `starts_at` and nullable `ends_at`, display `timezone`, `capacity`, advisory `remaining_capacity`, `status` and `event_type` with only `id`, `slug` and `name`. Ownership, account/contact data, reservations, waitlist records, the raw occupancy counter and internal timestamps are omitted. Availability comes from the event's MySQL counter; it never allocates a place. Successful responses use `Cache-Control: no-store, private`.

The list uses cursor pagination ordered by `starts_at, id`, preserving microseconds and equal-time ID ties in both directions. Page size defaults to 20 and is limited to 1 through 100. Only `per_page` and `cursor` query parameters are accepted; invalid inputs return `422`, and a GET body cannot supply pagination. Event types are eager loaded without counting or loading attendees. Pagination reflects current data rather than a fixed snapshot.

Important files:

- [EventController](../app/Http/Controllers/EventController.php), [ListEventsRequest](../app/Http/Requests/ListEventsRequest.php), [EventResource](../app/Http/Resources/EventResource.php) and [API routes](../routes/api.php) implement the public reads.
- [ApiSpecification](../app/Documentation/ApiSpecification.php) and [ApiDocumentationTest](../tests/Feature/ApiDocumentationTest.php) describe and verify the public routes, closed response field set, pagination and errors.
- [EventApiTest](../tests/Feature/EventApiTest.php) covers the baseline contract; [PublicEventBoundaryTest](../tests/Feature/PublicEventBoundaryTest.php) supplies the tester's independent boundary cases.
- The project README, documentation index, event guide and testing guide now include event discovery and its verification commands.

## Executed checks

| Check | Result |
| --- | --- |
| Initial isolated focused MySQL run | 49 passed, 1 failed, 415 assertions. The failed supplementary test submitted a JSON array to the existing booking endpoint, which correctly requires an empty object. The tester corrected the fixture to send `{}`; this was not an application defect. Runner cleanup succeeded. |
| Complete isolated MySQL suite | **459 tests, 3,925 assertions passed**, PHPUnit duration 69.78 seconds, runner exit `0`, no warnings. The corrected test fixture and all existing authentication, schema, email, booking and MySQL race cases passed. Temporary MySQL/container/network/image cleanup succeeded for `leggtix-test-848931084be742bba50b3f486dbac70a`. |
| Coordinator live HTTP checks | Passed: unauthenticated list/detail, explicit fields and no-store headers, next/previous cursor round trip, page sizes 0 and 101, malformed cursor and unsupported status query returning `422`, missing/nonnumeric IDs returning `404`. |
| Coordinator live visibility checks | Passed against existing demo records: draft/cancelled/completed absent from list and `404` by ID; past published absent from list and `200` by ID; full event present with zero remaining capacity. Existing demo IDs/statuses were read with Tinker; no fixtures were created or changed. |
| Coordinator generated OpenAPI check | Passed against `/docs/api.json`: both new GET operations are public; the resource has exactly the agreed 11 fields with `additionalProperties: false`; the list has the cursor `data`, `links`, `meta` envelope. |
| Tester independent live checks | Passed: a JSON GET body attempting to override pagination could not replace `per_page=1` from the query; response was `200`, one event, `meta.per_page: 1`, and no-store headers. `per_page=0` returned JSON `422` without an Accept header. Manual fixture commands returned eight existing demo events and valid inventory-snapshot JSON. No live database writes were performed. |
| Independent security probes | Passed: 15 cursor shapes covered malformed types, missing keys, dates, ID overflow, direction, distant valid boundaries and reverse pagination. Invalid inputs returned `422`; valid boundaries returned `200` without SQL errors. Exact resource/metadata fields and no-store headers were confirmed. |
| Locked dependency audit | Security reviewer ran `composer audit --locked --no-interaction --format=json`: exit `0`, no advisories and no abandoned packages; PHP 8.4.26. |
| Patch and documentation checks | Passed: `git diff --check`; 102 local Markdown links resolve and all 38 PowerShell blocks parse across the six updated/new Markdown documents. The tester also checked all 12 blocks in the manual plan. |
| Final cleanup and normal-stack health | Coordinator independently confirmed no containers, network or generated image remained for the final test project. Normal API health returned `200`; app, MySQL, Redis and Mailpit remained healthy and the worker remained running. |

The focused command was:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter='EventApiTest|PublicEventBoundaryTest|ApiDocumentationTest' --display-warnings --fail-on-warning
```

The successful complete command was:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
```

## Findings and role outcomes

The developer implemented the endpoints, baseline feature tests, OpenAPI contract and guides. Three framework edge cases were addressed during implementation:

| Risk and cause | Fix and verification |
| --- | --- |
| Carbon's ordinary string conversion drops microseconds when Laravel builds a cursor, which can repeat or skip events sharing a second. | Order through a raw timestamp alias, preserving the stored precision. The public resource omits the alias. Bidirectional microsecond and ID-tie regressions passed in focused acceptance. |
| Laravel's permissive cursor decoder can treat malformed input as the first page or fail when an ordering key is missing. | Validate encoded length, shape, expected keys, date, ID and direction before pagination. Focused regressions and independent live malformed-cursor probes passed. |
| The paginator's default input resolver can consume an unvalidated JSON GET body. | Validate query parameters only and explicitly supply the validated cursor, including the absent-cursor sentinel. The focused query/body regression passed. |

The tester added owner/admin/customer visibility checks, cursor revalidation after publication/start-time changes, and repeated read checks after a real booking to verify inventory and queues remain unchanged. The only reported test failure was the invalid booking fixture described above, **fixed** in the test client and verified by the successful complete suite. No product defect was reported by the tester.

The security reviewer reported **no actionable findings** and required no remediation. The review covered visibility, resource exposure, cursor validation and precision, query safety and bounds, eager loading, advisory capacity, cache headers and the final OpenAPI/docs. An initial ten-second null-cursor probe timeout was **not reproduced**: retry returned the expected `422`. No unresolved security, authorization, concurrency or data-integrity finding was reported within this ticket's scope.

## Manual plan and verification limits

Follow the tester's [12-step public event manual plan](public-events-manual-test-plan.md) for the complete PowerShell commands. Its relevant acceptance checks are:

| Manual checks | Expected outcomes |
| --- | --- |
| Prepare the local stack and run isolated acceptance | Healthy services, installed migrations, two public GET routes and the existing reservation POST route; tests pass and temporary test resources are removed. Preserve existing local data. |
| Inspect anonymous list/detail, Swagger and pagination | `200` without authorization, only the agreed public fields, UTC schedule plus display timezone, advisory availability and no-store headers. A stable forward/backward round trip returns the original IDs; default size is 20 and maximum is 100. Swagger's Events operations expose the same reads and errors. |
| Check full, past, hidden and missing events | Full published events remain visible with zero remaining places. Past published detail returns `200` but is absent from the list. Draft/cancelled/completed and missing detail IDs return generic JSON `404`. |
| Try invalid input and authenticated public reads | Bad sizes/cursors and unsupported filters return `422`. Optional owner/admin login does not reveal hidden events through the public routes; logout revokes the temporary token. These role boundaries also run automatically. |
| Repeat reads and inspect inventory/logs | Event capacities/counters/timestamps and reservation/waitlist records stay unchanged; public reads queue nothing. Inspect unexpected errors without resetting or deleting the normal database. |

The coordinator exercised the live list/detail, field and header, pagination, visibility, invalid-input and generated-schema checks described above. The tester exercised the independent GET-body/invalid-size checks, fixture-read commands and automated cases; the optional owner/admin email-login walkthrough was not performed live. Reviewer probes were also read-only. Browser interaction with Swagger and its Console/Network inspection, proxy/cache behavior, load benchmarks and an actual concurrent schedule-edit benchmark were not run. Hosted CI was not run for these uncommitted changes.

These endpoints do not change the schema or reservation allocation protocol. Filtering/search, event management, cancellation/history and waitlist workflows remain separate work. No frontend or cloud deployment work was added. The user's pre-existing `.gitignore` edit is preserved; changes remain local and uncommitted on the existing `LT-7-Expose-Public-Endpoints` branch.
