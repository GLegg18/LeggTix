# Issue #9 verification report

Work performed on 8 October 2026 for [concurrency-safe event reservations #9](https://github.com/GLegg18/LeggTix/issues/9), using the developer, tester and security reviewer roles. A read-only documentation explorer inspected all 17 existing repository Markdown files, including the local project spec and rationale, before implementation. The [booking guide](reservations.md) documents the final contract; the [manual plan](reservation-manual-test-plan.md) gives repeatable checks and expected outcomes.

The sections below retain the earlier milestone's execution history. The latest review fixes, verification and remaining acceptance work are recorded in the repository-review follow-up at the end of this report.

## Changes and scope

The existing #5 migrations already satisfy the agreed reservation schema: restricted foreign keys, generated active-only uniqueness, history/timestamp checks, lookup indexes and bounded event counter. They are reused without adding or rewriting a migration.

The developer added `Reservation`, typed status/rejection enums, event/holder relationships, an inventory-aware fixture factory, and `ReserveEventAction`. Booking locks the current event row first, uses current generated-key lookups, protects queue priority, and commits a guarded database-UTC occupancy increment with a fresh confirmed attempt. Three bounded whole-transaction attempts handle recognized contention. Insert errors and model-event vetoes roll back occupancy.

`POST /api/events/{event}/reservations` uses Sanctum, a creation policy, strict request parsing, an explicit resource, and user/IP throttles. The holder comes from authentication; supplied fields cannot override identity or lifecycle. Responses distinguish creation, business conflict, invalid request and exhausted contention. All roles share booking capability, and published events remain eligible after type retirement.

The endpoint was the coordinator's recommended assumption after an optional scope question received no answer before implementation. It follows the route suggested in the project spec and makes booking reviewable through the API. Cancellation, reservation history, waitlist join/leave/promotion and event management retain their separate ticket scope. Existing waiters conservatively block direct allocation until #14 integrates bounded promotion.

Important implementation files are `app/Actions/ReserveEventAction.php`, `app/Models/Reservation.php`, `database/factories/ReservationFactory.php`, `app/Http/Requests/ReserveEventRequest.php`, `app/Http/Controllers/ReservationController.php`, `app/Http/Resources/ReservationResource.php`, `app/Policies/ReservationPolicy.php`, `app/Providers/AppServiceProvider.php` and `routes/api.php`. The tester added four reservation feature classes and `tests/Support/reservation-worker.php`.

Changes are local and uncommitted on the existing `LT-9-Event-Reservations` branch. The pre-existing `.gitignore` edit is preserved. No issue/PR mutation, push or deployment was performed.

## Executed checks

| Check | Result |
| --- | --- |
| Production PHP syntax | All 28 application/factory/route PHP files passed in the configured PHP 8.4 Docker app runtime. The request file also passed after each parsing fix. |
| Dependency security audit | `composer audit --locked --no-interaction` passed, exit `0`, with no vulnerability advisories. |
| Patch formatting | `git diff --check` passed during development and review. |
| First focused MySQL run | 68 passed; one tester assertion incorrectly compared an unrefreshed factory model with a fresh row containing the generated key. The tester corrected the baseline. All seven independent-process race cases and then-existing parsing/veto regressions passed in this run. |
| Focused MySQL rerun | **69 tests, 442 assertions passed**, with warnings treated as failures and successful disposable-stack cleanup. Two additional media/bodyless request cases were added afterwards. |
| First complete MySQL run | 236 passed; one new bodyless-request harness case failed because Symfony's test request builder automatically supplied a form content type. The tester corrected the fixture to exercise the actual HTTP kernel with the header absent; application behavior and assertions were unchanged. |
| Final complete MySQL suite | **237 tests, 1,648 assertions passed**, 17.54 seconds, exit `0`, with `--display-warnings --fail-on-warning`. Includes all **71 reservation cases** and all **seven independent-process races**. No separate rerun of the 71-case filter was needed after this complete pass. |
| Docker test isolation and cleanup | The runner removed project `leggtix-test-1db50ed643df45faadd233b7b9d717fb`, including its MySQL container, network and generated local test image. The coordinator confirmed the normal app/MySQL/Redis containers retained their IDs and running state. |
| Documentation checks | 74 local links resolved and 24 PowerShell blocks parsed across eight new/edited Markdown files. |

The two first-run failures above were test defects, not implementation defects. Final complete-suite verification and cleanup passed. Docker Compose reported v5.5.1; the configured runtimes are PHP 8.4 and MySQL 8.4. Exact patch versions inside the disposable test image were not separately captured.

## Findings and role outcomes

**Developer:** Implemented the model, transaction and endpoint, reused the existing constraints, and fixed the persistence and parser findings. PHP syntax passed. The developer did not duplicate the tester's PHPUnit runs.

**Tester:** Added model/relationship/history/UTC checks, business and API acceptance, rollback/veto regressions, token boundaries, field overrides, throttling/busy responses, and independent-process MySQL races. Corrected the unrefreshed-model and implicit-content-type test fixtures described above. Final complete-suite acceptance passed with no outstanding implementation defect; the supplied live walkthrough was not executed.

**Security reviewer — medium, fixed:** Eloquent `save()` can return `false` when a saving/creating listener vetoes insertion. Ignoring that result could commit an occupancy increment without a reservation; factory storage had the same failure mode. The action now throws for unsuccessful save, and the factory verifies persistence inside its transaction. Regression cases assert zero reservation rows and no counter drift after a veto.

**Tester/security reviewer — low, fixed:** Laravel's parsed input can treat malformed JSON as empty, allowing the original field-only validation to invoke booking. The Form Request now validates raw JSON, rejects malformed syntax with `400`, rejects non-object JSON with `422`, and rejects supplied fields before allocation. Tests exercise rejection without inventory changes.

**Security reviewer — low, fixed:** PHP can hide raw multipart POST input when `enable_post_data_reading=1`, which the reviewer confirmed in the app container. An empty-raw-body shortcut could therefore bypass the first content-type check. The final request rejects any declared unsupported media type with `415` before that shortcut. A request regression represents the empty-raw multipart boundary; a live PHP multipart upload was not performed. [PHP documents the raw-input limitation](https://www.php.net/manual/en/wrappers.php.php).

Security source/framework review covered server-owned identity, policy/authentication, guarded fields and selected output, UTC cutoff, event-first/current reads, guarded counter/insert rollback, retry and busy classification, queue priority, rate limits and the fixture path. The review and parsing addendum report no unresolved findings. The reviewer ran the dependency audit and read-only checks, but did not execute PHPUnit or a load test.

## Manual plan and verification limits

Follow the [reservation manual plan](reservation-manual-test-plan.md). Expected outcomes include `201` with the authenticated holder, duplicate/full/ineligible conflicts without extra rows, guest rejection, invalid-payload rejection before allocation, and equality between the event counter and confirmed rows. The plan distinguishes automated disposable verification from any live requests that create local demo data.

The seven deterministic MySQL race cases stage separate PHP processes and database sessions around a held event lock. They cover last-place commit and rollback, same-actor overlap, stale repeatable-read full/duplicate/rollback cases, and a start cutoff while waiting. The coordinator inspected the barrier/worker implementation; the tester executes it. Committed fixtures are visible to both sessions, the test observes the contender's active event-lock statement, and final assertions compare counter, confirmed rows, holder and capacity. This is correctness evidence for exercised bookings, not a throughput benchmark or general proof for future writers.

At the original milestone, cancellation/promotion/join/capacity-edit races, larger contender bursts, measured latency/query plans and the complete multi-workflow suite remained #12 and dependent tickets. No browser flow, live Redis throttle test or load benchmark had been run. HTTP busy mapping used an injected concurrency exception; real deadlock/retry exhaustion had not been deliberately forced. The SQL time guard was present; the exercised time race checked expiry while waiting for the event lock. The follow-up below records additional verification and supersedes these live/timeout limitations.

**Remaining integrity obligation:** Every future inventory writer must follow the same event-first protocol and update the counter with attempt state. Direct SQL/trusted assignment can bypass it; database checks cannot enforce cross-table equality or exclusivity. The event-locked reconciliation/diagnostic command is deferred to the broader #12 integrity follow-up. Check existing data before adopting it; do not silently repair from stale snapshots.

**Staged waitlist limitation:** Any active backlog blocks newcomers and can leave spare capacity unused until #14 implements bounded promotion and recovery. Notification delivery reliability across commit/enqueue remains deferred; this change sends no notification and makes no exactly-once promise.

## API explorer and live verification follow-up — 8 October 2026

The user requested an interactive API reference and asked the agent to complete remaining practical checks before handing testing back. Docker Desktop access worked through the tool's required escalation; no VS Code takeover was needed. The earlier restricted-shell error was a Docker named-pipe permission boundary, not an absent Docker installation.

The local [Swagger API explorer](http://localhost:8000/docs/api) and [OpenAPI JSON](http://localhost:8000/docs/api.json) replace Markdown as the endpoint reference. Scramble is pinned as a development dependency at `0.13.47`, compatible with Laravel 13. Three development packages were added without upgrading existing packages. The generator supplies current routes, request/resource schemas and responses; `App\Documentation\ApiSpecification` adds precise corrections for byte-based password rules, strict booking input, status/error enums and response headers. The guarded HTTP JSON is the canonical corrected document; Scramble's standalone export command produces the generator's baseline.

Swagger UI `5.33.1` assets and its Apache 2.0 license are stored locally, with archive integrity and file hashes in `resources/vendor/swagger-ui/README.md`. There is no CDN, external proxy or online validator. Browser authorization stays in memory, requests are restricted to this origin, and documentation routes/assets require local/testing on every request. The guard works independently of route registration and package availability; ordinary production installation need not include the documentation dependency.

Important integration files are `app/Documentation/ApiSpecification.php`, `app/Http/Controllers/ApiDocumentationController.php`, `app/Http/Middleware/EnsureLocalApiDocumentation.php`, `app/Providers/ApiDocumentationServiceProvider.php`, `config/scramble.php`, `bootstrap/providers.php`, `routes/web.php`, the documentation Blade/JS/CSS resources and the Composer lock. `tests/Feature/ApiDocumentationTest.php` checks the contract and exposure boundary.

The tester added a repeatable live runner, `scripts/verify-reservations.ps1`, with a local-only PHP fixture helper. It creates tagged accounts/events, keeps bearer tokens out of logs, checks stored inventory, and cleans up only its verified fixture IDs under event-first locks. Token-free recovery metadata supports cleanup if the run is interrupted or cleanup fails. It does not reset the development database or flush shared Redis rate limits.

| Additional check | Result |
| --- | --- |
| Live HTTP reservation acceptance | Passed, exit `0`, using PHP **8.4.26**, MySQL **8.4.11** and Redis. Guest/invalid/expired token `401`; holder/query overrides `422`; malformed JSON `400`; array JSON `422`; plain text and real fieldless multipart `415`; normal and truly bodyless booking `201`; replay/full/ineligible/waiter/FIFO conflicts; missing/malformed IDs `404`. |
| Live resource and database checks | Exact seven response fields and authenticated holder passed. Two fixture events each had one matching confirmed row/count; the other three had zero; the waiting record was preserved. Tag/ID-verified cleanup reported zero remaining fixture events/users/type/reservations/waitlist/tokens. |
| Live Redis rate limiting | A dedicated actor received 30 permitted requests, then `429` on the 31st with `Retry-After`. Shared IP keys were not cleared. |
| Real MySQL retry exhaustion | **Eight concurrency cases, 97 assertions passed**, 10.05 seconds, in an isolated Docker project. The additional case held the event lock while an independent HTTP-kernel process used a one-second session lock timeout. Three real failed transaction attempts returned `503 reservation_busy`, `Retry-After: 1`, with zero allocation; booking succeeded after lock release. No timeout setting was changed on the normal stack. Project `leggtix-test-90655bf654674434b1dc08070003bb54` was removed. |
| Documentation acceptance after example correction | **10 tests, 143 assertions passed**, 1.05 seconds, exit `0`, warnings treated as failures. Checks cover all six operation contracts, selected response fields, auth, strict input, response/error headers, local asset hashes/CSP, local/testing versus production/staging guards with compiled routes, generation without database writes, and a valid newly confirmed reservation example. |
| Combined MySQL suite before example correction | **248 tests, 1,789 assertions passed**, 21.78 seconds, exit `0`, warnings treated as failures. Includes all **72 reservation cases**, **eight independent-process concurrency cases**, and **10 documentation cases**. The final result after the example-only correction is recorded below. |
| Final combined MySQL suite | **248 tests, 1,804 assertions passed**, 22.32 seconds, exit `0`, with `--display-warnings --fail-on-warning`, after the confirmed-example correction. The 15 additional assertions check example fields, positive IDs, confirmed status, null cancellation and valid equal creation/update timestamps. Project `leggtix-test-d81d01294928416687c0a4f3b9447ad3` was fully removed, including its MySQL container, network and generated test image. |
| Exploratory browser verification | The local UI rendered all six operations with no console warnings/errors. Swagger sent `/api/health` successfully (`200`), rejected `/api/user` without credentials and with a deliberately invalid token (`401`), added the `Bearer` header correctly, and cleared authorization on refresh. The booking view showed the optional `{}` request, corrected confirmed example, conflicts and retry guidance. A screenshot was saved outside the repository. |
| Installation without development dependencies | **32 HTTP route checks passed** in an isolated no-network container without host mounts. Locked offline Composer installation removed 32 development packages; package discovery booted and routes were cached with `APP_ENV=local`. In separate production/staging/local/testing processes, Scramble was absent, `/api/health` and `/up` returned `200`, and the UI, schema and all four JS/CSS assets returned `404`. The temporary container was removed; host vendor and `.env` were preserved. The offline filter-list warning was not an audit result. |
| Dependency audit after documentation installation | Passed, exit `0`, no vulnerability advisories. |
| Swagger asset review | Official npm archive integrity and recorded hashes matched. npm's advisory query for `swagger-ui-dist 5.33.1` returned no top-level advisories. The bundled JavaScript's transitive dependencies were not exhaustively audited. Node checks accepted three allowed request URLs and rejected five external/out-of-scope URLs; token persistence was disabled. |
| Live runner with recovery metadata enabled | The final repeat run passed all 50 booking HTTP requests, health, Redis limits, database invariants and fixture cleanup, exit `0`. Token-free recovery identity files were removed after successful cleanup; tokens were not reported. |
| Documentation and workspace checks | 78 local links resolved and 18 PowerShell blocks parsed across all 10 new/edited Markdown files. Patch formatting passed. Normal app/MySQL/Redis containers retained IDs `975dc7a7e67d`, `fbc71f1aaafa`, `d3c7f2cfae96` and their running state. |

The first live runner reached all HTTP assertions but failed its own JSON array handling during invariant inspection. The tester corrected the script and reran the complete live acceptance successfully; this was a runner defect, not an application defect.

**Coordinator/browser — low, fixed:** Swagger's generated example chose a timestamp for nullable `cancelled_at` while showing `status: confirmed`. This contradicted the newly created reservation and could mislead readers. The developer supplied an explicit seven-field `201` example with positive IDs, confirmed status, `cancelled_at: null` and valid UTC timestamps. The tester added semantic assertions to the existing documentation case; the coordinator reloaded the UI and verified the corrected rendered example. No schema or business behavior changed.

**Developer follow-up:** Implemented the guarded UI/schema and optional generator integration, recorded asset provenance, and fixed the browser example. PHP syntax and patch formatting passed. An exploratory baseline JSON artifact was removed; no additional application defect was reported.

**Tester follow-up:** Added the ten documentation cases and real timeout case, completed live HTTP/Redis/database acceptance and recovery-enabled cleanup, and corrected the runner-only array defect. No unresolved application finding remains. The detailed manual plan has expected responses and repeat/recovery commands; its individual copy/paste walkthrough was not rerun because the automated live runner exercised the relevant acceptance steps.

**Security reviewer follow-up:** No actionable findings in the final documentation integration. Source/package/asset/request-boundary review, online dependency checks and the isolated no-dev smoke passed. The response example correction is documentation-only and does not change the reviewed guards or request behavior.

Remaining broader work includes a deliberately constructed real deadlock cycle, load/latency and query-plan measurement, multiple servers, the diagnostic/reconciliation command, and races for cancellation, joining, promotion and capacity editing when those workflows exist. A full authenticated login/booking walkthrough in the browser and automated browser regression suite were not run; real authenticated HTTP booking and browser request/authorization controls were verified separately. The useful human check is usability: login, paste the raw token into Authorize, try `{}` against a published future event, expect `201`, then repeat and expect `409 already_reserved`. Try it out creates real local records. No current failed check requires the user to rerun Docker commands.

## Commit preparation and fresh setup — 8 October 2026

The user requested staging, ignore-rule review and a fresh-setup check. The coordinator staged the 53 intended implementation, test, documentation and repository-hygiene files. No commit, push or GitHub mutation was performed. Existing personal exclusions in `.gitignore` were preserved. Environment variants, runtime files under `storage/app` and `.agents` are now ignored; `.env.example` and directory placeholders remain tracked. The five pinned Swagger distribution files, licenses/notices and provenance belong in the commit so the explorer needs no npm installation.

**Developer audit — fixed setup gaps:** Starting the app before its initial Composer installation completes can make immediate Artisan commands fail to find `vendor/autoload.php`. Compose also captures `APP_KEY` when creating the container; key generation afterwards edits `.env` while the container can retain the initial blank environment value. A process-only override confirmed the shadowing behavior without changing the normal configuration. README and both model/reservation setup plans now install dependencies and generate a new environment's key in one-off containers before starting services, then wait for the server before migrating. Existing environments and keys are preserved. README includes a read-only event-ID lookup for Swagger, current Docker host-requirements guidance and the vendored-assets explanation. The authentication walkthrough now links the implemented booking plan.

**Coordinator — fixed Windows asset integrity:** The repository uses `core.autocrlf=true`. Its normal text conversion would alter the pinned upstream asset bytes on a Windows checkout, breaking recorded hashes. `.gitattributes` disables text conversion and whitespace checking only for the five unmodified distribution files, retaining their upstream notice whitespace. A temporary checkout from the staged index with `core.autocrlf=true` preserved all five recorded SHA-256 hashes and was removed afterwards. The staged patch check passed.

| Check | Result |
| --- | --- |
| Fresh setup from commit-candidate source | Passed, exit `0`, using a new 124-file source snapshot without an existing `.env`, installed dependencies, caches, Git metadata or private runtime data. The isolated Compose project had no published ports, only its temporary source bind and its own MySQL volume. README's build, fresh installation of all 109 locked packages, new key, startup/readiness, six migrations and demo seeding succeeded. Process/config key-presence checks and development-generator availability were true; no key/token values were printed. |
| Fresh seeded fixtures and Swagger event lookup | Exactly two users, three types and six events; zero reservation/waitlist/token rows before requests. The README Tinker command selected the two published future events with zero occupancy. |
| Fresh real HTTP/API flow | `/up`, `/api/health`, `/docs/api` and `/docs/api.json` each returned `200`; schema contained the six implemented operations. Cold JSON generation took 11.70 seconds. Demo login returned `200` and booking returned `201` for the correct account/event, confirmed status and null cancellation. Stored counter and confirmed rows both equalled one, with no waiting row. |
| Scratch cleanup and normal-stack preservation | Project `leggtix-scratch-5ba9a0e7cdb64b83b4feb5aa4c884589` containers, network, private volume and image were removed, as was its verified temporary directory. Normal app/MySQL/Redis container IDs stayed unchanged. |
| Ignore rules | Eight required source/template paths remained eligible for tracking; 12 representative environment, installed-dependency, cache, upload, recovery and private-agent paths were ignored. |
| Documentation | All 85 local links resolved and 63 PowerShell examples parsed across the 12 changed Markdown files, including indented code fences. |
| Staged review | Security reviewer confirmed all 53 paths were in scope, no environment variants, installed dependencies, agent folders or runtime files were indexed, and all seven directory placeholders remained tracked. The five upstream hashes and Git attributes matched; staged patch formatting passed. No actionable finding. A coordinator scan found no common real-credential markers; this was not an exhaustive secret audit. |

**Tester — temporary probe corrected:** The first scratch HTTP harness allowed only three seconds for cold schema generation and did not reliably return a failing exit status through Laravel's installed CLI exception handler. Its initial apparent success was disregarded. The tester added an explicit nonzero failure handler and a 45-second request timeout to the temporary probe, repeated the entire fresh setup successfully, and cleaned both scratch runs. No repository application defect was found.

The final PHPUnit result remains 248 tests / 1,804 assertions from the prior milestone; these ignore, attribute and documentation changes did not change business code, and the suite was not rerun. Developer audit, tester fresh-install acceptance and security staged review all completed. The manual browser usability pass and previously recorded broader concurrency/load/future-workflow checks remain outstanding.

## Repository-review follow-up — 8 October 2026

This section records the checks and pending work at that point. The 9 October CI follow-up below supersedes its Linux/Windows workflow and required-status instructions.

The user requested the five technical review findings and documentation drift be addressed through the developer, tester and security reviewer flow. Event discovery, management, cancellation and waitlist workflows retain their existing tickets and were excluded from this follow-up. GitHub issue #9 was read through the connector: it remains open, with its six acceptance boxes checked. No issue, PR, branch setting or remote content was changed; manual acceptance remains with the user.

### Fixes and decisions

| Finding | Cause and fix | Verification/status |
| --- | --- | --- |
| Equivalent email spellings could bypass the five-attempt login budget | MySQL equates accented variants while the previous limiter hashed literal normalized input. The limiter now validates and bounds email before resolving the authoritative indexed account ID, then hashes a namespaced account/IP key. Unknown identities retain a separate normalized email/IP key. | **Fixed.** Real MySQL regressions exhaust mixed spelling/case/trim attempts, check rejection even with a correct password, recovery after 61 seconds, another account's independent budget and invalid input avoiding account lookups. Live Redis checks passed too. |
| Returned booking had stale event availability/timestamps | The guarded query-builder increment bypassed the event object cached by `associate()`. The action clears that relation after the successful insert; subsequent access reads persisted state. | **Fixed.** Regression compares returned event occupancy and timestamp with a fresh database row. No extra read is added while the action holds the event lock. |
| Start-cutoff race depended on worker startup completing within three seconds | The test assigned a near-term deadline before launching the worker. It now establishes the cutoff after the worker is observed waiting on the held event lock. | **Fixed.** All eight independent-process races pass. The case verifies end-to-end cutoff rejection during a real wait; source review verifies PHP time sampling placement. The test alone cannot distinguish that placement because the guarded SQL update also checks current database time. |
| No tracked CI workflow | MySQL and runner acceptance were only manually invoked. The Tests workflow now runs the isolated suite on Linux/PowerShell 7 and the mock wrapper harness on Windows PowerShell and PowerShell 7. | **Workflow added and linted.** First hosted execution and configuring required merge statuses remain pending after publication. Checkout v7.0.1 is pinned to its verified release commit, with read-only contents and credential persistence disabled. |
| PHP Redis extension was unversioned | Composer does not lock PHP extensions. Docker now installs `redis-6.3.0`. | **Fixed.** The disposable suite successfully rebuilt the runtime with that exact install command and passed. The normal stack was not recreated. |
| Current guides described implemented features as absent | Authentication notes denied the demo organiser and treated booking as future work; the general manual plan and design walkthrough also contained outdated wording. | **Fixed.** Guides now describe demo roles, implemented booking and account-based throttling, while preserving future-ticket boundaries. Swagger's login description matches the limiter. Local links and rendered PowerShell examples were checked. |

Existing email matching, database schema, public response fields, five/account/IP and thirty/IP budgets are preserved. This fix does not decide whether distinct accented mailboxes should become distinct application accounts. Account-wide controls across multiple IPs remain a documented public-launch follow-up; choosing that policy is separate from repairing equivalent-spelling budget bypass.

### Agent outcomes

**Developer:** Implemented the limiter, cached relation and cutoff-harness fixes in three owned files. No migrations or business workflow expansion. Patch formatting passed; coordinator handled PHP/Docker execution.

**Tester:** Added seven regression cases across the authentication and booking classes; extended the live runner with canonical/accented/case/trim Redis checks and independent-account verification. Both Windows shell wrapper runs passed. Supplied the [morning acceptance checklist](issue-9-review-manual-checks.md), with exact requests, expected outcomes and captured-fixture cleanup. No additional application defect found. The tester did not run the coordinator's database or live HTTP checks.

**Security reviewer:** Reviewed application/tests/CI and the final live-script additions. The original login-budget and cached relation findings are fixed; no new actionable authorization, concurrency, token-disclosure or cleanup defect found. A remaining stale sentence in the general manual plan was reported and corrected. Hosted enforcement, multiple-IP abuse controls and browser/load verification remain explicitly scoped below. The reviewer ran no database mutations.

**Coordinator — CI defect found and fixed:** Actionlint rejected using a matrix expression in `steps.shell`. Two conditional steps now select literal `powershell` and `pwsh` shells; final actionlint passes. The initial documentation parser also needed to remove Markdown's common indentation before parsing here-strings; corrected parsing passed without changing those existing code examples.

### Checks actually executed

| Command/check | Result |
| --- | --- |
| `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning` | **255 tests, 1,867 assertions passed**, 23.35 seconds for PHPUnit, exit `0`. Includes the new authentication/returned-event regressions and all eight MySQL race cases. The runtime was rebuilt with pinned Redis. |
| Disposable-stack cleanup | Project `leggtix-test-4d78b5c8891a46a1af80ac91c0e85531`, its MySQL container, network and generated test image were removed. Final Compose inventory contained only the existing three-service development project. |
| `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify-reservations.ps1` | **Passed, exit `0`**, on PHP 8.4.26, MySQL 8.4.11 and Redis. Mixed-spelling login requests shared five failures and subsequent `429`/`Retry-After`; a different account retained its budget. Guest/invalid/expired auth, overrides, malformed/nonobject/non-JSON/real multipart input, booking, replay/full/ineligible/FIFO/missing-ID outcomes and bodyless requests passed. |
| Live inventory and cleanup | Exact holders, two matching confirmed rows/counters, one unchanged waiter and the thirty-request reservation actor throttle passed. Fixture event/user/type/reservation/waitlist/token counts all ended at zero. Recovery file removed; shared Redis keys were not flushed. |
| Both wrapper shells | Windows PowerShell and PowerShell 7 each passed **14 cases**, exit `0`, with application configuration unchanged. |
| Workflow lint | `rhysd/actionlint:1.7.12` passed, exit `0`, after the shell correction. The disposable no-network container received only workflow text, with no workspace mount. Hosted Actions was not executed. |
| PHP syntax | Changed application files and the cutoff test passed `php -l` in the PHP 8.4 app container; the complete suite also parsed/executed the new test classes. |
| Composer | Strict validation passed. Locked dependency audit returned no advisories or abandoned packages, exit `0`. |
| Documentation/patch checks | Repository Markdown links and PowerShell blocks passed after common indentation removal. `git diff --check` passed; Windows line-ending conversion notices were informational. |

### Still to action or check

1. Follow the [morning acceptance checklist](issue-9-review-manual-checks.md) for the human Swagger/F12 flow: local assets/schema, authorization, strict validation, `201` booking, `409` replay/full, persisted holder/count and refresh returning to `401`. This follow-up did not repeat a browser session.
2. If manually accepting recovery through real HTTP, wait for the login budget to expire and confirm an equivalent spelling can then log in. The isolated regression already verifies 61-second recovery; the live automatic check verifies enforcement but does not wait for recovery.
3. After publishing this branch/workflow, verify its first hosted Linux/Windows jobs and configure `MySQL acceptance`, `Windows wrapper (powershell)` and `Windows wrapper (pwsh)` as required checks on the intended merge branch. These repository settings were not changed.
4. Keep #9 open until the user has reviewed the remaining acceptance evidence. No new feature milestone is required by these fixes. Cross-IP account abuse policy is public-launch work; larger bursts, deliberate deadlock cycles, query/latency measurements and multi-server/future-workflow races retain the existing broader testing tickets.

At the end of that follow-up, changes were local and uncommitted on `LT-9-Event-Reservations`.

## CI simplification follow-up — 9 October 2026

The user published the previous changes as `78c3c2f562a7e7c3ee2751bfcd6d99240288864c`, then reported the failing [first hosted workflow](https://github.com/GLegg18/LeggTix/actions/runs/37858162133). GitHub job logs confirm `MySQL acceptance` succeeded on Linux: **255 tests / 1,867 assertions**, 21.73 seconds, followed by successful cleanup. Both Windows wrapper jobs failed only `MissingDocker`, finishing with 13 passing cases and one failure each. The test fixture retained `System32` in its child PATH, where the hosted runner had a real Docker CLI; the supposed missing-command case instead reached Compose and a stopped engine.

The coordinator simplified the workflow to one Ubuntu `MySQL acceptance` job using Bash and Docker Compose directly. It builds the existing test image, runs the full MySQL suite with warnings treated as failures, and always attempts cleanup in a separate step. Run ID and attempt identify the disposable project; both steps explicitly select the same project and test Compose file. Test or cleanup failure fails the job. Read-only permissions, pinned checkout and disabled persisted credentials remain. PowerShell helpers are local Windows conveniences; no Windows runner or PowerShell installation is required by CI. Current guides and the morning checklist now describe this arrangement.

**Tester — fixed:** Restricted each mock wrapper child's PATH to its own fixture directory. Absolute shell paths and COMSPEC keep the mock executable usable. A temporary fake `SystemRoot/System32/docker.cmd` trap reproduced the old failure without modifying actual Windows system files; the identical probe after the fix returned the missing-Docker diagnostic without invoking Docker. Both complete shell harnesses passed. No application, database or browser checks were performed by this agent.

**Security reviewer:** No actionable issue in workflow permissions, project isolation, failure propagation, cleanup, revised harness or current CI instructions. The reviewer performed source and patch checks only. **Delivery reviewer:** Confirmed the test configuration is self-contained, required directory placeholders are tracked and Compose configuration validates. The coordinator implemented the CI/documentation change; a separate developer agent was not used for this focused follow-up.

| Check actually executed | Result |
| --- | --- |
| Direct Compose acceptance command from the revised workflow, invoked locally against Linux containers | **255 tests / 1,867 assertions passed**, 24.05 seconds, exit `0`. No PowerShell test wrapper invoked. |
| Scoped cleanup | Disposable project `leggtix-ci-local-a01f0b1bec074d2699efbb1a46503765` removed its MySQL container, network and generated test image. |
| Local Windows PowerShell and PowerShell 7 harnesses | **14 cases passed / zero failed per shell**, exit `0`, application configuration unchanged. |
| Workflow lint | `rhysd/actionlint:1.7.12` passed, exit `0`, with only workflow text supplied to an isolated no-network container. |
| Documentation and patch | 35 local links resolved and 30 PowerShell blocks parsed across the three revised current guides. Patch formatting passed. |

The revised workflow has not been published or executed on GitHub. After publication, select its exact commit in Actions and expect one green `MySQL acceptance` job, passing tests and a successful `Clean up test stack` step. If branch protection was configured, require only `MySQL acceptance` and remove both obsolete Windows wrapper requirements. Repository settings were not changed. Issue #9 remains open; the browser, real HTTP recovery and broader checks listed in the [morning checklist](issue-9-review-manual-checks.md) still apply. No browser or deployment verification was repeated. These follow-up edits remain uncommitted; the pre-existing `.gitignore` change is preserved.
