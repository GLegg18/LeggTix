# Issue #4 expanded email and registration validation

Historical baseline: the 364-test results and direct password-login checks below were recorded before the required login email challenge and optional trusted-browser change. For the current login contract, use the authentication guide and the [login validation report](issue-4-login-validation.md). This report preserves the earlier evidence; its registration and transactional-email checks remain relevant.

Verified on 13 October 2026 against the repository's PHP 8.4 / Laravel 13 / MySQL 8 / Redis / Docker Compose stack. This report covers generated verification, welcome and booking-confirmation emails and mandatory email ownership verification before signup. The earlier [Mailpit infrastructure report](issue-4-validation.md) remains the record for the original transport-only scope.

## Result and scope

The final isolated suite passed **364 tests, 2,910 assertions, 45.53 seconds**, with warnings treated as failures. The real local HTTP app and existing Compose Redis worker delivered all three expected multipart emails to Mailpit. Chrome inspection confirmed welcome HTML and confirmation HTML/plain text, and the exact recipient search was empty after scoped cleanup.

New registration is email → emailed six-digit code → one-use registration proof → name/password signup. Existing accounts, including demo users whose `email_verified_at` is null, can still log in. This is email ownership verification before registration; TOTP and extra login challenges are outside this change. See the [accepted authentication assessment](mfa-assessment.md), [verification contract](registration-email-verification.md), and [transactional email guide](transactional-email.md).

The app stores a short-lived HMAC code hash, encrypted delivery code and hashed registration proof in a verification row. It does not store generated email bodies in an application table. Mailpit holds captured local messages for inspection.

## Automated coverage

| Test file | Meaningful assertions |
| --- | --- |
| `tests/Feature/RegistrationEmailVerificationTest.php` | Anonymous three-step flow; normalized exact mailbox binding, including accent-equivalent MySQL strings; mandatory, unknown, mismatched and expired proofs; leading-zero and malformed codes; five failed guesses persist after 422; expired code scrubbing; code/proof replay; resend rotation; one-use consumption; atomic account/token failure and successful retry with the same proof; required enqueue outage 503 and recovery; queue plus logger outage; real outer commit/rollback; query-string secret refusal; per-IP limits and per-mailbox budgets across IP changes/resends; safe response shapes and secret storage/payloads. |
| `tests/Feature/RegistrationVerificationConcurrencyTest.php` | Independent PHP/MySQL sessions demonstrably wait on an already-held verification row. Competing signups create exactly one user/token; first contender rollback preserves proof for the second. Code verification mints exactly one proof. Code and proof expiry are checked after a genuine lock wait using the MySQL clock. |
| `tests/Feature/RegistrationVerificationJobTest.php` | Actual verification HTML/text MIME with correct recipient/code/expiry/reference; scalar ID plus challenge generation in jobs; missing, rotated, verified, consumed, exhausted or expired delivery suppression; no inventory changes; retry metadata; pruning preserves a valid proof after its old code expiry and is repeatable. |
| `tests/Feature/TransactionalEmailWorkflowTest.php` | Signup and booking hooks enqueue only scalar IDs; no credentials/tokens in jobs; real production transaction manager plus independent MySQL observer prove only the outermost commit releases mail; outer/savepoint rollback silence; real configured sync jobs and MIME run after commit; optional enqueue/logger outages preserve successful account/booking responses; failed, duplicate, rejected or unauthenticated operations stay silent; seeders, factories and direct allocation stay silent. |
| `tests/Feature/TransactionalEmailJobTest.php` | Current recipient/event data is re-read; correct booking holder, `LT-{id}` reference, venue and event-local start/end/timezone; optional end times; HTML escapes customer/event/venue text while plaintext preserves literal characters; no passwords/access tokens; deleted/cancelled/superseded attempts and unbookable events are suppressed; repeat execution and SMTP retry never write ticket inventory. Repeated execution may repeat delivery. |
| Existing authentication, documentation and schema tests | Existing auth tests now use already-verified fixtures without sending unrelated mail; registration IP test rotates mailboxes so the new mailbox limit cannot mask it; public OpenAPI operation inventory includes both verification routes and required write-only proof; additive migration rollback/rerun and new database checks remain covered. |

`tests/Support/CreatesRegistrationProofs.php` creates completed proof fixtures for unrelated workflow tests. Its expiry uses MySQL UTC so historical token-expiry tests can freeze Laravel's clock independently. Real verification endpoint tests and contention tests do not replace the ownership flow with those fixtures.

