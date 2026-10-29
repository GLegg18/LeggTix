# Issue #8 implementation and verification

Work completed locally on 27 October 2026 for [event-owner authorisation and management #8](https://github.com/GLegg18/LeggTix/issues/8), using the developer, independent tester and read-only security reviewer roles. A separate explorer reviewed the project Markdown guidance, including the database contract and historical milestone notes. The coordinator implemented the Nuxt workspace and owns this consolidated report.

## Result and scope

Owners can create, inspect, edit, publish and cancel their events; admins can manage all events. Current organiser privileges are required as well as ownership. Private reservation/waitlist history is cursor paginated and returns deliberate attempt fields plus account ID/name. Management uses the existing bearer authentication or the explicit first-party SPA session routes, retaining email-factor login, origin checks and CSRF protection. No new authentication system, schema migration or dependency was added.

All event updates take the same event-first inventory lock as booking. Lifecycle rules, historical schedule/type restrictions, active type checks and capacity bounds are applied under that lock. Cancellation atomically closes active reservations/waiters and zeroes occupancy; completion retains confirmed attendance and closes remaining waiters. Neither operation promotes. Closed events permit listing metadata corrections without reopening.

Capacity increases fill existing FIFO waiters through a reusable, 50-entry bounded action. A repeatable `inventory` job continues remaining work and `waitlist:promote EVENT_ID` resumes after interruption/lost enqueue. The Compose worker now consumes `inventory,emails`; it was recreated locally to apply the command. Existing direct bookings continue to reject queue bypass. Exact newly created reservation IDs feed the existing after-commit confirmation email.

The user approved an organiser workspace during this task and requested a roadmap overlap check. The live GitHub tickets were read: #20 is the completed shell, #21 retains customer discovery/booking/personal reservation UI, and #22 remains optional internal Filament administration. The Nuxt workspace supplies owner event-management screens and shares Laravel policies/actions with any future Filament interface. No later ticket was closed or rewritten. Public waitlist join/leave, holder cancellation/history, full cancellation/direct-booking promotion integration and dedicated promotion notifications remain #10/#11/#13/#14/#15 work.

Important files:

- `app/Policies/EventPolicy.php`, management Form Requests and explicit resources: role/ownership, input and private output boundaries.
- `app/Actions/ManageEventAction.php`, `PromoteWaitlistAction.php`, `app/Jobs/ContinueWaitlistPromotion.php`, `routes/console.php`: lifecycle, atomic inventory, bounded continuation and recovery.
- `app/Http/Controllers/ManagedEventController.php`, `routes/api.php`, `app/Documentation/ApiSpecification.php`: bearer/browser routes and corrected OpenAPI contracts.
- `frontend/app/pages/manage/events/`, management components/composable/types: list, draft form, detail/lifecycle and private attempt views. Account/header links and direct-visit HTTP handling include these routes.
- Three `EventManagement*Test.php` classes, the independent worker and `frontend/tests/management.spec.ts`: acceptance, workflow, races and UI interaction coverage.

The [management guide](event-management.md) records fields/routes/outcomes, the [workspace note](event-management-ui.md) records screens and roadmap boundaries, and the [manual plan](event-management-manual-test-plan.md) gives exact requests and expected results. Root README, documentation index and relevant event/reservation/design/email/frontend/testing guides were updated to distinguish current functionality from downstream work.

## Checks actually executed

| Check | Actual result |
| --- | --- |
| Independent tester acceptance/concurrency suite | **23 tests, 233 assertions passed**, 3.26 seconds, warnings treated as failures, exit 0. Includes all four new independent-process MySQL races. Both tester disposable stacks cleaned successfully. |
| Complete Laravel/MySQL suite | **509 tests, 4,557 assertions passed**, 87.77 seconds, `--display-warnings --fail-on-warning`, exit 0. Includes management workflow/OpenAPI cases and existing auth/booking/email regressions. |
| Full-suite isolation/cleanup | Project `leggtix-test-cc33c81620324f80a645398eb5f17753` removed its disposable MySQL, network and generated test image. No PHPUnit invocation used the normal development database. |
| Frontend type check and production build | `npm run typecheck` and `npm run build` passed in the configured Node 24 Compose runtime. Development Nuxt was stopped while generating `.nuxt`/production output, then restored. |
| Final desktop/mobile browser regression | **28 tests passed**, 15.6 seconds, exit 0, using `mcr.microsoft.com/playwright:v1.64.1-noble`, `CI=true` and production preview. API responses are mocked. Includes existing auth cases and new management pagination/create/publish/cancel/history/validation/role/privacy/completion/session-expiry cases. |
| Real Laravel/Mailpit browser walkthrough | Passed organiser password+email-code login, private workspace access, draft creation, metadata edit/reload, publication, public 404→200 visibility, empty private lists, named cancellation confirmation/dismissal, cancellation/reload, public 404, logout and guest recovery. No page JavaScript errors. |
| Responsive visual inspection | Live event detail inspected in desktop/light and 393-pixel mobile/dark views; mobile document had no horizontal overflow. Full-page previews were generated and inspected locally; the later Playwright run cleared its ephemeral output directory. No formal accessibility conformance is claimed. |
| Live read-only API boundaries | Tester confirmed guest management list 401 and untrusted-origin SPA management 403; both `Cache-Control: no-store, private`. |
| Compose and local worker | `docker compose config --quiet` passed. Worker recreated for `inventory,emails`; `queue:failed` reported no failed jobs. Final app/frontend/MySQL/Redis/Mailpit healthy; worker running. |
| Static review/formatting | Security reviewer inspected source, framework cursor behavior, auth/CSRF/privacy, concurrency and after-commit paths. Tester linted its three PHP test/worker files. `git diff --check` passed. |
| Documentation links | 166 local Markdown links resolved across 12 current guides. |

The full-suite command was:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
```

The coordinator's first frontend type check caught a Vue optional-prop narrowing error inside an option-list callback; a computed predicate corrected it before the passing type check/build. The tester's first focused run had seven fixture-comparison failures caused by comparing factory attributes to fresh MySQL rows with generated keys/attribute ordering. The tester corrected the snapshots to capture fresh database-loaded state; strict preservation assertions stayed intact. All four races already passed on that first focused run. These were test/type issues, not evidence of an inventory or API exploit.

## Findings and role outcomes

**Developer — completed:** implemented policies, strict requests/resources, management workflows, bounded promotion, browser/bearer routes, throttle/cache behavior, OpenAPI corrections, seven workflow tests and domain documentation. Its tests include >50 waiters/resume/repeated delivery, deterministic ties, bounded inconsistent-waiter examination, exact-ID after-commit notification, save-veto rollback, outer rollback discarding callbacks and start cutoff between allocations.

**Tester — passed:** independently added role/ownership/demotion, strict-body/writable-field, storage/date/calendar, retired type/history, private-field/pagination and SPA session/origin/CSRF acceptance. Four real MySQL races cover capacity reduction after booking commits, schedule edit after history appears, event cancellation while booking waits, and FIFO promotion ahead of a newcomer. Committed counters equal confirmed rows and never exceed capacity. No outstanding application defect was reported by the tester; its seven snapshot fixture defects were fixed as described above.

**Security reviewer — three medium findings fixed:**

| Symptom/risk | Cause and correction | Verification |
| --- | --- | --- |
| A demoted owner could enumerate retained private events through the interim management list. | The initial list/type Form Request had authentication without the current-role policy gate. Request authorisation now enforces `viewAny`, or the event-specific private-view policy. | Independent demoted-token/list/type/ownership tests passed; source rereview confirmed the correction. |
| Validated multibyte descriptions could cause a database error. | Character-only validation could exceed MySQL TEXT's 65,535-byte storage. Added UTF-8 byte-budget validation. | Independent four-byte text boundary regression passed. |
| Offset dates could normalize outside MySQL's supported UTC range and cause a database error. | Input calendar validation did not bound the normalized UTC year. `EventInstant` now rejects normalized years outside 1000–9999. | Independent lower/upper UTC overflow regressions passed. |

**Contract alignment — resolved:** terminal metadata corrections follow the existing design; capacity/schedule/type/lifecycle changes stay blocked. **Minor UI review note — fixed:** parent lifecycle actions now disable the detail form, and a pending form save disables lifecycle buttons, avoiding overlapping UI submissions. The final build/browser suite includes this source.

The security reviewer reported no unresolved source security/data-integrity blocker. Its review was static/read-only, and it did not independently run PHPUnit, browser tests or audits. Executable results above belong to the tester/coordinator; no role's verification is implied beyond those checks.

## Manual plan and retained state

Use the [detailed manual plan](event-management-manual-test-plan.md) for PowerShell and browser steps. Expected outcomes are: organiser owns its new draft; regular/foreign-owner requests fail; admin can inspect/manage without ownership reassignment; publication requires a future start/active type; schedule/type changes fail after any history; capacity cannot drop below occupancy; cancellation and replay retain history without extra changes; guests/session expiry reveal no private records.

The coordinator's real walkthrough created only dedicated review event **53**, named `Issue 8 live review 2026-10-27T11-09-15-612Z`, owned by the existing demo organiser. It is retained **cancelled**, with zero confirmed occupancy. Existing seeded event rows were not edited or cancelled. The browser completed logout. The normal database/key/volumes were preserved. The local worker configuration was applied and the development frontend restored.

The longer manual plan's separate authenticated API booking/cancellation sequence and live >50-waiter Redis continuation delivery were not performed. Their domain behavior is covered by isolated acceptance/workflow/race tests; that does not establish live Redis continuation transport. The live login did exercise the real Redis worker and Mailpit email delivery.

## Remaining limits

- After-commit enqueue is best effort: crashes can lose continuation/notification delivery, and SMTP/queue retries can duplicate emails. `waitlist:promote` resumes allocation; durable outbox/recovery remains #31. Allocation safety does not rely on email.
- Dedicated promotion messages, event-cancellation notices, customer cancellation/join/leave integration and public customer UI remain downstream; these tickets are not claimed complete.
- No hosted CI run, dependency audit/image scan, throughput/load benchmark, assistive-technology audit or cross-browser matrix was run. Dependency files were unchanged. The earlier frontend transitive-advisory follow-up in [issue #20](issue-20-validation.md#dependency-advisories-and-security-review-outcome) remains recorded; this task did not re-audit or resolve it.
- Changes remain local and uncommitted on `LT-8-Event-Owner-Auth-And-Management-Endpoints`. The pre-existing `.gitignore` edit is preserved. No GitHub write, PR creation, push, deployment or issue closure was performed.
