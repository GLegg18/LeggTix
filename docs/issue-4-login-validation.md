# Issue #4 required login factor and trusted-browser validation

Verified on 13 October 2026 against the local PHP 8.4 / Laravel 13 / MySQL 8 / Redis / Mailpit stack. This report supersedes the password-only login behavior in the [earlier email report](issue-4-email-validation.md); its 364-test result remains historical evidence for the previous committed baseline.

## Result

The final isolated suite passed **419 tests, 3,636 assertions, 68.06 seconds**, with warnings treated as failures. Real HTTP signup, required login-code verification and booking passed through the normal app and existing Compose Redis worker. Actual Chrome Swagger requests proved automatic storage/sending of the Secure trusted-browser cookie: OTP opt-in 200, later password login 200 without another email, wrong password 422, cookie-only protected request 401, logout 204 followed by a new factor challenge 202, and forget-all 204 invalidating that pending code with 422.

All tagged fixture rows, tokens, authentication state and six captured messages were removed afterward. No normal DB reset/seed, shared Redis flush, queue clearing, temporary API server/worker or global inbox deletion was used.

See the [login/trust contract](login-email-verification.md), [authentication guide](authentication.md), [accepted scope](mfa-assessment.md), and [registration ownership flow](registration-email-verification.md). All accounts, including legacy/demo accounts and every role, require the factor on new login unless an explicitly remembered browser is still valid. The password remains required. Existing API tokens retain their original 24-hour expiry; proof-backed registration still returns its initial token directly. A nonnull original `email_verified_at` is preserved; OTP fills only a null marker.

## Automated coverage

| Tests | Checks |
| --- | --- |
| `LoginMfaTest` | No early bearer token; safe 202 challenge/200 token shapes and no-store headers; legacy/verified account behavior; malformed and leading-zero codes; five failed guesses persist across independent MySQL sessions; generic expired/unknown/replayed code errors; resend rechecks password and enforces DB cooldown; credential/email changes and deletion invalidate pending codes; required queue/logger failure sanitized 503; real outermost commit/rollback dispatch; default opt-out; Secure/HttpOnly/Strict/host-only/Path=/ cookie metadata; hashed validator storage; password always required; cookie alone cannot authorize; rotation with fixed original expiry; invalid/expired/revoked/malformed/oversized/array-valued/foreign cookies fail closed; stale-validator family logout; token/owner isolation; forget-all invalidates pending OTP; cross-IP/MySQL-equivalent account limits and cross-generation code budgets; JSON-only and query/unknown-field rejection; existing-token rollout policy. |
| `LoginMfaConcurrencyTest` | Separate PHP/MySQL processes demonstrate actual held-row contention. Competing OTP consumption yields one token/device; rollback preserves the code for the second contender. Competing old-cookie reuse permits one trusted login; rollback preserves its validator. Independent device-row maintenance locks crossing trusted-device or OTP expiry cannot admit an expired credential. A password changed after its initial check but before the user lock is acquired cannot issue a challenge/token. |
| `LoginVerificationJobTest` | Actual multipart MIME with correct recipient/code/UTC expiry/reference; no credential hashes in mail; job retry metadata and hidden model secrets; missing/rotated/consumed/exhausted/expired/credential-changed delivery suppression; no inventory or auth-state writes during delivery; pruning preserves recent consumed-code cooldown and live devices, and removes only terminal records. |
| Token-write regression cases | Both thrown exceptions and Eloquent `creating` observers returning false roll back signup/proof consumption, OTP consumption/trust enrollment/audit marker, and trusted-cookie validator rotation. The same proof/code/old cookie remains usable for a successful retry. |
| Adapted existing tests | Authentication/roles/token lifecycle, demo users, registration ownership and transactional-mail workflows complete the real login API using `CompletesLoginChallenges`; no production factor bypass was added. OpenAPI includes all ten operations and same-origin Swagger cookie behavior; migration rollback/rerun and the new enforced checks/restricting foreign keys remain covered. |

`tests/Support/CompletesLoginChallenges.php` submits real password and code requests, asserts no token at the password step, then reads the encrypted code from its isolated test fixture. It does not bypass production cooldowns. Where a resend/time-window test intentionally ages `issued_at` or device fixture timestamps, that mutation is explicit and restricted to the disposable test DB. MySQL UTC governs expiry; clock-sensitive contention tests use real lock waits rather than assuming `RefreshDatabase` represents an outer commit.

## Commands and results actually recorded

