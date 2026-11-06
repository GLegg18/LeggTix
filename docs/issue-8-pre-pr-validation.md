# Issue #8 pre-PR validation — 5 November 2026

The [6 November dependency review](dependency-security-validation.md) supersedes the dependency-alert status below and verifies the runtime/security package follow-up. This report retains the original manual-plan execution evidence.

The [management manual plan](event-management-manual-test-plan.md) was exercised against the running local Docker app, followed by isolated MySQL regressions. The developer implemented the API error correction, the tester independently checked API boundaries and live acceptance, and the security reviewer inspected the implementation read-only. The coordinator ran the live browser walkthrough, isolated suites and final service/data checks, and owns this report.

## Result and correction

Public draft, cancelled, completed and missing events now return the same nonempty `404` JSON:

```json
{"message":"Resource not found."}
```

The screenshot exposed Laravel's default debug rendering of an empty `abort(404)` response: local debug added exception classes, paths and traces. `ApiErrorResponse`, registered in `bootstrap/app.php`, now supplies safe authentication/HTTP error messages even with local debug enabled. Unexpected JSON failures expose details only in the exact `local`/`testing` environments with debug enabled; other environments get a generic `500`, including when debug was accidentally enabled. Framework reporting remains intact. Validation fields, explicit business codes, status/retry/method/CORS headers and private cache controls remain available. See the [API error contract](api-errors.md).

This is an API/JSON response policy. Ordinary HTML error pages retain Laravel's rendering configuration; production still requires `APP_DEBUG=false`. No environment file, schema, dependency or frontend source was changed in this follow-up.

The screenshot's event **55** was already published when this review began, so its live public URL correctly returned `200`. It was not modified for this review. Dedicated event **59** established the draft/published/cancelled `404`→`200`→`404` behavior instead.

The OpenAPI reference now documents the exact public `404` and safe error behavior. Its management `503` schema also includes the existing required `event_busy` code; that was a documentation gap rather than a new API behavior.

## Manual plan execution

Browser steps used headless Playwright Chromium against the actual development Nuxt frontend, Laravel, MySQL, Redis worker and Mailpit; API responses were not mocked. A local bridge preserved the configured `localhost:3000` origin. The separate PHP acceptance runner issued real HTTP requests against the normal local app and used actual password/email-code authentication. It read inventory for assertions; it did not manufacture waiters or mutate inventory through SQL.

| Plan step | Expected outcome and actual result |
| --- | --- |
| 1 | **Passed.** Docker Compose v5.5.1 and Linux engine confirmed. Existing key/database preserved. |
| 2 | **Passed.** `up -d`, `migrate --seed`, worker restart and migration status executed. Nothing pending; nine migrations `Ran`; demo seeder completed create-only. Final app/frontend/MySQL/Redis/Mailpit healthy and worker running. |
| 3 | **Passed.** Health, frontend, Swagger and Mailpit reachable; HTTP checks returned `200`. |
| 4 | **Passed.** Organiser browser login completed a real Mailpit code; password alone left current-user access unauthenticated. |
| 5 | **Passed for available data.** All 13 listed events belonged to the organiser. Desktop/light and 393-pixel/dark screenshots inspected; no horizontal overflow. The event list had one page, so live next/previous event navigation was not applicable. Private attendee pagination was exercised in step 12. |
| 6 | **Passed.** Whole-field start/end picker clicks, Escape and keyboard segment editing worked. London 1 December 2027 18:00 created owned draft **59**, capacity two, zero occupancy and null end. Hidden/missing public responses were identical safe `404`s. |
| 7 | **Passed.** Metadata persisted after reload. Invalid capacity/date and London clock-gap time prevented writes. Both 31 October 2027 01:30 occurrences saved distinct UTC instants (`00:30Z` / `01:30Z`). New York/London changes preserved the instant. Valid publication returned `200` and public visibility. API unpublication was rejected. |
| 8 | **Passed.** New private lists showed empty states and disabled pager controls. Named cancellation confirmation could be dismissed without a mutation. Confirming cancellation persisted after reload, hid public detail and left metadata editable with no reopen control. |
| 9 | **Passed.** Customer had no management link and direct list/detail visits showed denial; guest visit offered sign-in. Admin could inspect organiser event **59** without changing owner **10**. |
| 10 | **Passed using the equivalent live runner.** Organiser/customer/admin each completed password and email factor; bearer tokens remained in memory. |
| 11 | **Passed.** Active choices exposed only `id,slug,name`. API drafts **57/58** returned `201`, ownership matched their actors and occupancy was zero. Public draft/missing `404`s matched; authorised private reads were `200` with no-store/private caching. |
| 12 | **Passed.** Publication succeeded; customer and admin booked two places. Committed count equalled two confirmed rows and capacity. Attendee pages with `per_page=1` returned both records in order without duplicates and only `user:{id,name}`. Waitlist was explicitly empty. Both confirmation emails arrived before cancellation. |
| 13 | **Passed.** Guest `401`, customer/foreign-owner `403`, strict-field/type/history/capacity `422` and malformed JSON `400` left protected values intact. Post-booking metadata correction succeeded. Expected errors contained no debug fields. |
| 14 | **Passed.** Cancellation and replay returned `200`, retained reservation IDs **21/22**, preserved identical cancellation timestamps, zeroed committed occupancy and did not promote. |
| 15 | **Passed.** Reopening and published-to-draft writes returned `422`; a cancelled-event booking returned `409/event_not_bookable`. |
| 16 | **Passed.** Focused isolated management acceptance/concurrency, API errors and OpenAPI run exited zero. It includes all four independent-process MySQL inventory races described in the manual plan. Temporary stack cleanup succeeded. |
| 17 | **Passed.** Complete isolated suite: **545 tests, 4,905 assertions**, **84.10 seconds**, warnings treated as failures, exit zero. Disposable MySQL, generated image and network removed. |
| 18 | **Passed for the stated local checks.** Both real booking emails captured; `queue:failed` reported none. Explicit `waitlist:promote 57` reported zero waiters for the cancelled event. The bounded post-recovery app/worker/frontend log sample had 107 lines and no error/exception/fatal/warning matches. |
| 19 | **Passed.** Review events **57/58/59** retained cancelled, with zero confirmed counters and rows; attempts **21/22** retained cancelled. Tokens **50/51/52** revoked and rejected afterward; final database read found none remaining. All three browser sessions logged out and failed subsequent current-user access. No shared volume, table, queue or inbox reset. |