## Commands actually run

Commands were run from the repository root in PowerShell. PHPUnit always used `scripts/test.ps1` and its disposable MySQL environment, never the normal application database.

1. An initial focused attempt stopped before tests with `Docker Desktop is unable to start`. The coordinator restarted Docker Desktop, restored the existing stack and retried. No test mutation occurred in the failed prerequisite check.
2. The next attempt stopped during test loading because a new private helper was called `result()`, colliding with PHPUnit's final method. It was renamed to `awaitResult()`; this was a test harness correction.
3. The broad focused command ran **203 passing tests, two harness expectation failures, 1,492 assertions, 31.91 seconds**:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 '--filter=RegistrationEmailVerification|RegistrationVerification|TransactionalEmail|Authentication|ApiDocumentation|Migration|DatabaseSchema' --display-warnings --fail-on-warning
   ```

   The old registration IP test reused the empty mailbox and hit the new mailbox budget first. It now rotates mailboxes while holding the IP constant. The required-queue-outage recovery test needed to clear its mocked Queue facade/manager before installing a fresh fake. Both are harness adaptations; no production assertion was weakened.
4. A narrow rerun verified both corrections plus the final code-verification concurrency, required queue/logger failure and rotating-target IP tests: **six tests passed, 87 assertions, 2.12 seconds, exit 0**:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 '--filter=registration_is_limited_by_ip|required_verification_enqueue_failure|competing_code_verification|queue_and_logger_outage|request_ip_limit_bounds|verify_ip_limit_bounds' --display-warnings --fail-on-warning
   ```

5. The coordinator ran the final complete suite: **364 passed, 2,910 assertions, 45.53 seconds, exit 0**:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   Disposable project `leggtix-test-24513fe83fcd4faeb0549319ff6781fc` removed its MySQL container, network and local test image successfully. The earlier focused projects also cleaned up successfully.
6. The real helper lint passed, followed by successful acceptance and exact cleanup:

   ```powershell
   docker compose exec -T app php -l tests/Support/transactional-email-smoke.php
   docker compose exec -T app php tests/Support/transactional-email-smoke.php --keep-messages
   docker compose exec -T app php tests/Support/transactional-email-smoke.php cleanup 89acbac45e87e9e4eefedd93
   ```

## Real HTTP, worker and browser acceptance

The helper used the normal `http://127.0.0.1:8000` HTTP server inside the app container and the already-running Compose worker consuming Redis `emails`. It did not start a temporary API server/worker, change a Redis prefix, consume queues itself, or clear any queue.

Recipient `issue4.customer.89acbac45e87e9e4eefedd93@example.test` received exactly three messages: **Verify your email for LeggTix**, **Welcome to LeggTix**, **Your LeggTix reservation is confirmed**. The code was obtained from the actual captured email, then verified through HTTP. Codes, proofs, passwords and access tokens were kept out of helper output.

Actual HTTP checks: missing proof 422; malformed email 422; code request 202; wrong code 422; correct code 200; repeated code 422; proof bound to another mailbox 422; verified signup 201; duplicate signup 422; password login 200 with no extra welcome; guest booking 401; holder override 422; booking 201; repeated booking 409; second holder at full capacity 409. The actual worker delivered the messages and left one confirmed reservation and `confirmed_count = 1` for the capacity-one fixture.

The booking message used reference **LT-18**, test venue, **Tuesday 14 July 2099, 19:30 BST (+01:00)** and end **21:00 BST (+01:00)**, with **Europe/London** explicitly named. HTML safely displayed literal `<b>Customer & "Friends"</b>` and event text instead of interpreting customer tags. Plain text retained `&`, quotes and angle brackets. Welcome/booking messages contained none of the password, access tokens, verification code or registration proof.

The in-app browser was unavailable in this resumed session; the existing Chrome Mailpit tab was available and used. Its exact-recipient search showed three messages. Welcome HTML and booking HTML/Text were opened, and the captured browser warning/error log was empty. After the cleanup command, the same browser search showed **0 of 0 / No results**. Detailed browser network tracing was not run; real request status/body checks and Mailpit API content checks were performed by the helper.

The coordinator applied only the additive verification migration to the normal DB and started the emails worker. No normal DB reset, seed, table truncation, Redis flush, queue clearing or global Mailpit deletion occurred. Fixture cleanup removed only cryptographically tagged users/tokens, their exact owned event/reservation/type and verification row. Three exact recipient-scoped inbox IDs were deleted after inspection.

