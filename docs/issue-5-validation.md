# Issue #5 verification report

Verified on 7 October 2026. Covers [registration and login #5](https://github.com/GLegg18/LeggTix/issues/5), the initial physical schema designed in [#27](https://github.com/GLegg18/LeggTix/issues/27), and the isolated Docker test runner.

This records the checks performed for this milestone. Use the [testing guide](testing.md) to run the current suite and the [manual test plan](manual-test-plan.md) for repeatable API checks.

## Implemented scope

The API provides registration, login, authenticated user lookup and current-token logout using Laravel Sanctum. Registration normalizes email, explicitly hashes every submitted password, creates only `regular_user` accounts, and creates the account and token in one transaction. Tokens expire after 24 hours. Validation, guest and throttle failures return JSON; user fields are explicitly selected and credential responses use `Cache-Control: no-store`.

Migrations create `users`, `event_types`, `events`, `reservations`, `waitlist_entries` and Sanctum's `personal_access_tokens`. The five domain tables use MySQL checks, restricted foreign keys, generated active-entry uniqueness, indexes and UTC `DATETIME(6)` timestamps. The [database design](database-design.md) documents the fields and constraint boundaries; the [authentication guide](authentication.md) documents the API contract.

The Docker runner creates a fresh, isolated MySQL 8.4 test database for each invocation, runs the tests, and attempts project-scoped cleanup in `finally`. The host environment file, cached configuration and application database are excluded from the test stack.

## Executed checks

| Check | Result |
| --- | --- |
| Complete suite in Docker/MySQL 8.4 | **106 tests, 622 assertions passed**, 9.90 seconds, exit `0`, with `--display-warnings --fail-on-warning`; no warnings remained. |
| Suite coverage | 49 authentication cases, 56 schema cases and one migration case. |
| MySQL constraints and metadata | Exact role/status values, timestamp/capacity checks, duplicate rejection, retained terminal history, generated fields, foreign-key restrictions, indexes, UTC and microsecond timestamps passed. |
| Migration lifecycle | All six migrations ran; rollback removed tables in foreign-key order; rerun restored them; repeated migrate succeeded. |
| PowerShell runner regression suite | **28 cases passed**, 14 each on Windows PowerShell 5.1 and PowerShell 7. Mock Docker coverage includes preflight, stderr, argument forwarding, failure exit codes, unique projects and cleanup failures. |
| Real runner failure and database guard probes | Invalid invocation returned exit `1` and cleaned its stack. Network-disabled probes rejected a non-test database before connection and reported an unavailable test connection before migrations; both produced zero assertions. |
| Isolation and teardown | No containers, networks, volumes or generated images remained for the four verification projects. Normal app/MySQL/Redis container IDs and start times, host `.env`, autoload and bootstrap cache hashes were unchanged. |
| Normal Docker services | App health returned `200`, Redis returned `PONG`, and all six normal migrations remained `Ran`. |
| Standalone HTTP authentication lifecycle | Normalized registration/login, explicit fields, no cookies, `no-store`, independent tokens, current-token logout, guest/revoked JSON `401` without Accept, privilege/duplicate `422` and persistent-cache throttle `429` passed. |
| Three concurrent same-email registration races | Each produced one `201`, one `422`, exactly one user and one token through two independent PHP server processes and a request barrier. |
| Manual walkthrough | Completed successfully in the local Docker/VS Code PowerShell environment, as reported by the project owner. |
| PHP syntax, package discovery and route inventory | Passed; the unused Sanctum CSRF-cookie route is absent. |
| Composer validation and locked dependency audit | `composer validate --strict` passed; `composer audit --locked --no-interaction` found no vulnerability advisories at verification time. |
| Documentation checks | PowerShell command parsing, local documentation links and `git diff --check` passed. |

The initial automated suite also passed on temporary PHP 8.4.26/MySQL 8.4.11 runtimes. The standalone HTTP lifecycle and registration races used temporary runtimes with a persistent file cache. Those checks are distinct from the later Docker suite and the project owner's manual walkthrough. Temporary runtime servers were stopped after verification.

## Findings fixed

| Finding | Cause and risk | Fix and verification |
| --- | --- | --- |
| Bcrypt-shaped literal passwords authenticated incorrectly | Laravel's `hashed` cast preserved a submitted string that already looked like a bcrypt hash, allowing its underlying password to authenticate instead of the submitted literal. | Explicit `Hash::make` during registration. Regression and independent review confirmed that the literal authenticates, the underlying password fails, and configured hashing cost is honored. |
| Guest requests without Accept returned `500` | Laravel attempted to resolve a nonexistent browser login route before JSON exception rendering. | Disable guest redirects. Automated regressions and actual HTTP requests return JSON `401` for guest, invalid and revoked tokens. |
| Missing-file warnings across the Docker suite | The image safely excluded the host `.env`, but phpdotenv's suppressed missing-file reads were captured by PHPUnit. | Create an empty `.env` only inside the test image. The complete Docker rerun passed with warnings treated as failures. |
| Generated test images survived cleanup | A unique Compose project generated a separate image tag on each invocation. | Add project-scoped `--rmi local` to teardown and the recovery command. Final checks found no generated images remaining; shared base images and build cache remain reusable. |

The reported dedicated-database error was a safety guard refusing configuration before migrations, not evidence of failed database creation. The normal app container inherits its application database name. The isolated runner now supplies test settings and excludes cached host configuration; diagnostics identify rejected configuration or connection prerequisites clearly.

## Review outcomes

Developer implementation and fixes are complete. Tester acceptance passed the authentication, schema and migration suite plus the runner regression cases. The guest-response defect identified during testing was fixed; no unresolved tester finding remains.

Security review identified the bcrypt-shaped-password defect and confirmed the guest-response defect; both are fixed and regression-tested. The completed review covered input validation, role assignment, credential handling, token expiry/revocation, response fields, CORS, database constraints, configuration isolation and cleanup scope. No unresolved authentication or runner security/data-integrity finding remains. The reviewer used code/framework probes and read-only checks; physical MySQL, HTTP and Docker verification were performed separately.

## Limits and follow-up work

- Schema constraints do not allocate capacity, reconcile counters with child rows, prevent simultaneous active reservation/waitlist membership, enforce published/future eligibility, promote FIFO waitlists or authorize event ownership. Those workflows need transactions, event locking and their own MySQL race tests before booking endpoints are exposed.
- The runner's mock tests cover failure handling; real concurrent runner invocations, Ctrl+C and forced-termination cleanup were not exercised. Losing the Docker engine or forcibly terminating PowerShell can prevent `finally` cleanup. The [testing guide](testing.md) gives the exact project-scoped recovery procedure.
- SQLite cannot establish the intended MySQL constraint/type/locking behavior. Load/EXPLAIN tests, production deployment, frontend/session integration, domain seeds, password reset and email verification remain outside this milestone.
- Compose does not start Laravel's scheduler. Token pruning must be invoked manually locally or supplied by a scheduler when deploying.
- The future notification design retains an unresolved commit-to-enqueue reliability gap documented in the database design; notification delivery is not implemented or verified here.