Run from the repository root in PowerShell. PHPUnit always used the isolated repository runner, never the normal app DB.

1. The first focused invocation was not executed because automatic approval review hit a usage limit. It was a review availability failure, not an unsafe-action finding. After the user resumed work, the same authorized isolated command ran successfully:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 '--filter=LoginMfa|LoginVerificationJob|Authentication|DemoSeeder|RegistrationEmailVerification|TransactionalEmailWorkflow|Migration|DatabaseSchema|ApiDocumentation' --display-warnings --fail-on-warning
   ```

   **272 passed, 2,574 assertions, 52.56 seconds, exit 0**. Disposable project `leggtix-test-d0f266e444f54f7a9402bc204d0f15ff` cleaned up successfully.
2. The coordinator's first full snapshot passed **415 tests, 3,604 assertions, 68.29 seconds**. The token observer-veto defect was found after that image snapshot, so this is an intermediate result rather than final validation.
3. After the persistence guard fix and regression additions:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 '--filter=token_write_failure_rolls_back' --display-warnings --fail-on-warning
   ```

   **Six passed, 48 assertions, 2.45 seconds, exit 0**. Disposable project `leggtix-test-762c1da7a5c4472d8abe733af60cea6f` cleaned up successfully.
4. Final coordinator-run complete suite, including the fix and all final regression cases:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   **419 passed, 3,636 assertions, 68.06 seconds, exit 0**. Disposable project `leggtix-test-07bd8d6368304e5d913f52bc92b7829e` removed its MySQL container, network and local test image successfully.
5. The adapted live helper passed PHP lint. Security reviewed its retention and exact-user child-row cleanup before execution:

   ```powershell
   docker compose exec -T app php -l tests/Support/transactional-email-smoke.php
   docker compose exec -T app php tests/Support/transactional-email-smoke.php --keep-fixtures --keep-messages
   docker compose exec -T app php tests/Support/transactional-email-smoke.php cleanup 733da9bcdfcbb4edd1687085
   ```

   Acceptance and final cleanup both exited 0. `git diff --check` passed; Git emitted only existing working-copy line-ending conversion notices.

## Real HTTP and Chrome evidence

The actual helper created a tagged capacity-one future event and used registration-code → proof → signup → password challenge → captured login code → login token → booking. It verified no token was created by password acceptance and no trusted device was enrolled when `remember_device` was omitted. Wrong/replayed signup/login codes, wrong/missing registration proof, guest booking, holder override, duplicate booking and full capacity were rejected. Inventory remained one confirmed place. The existing Compose worker delivered exactly four messages: registration code, welcome, login code and booking confirmation. Generated customer/event text was HTML-safe and literal in plaintext, with correct holder, `LT-19` reference and London-local times. Codes/proofs/access tokens were omitted from helper output.

For the retained recipient `issue4.customer.733da9bcdfcbb4edd1687085@example.test`, actual Chrome at `http://localhost:8000/docs/api` then performed:

1. Password request **202**, challenge only. Its actual Mailpit email reference matched that response.
2. Code verification with `remember_device: true` **200**.
3. Same-browser password request **200** using the browser's automatically stored/sent cookie; no cookie header/value was injected by the tester. Mailpit remained at five messages: the helper's four plus this browser OTP.
4. Wrong password **422** despite trust, and `/api/user` without a bearer token **401** despite the cookie.
5. Authenticated logout **204**, then correct password in the same Chrome browser **202**, with a new challenge and no token after the issuance cooldown.
6. Authenticated `DELETE /api/trusted-devices` **204** using the other still-valid factor-issued token. The actual pending Mailpit code then failed **422**, even with `remember_device: true`.

Read-only inspection of the exact owned DB row showed expiry **2026-10-20 15:50:10.187394 UTC** unchanged after trusted use, while `last_used_at` advanced. Logout set the family revocation time. Forget-all left the pending challenge consumed and both code/delivery secrets cleared. Final Mailpit subject counts were one registration verification, one welcome, one confirmation and three login-code messages: six total, with none added by the trusted password login. Captured Chrome warning/error logs were empty.

Direct Chrome cookie-jar settings inspection was blocked by the browser URL policy, which permits HTTP/HTTPS pages only. No workaround was attempted. Automatic cookie behavior above and the exact secure/host-only/HttpOnly/Strict creation/deletion attributes were verified, but this report does **not** claim direct cookie-jar inspection or that its value was observed absent after logout. Browser network tracing and DevTools cookie inspection were not run.