## Findings and role outcomes

| Role / finding | Symptom or risk | Cause and fix | Verification / status |
| --- | --- | --- | --- |
| Security reviewer: plaintext encoding, Low | Names/venues with ampersands or quotes appeared as HTML entities in the plain-text alternative. | Blade HTML escaping was also used in plaintext templates. Developer changed only plaintext dynamic fields to literal output; HTML remains escaped. | Actual MIME assertions, real captured messages and Chrome Text inspection passed. **Fixed.** |
| Security reviewer: initial unrun smoke helper, High | Its intended private Redis prefix could fail to update the already-resolved Redis manager; its final queue clearing could therefore erase normal `emails` jobs. | Prefix changes occurred after bootstrap. Tester replaced the entire approach with normal HTTP plus the existing Compose worker, and removed all Redis prefix changes, queue consumption and queue clearing from the helper. | Reviewer approved the replacement before execution; real acceptance and scoped cleanup passed. The unsafe version was **never run**. **Fixed.** |
| Tester | No unresolved production defect reproduced in the final implementation. | Test-loading/fake restoration/IP-budget assumptions were corrected in test code, as recorded above. | Focused corrections and final full strict suite passed. **Complete.** |
| Developer | Implemented verification/signup/booking side effects and generated templates, additive schema, worker and documentation. | Required verification enqueue failures return sanitized 503; welcome/confirmation remain best effort after commit. | Developer reported production lint and OpenAPI checks; independent tester/runtime checks above passed. **Complete.** |
| Security reviewer | Read-only auth, secret handling, exact email binding, rate limits, commit/rollback, job freshness, pruning and cleanup review. | Mandatory policy and reviewed schema/API implemented; code attempts commit before validation errors and proof consumption locks within signup transaction. | Final reviewer verdict approved with no unresolved findings. Concurrency and secret-safety evidence above supplied by tester. **Complete.** |

No external email-provider delivery, production deployment, dependency vulnerability audit, image scan, Nuxt signup UI or TOTP/login-MFA acceptance was performed. SMTP can fail or jobs can exhaust retries; successful registration/booking is not a delivery guarantee. Queued delivery can repeat. On a failed smoke run an already-in-flight SMTP message may arrive after immediate cleanup; the printed tag allows safe repeat cleanup once the worker settles.

## Manual test plan

Use Windows PowerShell and keep one terminal open. Commands below run from `C:\Users\georg\Documents\LeggTix`. Use the repository's existing `.env`; never substitute production credentials or run `migrate:fresh` on the usual app.