## Executable evidence and repeat commands

The full suite command was:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
```

The focused run added `--filter=EventManagementAcceptanceTest|EventManagementConcurrencyTest|ApiError|ApiDocumentationTest`. Its completed disposable project was `leggtix-test-9eec04de04094378b315842218de4c94`; full-suite project was `leggtix-test-6d4d87e37ef64065b0d08c710fe1066e`. PHPUnit never ran against the normal development database.

PHP lint passed for the new renderer, bootstrap wiring, OpenAPI specification, error test and live runner. PowerShell parsing passed for the live wrapper. `docker compose config --quiet`, `git diff --check` and all **113 local file links** across the seven affected Markdown guides passed. Final inspection found no remaining temporary test containers or networks.

The reusable live runner is [verify-event-management.ps1](../scripts/verify-event-management.ps1), backed by [verify-event-management.php](../scripts/support/verify-event-management.php). It passed **56 checks**, including **54 real HTTP requests**, in approximately 7.5 seconds. It guards local MySQL, Redis and credential-free Mailpit destinations, tags its own fixtures, records token-free evidence incrementally and cancels/revokes only its own review resources in cleanup. The manual plan documents its repeat command and expected outcomes.

Local execution artifacts are intentionally ignored under `storage/app/private/`:

- `issue8-api-2b908fee44fb55067dfc.json`: API checks, review IDs, Mailpit message IDs and zero cleanup errors. Booking confirmation IDs: reservation 21 → `6JqM0hGQZ4pho0V2D00MiQ`; reservation 22 → `1YJOPwxIlVugeXUmA3sc1o`.
- `event-management-review-20261105/browser-evidence.json`: live browser checks, passed status and logged-out session cleanup.
- `event-management-review-20261105/full-suite.log`: complete regression output and successful cleanup.
- `event-management-review-20261105/local-service.log`: bounded logs after service recovery, starting at 11:18:50 UTC.
- `event-management-review-20261105/owner-list-desktop-light.png`, `owner-list-mobile-dark.png`, `native-picker-start.png` and `cancelled-detail-desktop.png`: live screenshots.

These are local artifacts, not files bundled in the PR. They contain no persisted authentication tokens or email codes. Review events/attempts and Mailpit messages remain as intentional acceptance history.

## Findings and role outcomes

**Developer — fixed:** default debug rendering leaked framework details and an empty message for expected API errors. Central JSON rendering now produces safe messages while preserving reporting and existing contracts. Added **33 error-response cases** covering debug/environment combinations, hidden/missing equivalence, logging, JSON selection, headers, validation and explicit responses; corrected OpenAPI examples/schema. All are included in the passing full suite.

**Tester — passed:** independently added **three real-route boundary cases**, covering local debug, production debug and production normal behavior, then ran the live HTTP/Mailpit acceptance above. No additional application defect was found. Its first isolated attempt failed during image construction before PHPUnit; no test result is claimed from that attempt. The coordinator's passing focused/full runs include the tester's tests.

**Security reviewer — no new source blocker:** inspected safe error rendering, exception normalization, auth/ownership privacy, header/cache/validation/business-code preservation, logging, the environment matrix and live-runner destination/cleanup guards. This was static/read-only; the reviewer did not independently execute PHPUnit, browser acceptance or a dependency audit. Existing dependency findings below remain unresolved.

**Infrastructure interruption — recovered:** two initially overlapping test-image builds were interrupted by Docker BuildKit `RPC Unavailable … EOF`, before PHPUnit. At 11:16:52 UTC several normal local services also stopped with exit 255; inspected OOM flags were false and the cause was not established. The normal stack was restored with its data intact, the worker restarted, and browser/focused/full checks subsequently passed. Both abandoned temporary stacks were cleaned. No application fix or Docker configuration change is attributed to this interruption.

## Remaining limits and follow-up

- **Unresolved existing dependency findings:** the [29 October audit comparison](event-date-time-picker.md) recorded nine identical baseline/current alerts: four low, four moderate and one high, including `node-forge` advisory GHSA-86w9-cpqp-85rv. Dependencies were unchanged and no fresh audit/remediation was performed here. Follow the documented targeted dependency remediation and re-audit before treating the dependency review as clean.
- Live event-list pagination was not applicable to the 13-row organiser list. Real attendee pagination passed; event-list pagination also has existing automated coverage. No extra fake events were created solely to fill a page.
- The browser run used Chromium and a narrow viewport, not physical mobile hardware, Firefox/Safari or assistive technology. No new frontend typecheck/build or mocked browser regression suite was run because frontend source/dependencies were unchanged; the live browser path was rerun.
- No live greater-than-50 waiter continuation experiment was synthesized through SQL. The complete isolated suite covers bounded FIFO/replay/resume/stale notification behavior; this does not separately prove large live Redis continuation delivery. After-commit enqueue remains best effort; durable outbox/recovery is still #31.
- No hosted CI run, deployment, load benchmark, GitHub write, push or PR merge was performed. Changes remain local for review. The pre-existing `.gitignore` edit was preserved separately.