The exact tagged cleanup removed owned login/trust rows before their restricting user foreign keys, then owned users/tokens/event/reservation/type/registration proof and six recipient-scoped message IDs. Shared queues and unrelated data/messages were untouched. Reloading the exact Mailpit recipient search after cleanup is the visible confirmation.

## Findings and role outcomes

| Finding / role | Cause, fix and verification | Status |
| --- | --- | --- |
| Security reviewer: expiry sampled before device lock, Medium | Trusted-login time could become stale while waiting on a separately held device row; optional device locking could similarly cross OTP expiry. Developer samples DB UTC after relevant locks. Separate-process tests hold only the device row and let both expiries pass before release; no token is issued. | **Fixed; regression passed.** |
| Security reviewer: array-valued cookie input, Medium | PHP bracket-cookie parsing can produce an array, which could reach a typed string service and raise TypeError. Developer added the shared controller string guard. An array-valued foreign credential returns a challenge without a 500, token or foreign-device revocation. | **Fixed; regression passed.** |
| Developer: silently vetoed token persistence | Sanctum can return a `NewAccessToken` even if an Eloquent creation observer returns false. That could otherwise consume proof/code or rotate trust while returning an unusable token. Both issuance paths now require a persisted token ID. Exception and false-veto cases prove rollback and successful retry for signup, OTP/trust and trusted rotation. | **Fixed; six targeted cases and final full suite passed.** |
| Tester | Independently added/ran feature, MIME, MySQL concurrency and regression tests; exercised real normal HTTP/worker/Mailpit and Chrome; recorded the narrow cookie-jar limitation and exact cleanup. | **Complete; no unresolved production defect reproduced.** |
| Security reviewer | Read-only schema, credentials, rates, JSON/CSRF boundary, owner/revocation, lock/expiry, secret storage/payload, pruning, Swagger and cleanup review. Final source review approved. Runtime evidence above came from tester/coordinator. | **Complete; no unresolved findings.** |
| Developer | Implemented the required factor, trusted-browser lifecycle, persistence guards, additive schema, generated mail, worker/documentation/OpenAPI changes. Reported production PHP/OpenAPI/PowerShell/Compose checks. | **Complete.** |

No production deployment, external mail-provider delivery, vulnerability audit, image scan, Nuxt signup/login UI or authenticator-app flow was tested. Email OTP is not phishing resistant. Async delivery may fail, be delayed or repeat; already-started SMTP sends can race a later invalidation. Existing bearer-token rollout is deliberately unchanged, as documented in the accepted scope.

## Manual test plan