1. **Install/start prerequisites.** Docker Desktop must be installed, running and set to Linux containers. Open PowerShell and run:

   ```powershell
   Set-Location C:\Users\georg\Documents\LeggTix
   docker compose version
   docker info --format '{{.OSType}}'
   ```

   Expect a Compose version and `linux`. If this is a new checkout without `.env`, complete [first-run setup](../README.md#first-run) before continuing. For an existing checkout, keep its app key and database settings.
2. **Check local mail/queue settings.** Open `.env` in your editor. Its relevant values should match the [verification setup](registration-email-verification.md): `APP_ENV=local`, `QUEUE_CONNECTION=redis`, `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, `MAIL_URL=null`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_USERNAME=null`, `MAIL_PASSWORD=null`. MySQL/Redis hosts must be `mysql`/`redis`; `DB_URL`/`REDIS_URL` must not redirect elsewhere. Save only needed mail/queue changes, preserving the existing app key and database credentials. The smoke helper refuses unsupported destinations before creating fixtures.
3. **Start all services and apply only pending migrations.**

   ```powershell
   docker compose up -d --build
   docker compose exec app php artisan config:clear
   docker compose exec app php artisan migrate
   docker compose restart worker
   docker compose ps
   curl.exe -sS -i http://localhost:8000/api/health
   ```

   Expect app/MySQL/Redis/Mailpit healthy, worker running, migrations completed or `Nothing to migrate`, and HTTP 200 with `{"status":"ok"}`. An existing `.env` change requires `docker compose up -d --force-recreate app worker` to refresh container environment; wait for app health before proceeding.
4. **Run the full automated checks safely.**

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   Expect all tests to pass; this report's final run was 364 tests/2,910 assertions. The script creates/removes a separate test stack. Its MySQL contention cases prove one-use proofs, rollback preservation and expiry after locks, alongside the existing reservation race tests. Do not run bare PHPUnit against the normal app DB.
5. **Run the real workflow with disposable owned fixtures.** No seed data, manual organiser setup, existing event ID or copied token is required:

   ```powershell
   docker compose exec -T app php tests/Support/transactional-email-smoke.php --keep-messages
   ```

   Copy the printed 24-character acceptance tag and exact `issue4.customer.<tag>@example.test` recipient somewhere temporary. Expect three PASS groups for real HTTP workflow, generated messages/inventory and scoped fixture cleanup. The helper creates its own capacity-one future event, reads the actual emailed code, registers, logs in and books through HTTP. It automatically exercises missing/wrong proof, wrong/replayed code, duplicate signup, guest booking, holder override, duplicate booking and full capacity. These failures must add no extra welcome/confirmation and must leave one allocated place before cleanup. If it returns 429 after repeated runs, wait for the indicated rate window rather than clearing Redis.
6. **Inspect the captured messages in a browser.** Open **http://localhost:8025**. Paste the exact recipient printed in step 5 into **Search**, then click **Search**. Expect exactly three messages with the subjects listed above. Open the verification email: expect a six-digit code, a UTC expiry and request reference. The code may already have been consumed by the helper; never use it for a different signup.
7. **Inspect generated customer content.** Open **Welcome to LeggTix**, select **HTML**, and expect the literal customer name `<b>Customer & "Friends"</b>` without customer-controlled bold markup. Open **Your LeggTix reservation is confirmed**. Expect the same recipient, a reference `LT-<number>`, the tagged event, venue, London start/end times and explicit timezone described above. Select **Text**; `&`, quotes and angle brackets should remain literal characters. Neither workflow email should display a password, code, registration proof or access token.
8. **Exercise the API yourself if desired.** Follow [manual acceptance steps 5–11](manual-test-plan.md#5-set-up-a-fresh-demo-account) in a fresh PowerShell terminal. They give exact JSON requests to request a code, copy it from Mailpit, reject a wrong code, verify, register, authenticate and revoke tokens. Expect request202 → verification200 → signup201; absent/wrong proof422; login200; guest401; privilege attempt422. Finish code verification and registration within their separate ten-minute lifetimes. Requesting another code invalidates the previous challenge and proof; wait at least 61 seconds before a resend to the same mailbox.
9. **Inspect worker problems without discarding work.**

   ```powershell
   docker compose logs --tail 60 worker
   docker compose logs --tail 60 app
   docker compose logs --tail 60 redis
   docker compose exec app php artisan queue:failed
   ```

   On a normal pass, worker logs show all three job classes RUNNING then DONE and no new failed job for this test. If mail does not arrive within 30 seconds, record the failed step/status and job name. A verification enqueue outage is HTTP503 with Retry-After1, followed by the mailbox cooldown before requesting again. Do not clear the `emails` queue or all failed jobs to diagnose a single test. Omit codes/proofs/tokens/passwords when sharing logs.
10. **Remove only this run's retained messages.** Replace the example below with the tag printed by YOUR run, using no angle brackets:

    ```powershell
    docker compose exec -T app php tests/Support/transactional-email-smoke.php cleanup 89acbac45e87e9e4eefedd93
    ```

    Expect PASS cleanup, then refresh that exact Mailpit search and expect **0 of 0 / No results**. The helper's ordinary pass already removed its own app fixtures/tokens; cleanup is safe to repeat. Never click global **Delete all** or reset the app DB. If the helper failed while SMTP was in flight, inspect worker logs, wait for that job to finish, and repeat the same tagged cleanup.
11. **Check pruning scheduling without deleting unrelated local records.**

    ```powershell
    docker compose exec app php artisan schedule:list
    ```

    Expect `registration-email:prune` hourly. The isolated automated pruning test is the safe reproduction of expired/consumed removal and valid-proof retention. Running `schedule:list` does not run deletion. A scheduler process is required for automatic pruning outside this manual check; see the verification guide.
12. **Finish in a known state.** Keep the containers running to continue development, or run `docker compose down` to stop them while preserving local MySQL data. Do not add `-v`. Remove any copied secret values from your terminal notes; retained manual accounts from step 8 are described in that walkthrough's finish step. No live provider or real recipient was used.