Use Windows PowerShell in `C:\Users\georg\Documents\LeggTix`, Docker Desktop in Linux-container mode, and a current Chrome browser. These steps use disposable `.test` fixtures, not real recipients. For a new checkout complete [README first-run setup](../README.md#first-run); preserve the existing app key and database settings on an existing checkout.

1. **Start/check the local stack.**

   ```powershell
   Set-Location C:\Users\georg\Documents\LeggTix
   docker info --format '{{.OSType}}'
   docker compose up -d --build
   docker compose exec app php artisan migrate
   docker compose restart worker
   docker compose ps
   curl.exe -sS -i http://localhost:8000/api/health
   ```

   Expect `linux`, app/MySQL/Redis/Mailpit healthy, worker running, nine migrations applied and health200. Local settings must use SMTP `mailpit:1025`, Redis queue and Compose `mysql`/`redis` hosts; `.env` URL overrides must not point elsewhere. The helper refuses unsupported destinations. Never run `migrate:fresh`, clear shared queues or add `down -v`.
2. **Run the isolated regression suite.**

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   Expect all tests passing; the recorded final result is 419 tests/3,636 assertions. Its temporary DB/containers are removed automatically. MySQL contention, expiry, rollback, replay and account/owner boundaries are included; do not run bare PHPUnit against the normal app.
3. **Create and exercise owned live fixtures.**

   ```powershell
   docker compose exec -T app php tests/Support/transactional-email-smoke.php --keep-fixtures --keep-messages
   ```

   Save the printed acceptance tag and exact customer email. Expect PASS for real signup, two-step login, booking and failure paths; four messages and one allocated place. This option intentionally retains tagged fixtures for the browser steps. The customer password is the helper's disposable fixture value `local-mail-acceptance-password`. Do not substitute a real account. Wait at least 61 seconds after this run before requesting another code for that customer.
4. **Open the real browser interfaces.** In Chrome open **http://localhost:8000/docs/api** and **http://localhost:8025**. In Mailpit paste the exact printed customer email into **Search**, then click **Search**. Expect four messages. The verification/login emails contain codes and request references; welcome/confirmation contain no passwords or bearer tokens.
5. **Start a browser login.** In Swagger expand **POST /login**, click **Try it out**, and replace the JSON with:

   ```json
   {"email":"issue4.customer.YOUR_PRINTED_TAG@example.test","password":"local-mail-acceptance-password"}
   ```

   Replace `YOUR_PRINTED_TAG` with the actual tag, then click **Execute** once. Read **Server response**, not the example responses below it. Expect202 and only `challenge_id`/`expires_at`, with no access token. If429, obey Retry-After; resending requires the password and at least 60 seconds between issuances.
6. **Complete the factor and opt in.** Refresh the exact Mailpit search; open the newest **Your LeggTix login code** whose reference matches this challenge. In Swagger expand **POST /login/email-verifications/{challenge}/verify**, click **Try it out**, paste the response challenge ID into the path field, and use:

   ```json
   {"code":"SIX_DIGITS_FROM_THIS_EMAIL","remember_device":true}
   ```

   Keep the code as a string, including leading zeros, and click **Execute**. Expect200 with safe user fields, bearer token and its expiry. The trust secret must not appear in JSON. Save this token privately for logout/forget-all; do not publish it. Default `false` or omission enrolls no browser trust.
7. **Prove automatic trust and its limits.** Return to the existing **POST /login** request and click **Execute** with the correct password. Expect200 without a new email; never paste a Cookie header. Change only the password to `wrong-password`, execute, and expect422. Before applying any bearer authorization, expand **GET /user**, click **Try it out** → **Execute** and expect401 despite the browser cookie. Restore the correct password text afterward. Browser support for Secure cookies on localhost is required; otherwise use HTTPS or verify a code at each login, not a weakened cookie.
8. **Check logout forces the factor again.** Copy the latest successful login's raw `access_token` into Swagger **Authorize** → **Value** → **Apply credentials** → **Close**, without adding `Bearer`. Expand **POST /logout**, choose **Try it out** → **Execute**. Expect204 and the used token revoked. After the issuance cooldown, execute the correct password request in the same Chrome browser. Expect202/new challenge/no token. Its new login-code email should arrive; the previous trusted password login should have added none.
9. **Check forget-all invalidates a pending code.** Use the other still-valid OTP-issued token saved in step6: open **Authorize**, remove the revoked authorization, apply that raw token, then close. Expand **DELETE /trusted-devices**, choose **Try it out** → **Execute**, and expect204. Read the newest pending code from step8's Mailpit message; try verifying it with `remember_device:true`. Expect422 and no new token/device. This operation affects only the authenticated account's browsers and pending challenge; existing bearer tokens and other users remain unaffected. Automated owner-isolation and concurrent rotation cases cover these invariants in the isolated DB.
10. **Diagnose without discarding unrelated work.**

    ```powershell
    docker compose logs --tail 60 worker
    docker compose logs --tail 60 app
    docker compose exec app php artisan queue:failed
    docker compose exec app php artisan schedule:list
    ```

    Expect login jobs RUNNING then DONE and hourly `login-email:prune`. Required enqueue outage returns503 without a token; retry the password step after cooldown. Codes expire after ten minutes and five wrong guesses disable them. Record unexpected status/job names without sharing codes/passwords/tokens. Do not clear queues or all failed jobs. The isolated pruning test is the safe check of cooldown retention and terminal-record removal.
11. **Clean up only your run.** Copy the helper's printed cleanup command, replacing the example tag below with YOUR tag:

    ```powershell
    docker compose exec -T app php tests/Support/transactional-email-smoke.php cleanup 733da9bcdfcbb4edd1687085
    ```

    Expect PASS cleanup. Refresh that exact Mailpit search and expect **0 of 0 / No results**. This removes the tagged users, their tokens/login/trust state, event/reservation/type and exact recipient message IDs. It is safe to repeat. If an in-flight job finishes after a failed run, repeat this same tagged cleanup after it settles; never use global inbox deletion.
12. **Finish in a known state.** Reload Swagger to discard its in-memory authorization and request inputs, and close any copied credential notes. Leave containers running for development, or use `docker compose down` without `-v` to preserve ordinary MySQL data. Direct browser cookie-jar/DevTools inspection was not part of the automated browser tooling checks; creation/deletion attributes and absolute expiry are covered by the suite, and automatic browser behavior is checked above.
