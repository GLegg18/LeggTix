# Issue #4 verification report

Verification date: 12 October 2026. Scope: [Add Mailpit for local email preview](https://github.com/GLegg18/LeggTix/issues/4).

The ticket requires the inbox at `http://localhost:8025`, Laravel and its queue worker reaching SMTP by Compose service name, capture without real delivery, and README configuration. Confirmation/promotion notification workflows remain separate tickets; this change supplies their local mail infrastructure.

This records the original infrastructure milestone. The owner subsequently expanded the same ticket with required signup email verification, welcome messages and booking confirmations. See the [expanded email validation report](issue-4-email-validation.md) for that work; promotion notifications remain in issue #15.

## Acceptance automation

`scripts/mailpit-smoke.ps1` checks the host-facing inbox and invokes `scripts/support/mailpit-smoke.php` in the normal app container. The PHP probe checks the effective local mail/queue configuration before creating anything, sends one direct SMTP message, and enqueues one Laravel `SendQueuedMailable` in Redis. It verifies that the queued message is absent until a separate `php artisan queue:work` process runs, checks exact subjects, `.test` recipients and body markers through [Mailpit's API](https://mailpit.axllent.org/docs/api-v1/), and restarts an empty worker to check that the completed job is not processed again.

Each invocation uses a random recipient/subject marker and its own queue. Cleanup targets only that queue, any failure records bearing its queue name, and the captured IDs with its exact subjects. It never submits an empty message deletion list, resets MySQL, seeds accounts/events, clears shared Redis, or processes the ordinary `default` queue. `-KeepMessages` preserves the two messages after a successful run for browser inspection; queue cleanup still runs. This is a real Docker/SMTP/Redis smoke test, separate from PHPUnit's deliberately isolated `array` mailer.

## Checks actually run

All checks below ran against the local Docker Desktop Linux stack. Commands were run from the repository root in PowerShell; Docker/localhost access required the sandbox escalation already authorized for this work. Existing MySQL/Redis services and application data were retained; the coordinator changed only ignored local mail environment values and recreated the app/Mailpit containers to load them.

| Check actually run | Result |
| --- | --- |
| `docker compose config --quiet` | Developer/coordinator: exit `0`; Compose accepts the Mailpit service and dependency configuration. |
| PHP syntax and PowerShell parser | `docker compose exec -T app php -l scripts/support/mailpit-smoke.php` passed, including after the guard fix. PowerShell parsed `scripts/mailpit-smoke.ps1` without errors. |
| `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\mailpit-smoke.ps1` | Tester: exit `0`. Inbox HTTP `200`; direct SMTP captured once; Redis held exactly one job and no queued message before worker execution; a separate worker delivered exactly one queued message; recipients, subjects and bodies matched; empty-worker restart produced no duplicate; owned queue/messages removed. |
| Same smoke command with `-KeepMessages` | Tester: exit `0`; repeated full SMTP/Redis acceptance successfully and retained only its two synthetic messages for browser inspection. Dedicated queue cleanup still completed. |
| README's exact Tinker mail command | Tester: exit `0` from Windows PowerShell. Captured `LeggTix Mailpit preview` with recipient `mailpit-preview@example.test` and expected HTML body. |
| Unsafe probe overrides | Tester: separate `docker compose exec -T -e <override> app php scripts/support/mailpit-smoke.php` commands using `APP_ENV=production`, `MAIL_URL=smtp://external.example.test:2525`, `REDIS_URL=redis://external.example.test:6379`, and `REDIS_QUEUE_CONNECTION=cache` each printed `REFUSED` and exited `1` before mail/job creation. No environment file was edited by these probes. |
| Host allowlist | Tester: normal localhost inbox returned `200`; internal `mailpit` API requests succeeded throughout the smoke test; `curl.exe --silent --output NUL --write-out '%{http_code}' -H 'Host: untrusted.example.test' http://127.0.0.1:8025/` returned `403`. Mailpit logs recorded the expected rejection. |
| Browser acceptance | Tester: opened Mailpit in the in-app browser, searched the unique marker, opened both messages, and inspected the `.test` recipient, direct text body and queued HTML preview. Captured browser warning/error logs were empty. |
| Cleanup and operations | Tester: revalidated the three retained message identities, then deleted only those exact IDs (two browser probes and one README preview). Final inbox total returned to `0`; no global inbox deletion was used. `docker compose exec -T app php artisan queue:failed` returned `No failed jobs found.` Mailpit logs showed SMTP/API listeners and the expected hostile-Host warning, with no SMTP failure. Runtime API reported Mailpit `v1.31.4`. |
| Full PHPUnit regression | Coordinator: `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning`: **277 tests, 2174 assertions passed**, 24.89 seconds, exit `0`. Disposable project `leggtix-test-fc5ee7a27f0148529eed0b1b3b04981a` removed its MySQL container, generated image and network successfully. |
| Documentation/diff checks | Coordinator: all 46 local Markdown file links across README/index/this report resolved; `git diff --check` passed. |

## Review outcomes and limits

- **Developer:** implemented pinned Mailpit, loopback-only inbox, internal SMTP, readiness dependency, mail environment defaults and README setup/smoke instructions. Developer lint/configuration checks passed; no application business code, migrations or domain notifications changed.
- **Tester:** real SMTP, actual Redis queue/independent worker, repeat invocation, completed-job replay, browser preview, documented command, refusal paths and scoped cleanup passed. No application defect was found. The acceptance helper's Redis guard finding below was fixed, then the repeated smoke run and negative overrides verified the final guard.
- **Security reviewer:** approved the final scope with no unresolved finding. Review covered repository instructions, issue/diff, installed Laravel mail/queue internals, official Mailpit documentation and diff checks. Reviewer performed read-only review; runtime mutations/checks were performed by the tester/coordinator. Dependency audit and image scanning were not run.

| Finding | Symptom/cause | Fix and verification |
| --- | --- | --- |
| Medium: DNS rebinding protection absent from initial Mailpit configuration | A loopback-only unauthenticated inbox needs HTTP Host validation as well as a local port binding. | Developer added `MP_ALLOWED_HOSTS=localhost,127.0.0.1,mailpit`; hostile Host returned `403`, while browser localhost and app-to-Mailpit API access worked. |
| Low: smoke helper could select remote Redis despite its local-only claim | Checking `database.redis.default.host` alone missed Laravel's URL override and the queue's selected Redis connection. | Tester now requires the Redis driver, selected `default` connection, `redis:6379`, and an empty Redis URL. Both remote URL and alternate-connection overrides refused with exit `1` before creating mail/jobs; both happy-path smoke runs passed. |

Ticket inventory, ownership and HTTP API rules are unchanged. No new mail HTTP endpoint or domain notification is introduced, so customer authentication/validation and reservation-capacity cases are outside this mail infrastructure smoke test. Worker crash/retry delivery guarantees and provider delivery are not established by restarting a successfully completed empty queue. The full PHPUnit run retains its established isolated MySQL/array-mail configuration; it does not independently exercise Mailpit. No separate Origin-header negative, Mailpit restart/storage-loss test, LAN attack test, load test, SMTP failure injection, hosted CI run, dependency audit or image scan was performed. Safe capture is supported by the internal SMTP configuration and absence of relay/forwarding settings plus the actual captured `.test` messages; no real-address delivery experiment was attempted.

## Manual test plan

1. **Prepare the host and repository.** Use Windows with Git and Docker Desktop installed, WSL 2 enabled, Docker Desktop running in Linux-container mode, and PowerShell open. Run:

   ```powershell
   Set-Location 'C:\Users\georg\Documents\LeggTix'
   docker compose version
   docker info --format '{{.OSType}}'
   ```

   Expect Compose v2 or newer and `linux`. If your checkout lives elsewhere, change only the `Set-Location` path. PHP and Composer run inside Docker; no host PHP or test user is needed.

2. **Prepare a new checkout only.** If `.env` already exists, continue to step 3 without replacing it. Otherwise run:

   ```powershell
   Copy-Item .env.example .env
   docker compose build app
   docker compose run --rm --no-deps app composer install --no-interaction
   docker compose run --rm --no-deps app php artisan key:generate
   ```

   Expect dependencies installed and a new application key saved to `.env`. These commands require network access on the first build. Preserve any existing key and database credentials.

3. **Check local mail settings.** Open `.env` in a text editor and set the following mail fields, preserving all other settings:

   ```dotenv
   MAIL_MAILER=smtp
   MAIL_SCHEME=smtp
   MAIL_URL=null
   MAIL_HOST=mailpit
   MAIL_PORT=1025
   MAIL_USERNAME=null
   MAIL_PASSWORD=null
   MAIL_FROM_ADDRESS="hello@example.com"
   MAIL_FROM_NAME="${APP_NAME}"
   ```

   Also retain `APP_ENV=local`, `QUEUE_CONNECTION=redis`, `REDIS_HOST=redis` and the repository's MySQL settings. No external SMTP URL, relay/forwarding setting or real credentials are needed. `mailpit` is the hostname inside Docker; `localhost` is for the browser on Windows.

4. **Start services and reload configuration.** From the same repository directory run:

   ```powershell
   docker compose up -d --force-recreate app
   docker compose exec app php artisan config:clear
   docker compose exec app php artisan migrate --no-interaction
   docker compose ps
   ```

   Expect `app` running and `mysql`, `redis` and `mailpit` healthy. Mailpit maps only `127.0.0.1:8025` to its web port; SMTP `1025` has no host mapping. Migrate applies missing tables, including queue failure storage, without resetting existing rows. No demo data or account login is needed. Stop any manually launched old worker with **Ctrl+C**; new workers must load the new mail settings.

5. **Open the inbox and check host protection.** Browse to `http://localhost:8025`. Expect the Mailpit inbox, with no login required. Existing previews may already be listed; leave them in place. In PowerShell run:

   ```powershell
   curl.exe --silent --output NUL --write-out '%{http_code}' -H 'Host: untrusted.example.test' http://127.0.0.1:8025/
   ```

   Expect `403`, while the ordinary browser URL continues to work. This checks Mailpit's allowed HTTP hosts, not a customer login permission.

6. **Run the automated live mail check.** Run:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\mailpit-smoke.ps1
   $LASTEXITCODE
   ```

   Expect `PASS` for the inbox, configured direct SMTP, one pending Redis job with no early queued delivery, independent worker delivery with matching content, no duplicate on empty-worker restart, and scoped cleanup; final exit code `0`. The script sends to a unique `@example.test` address and processes only its printed `mailpit-smoke-issue4-...` queue. Existing inbox messages, application data and ordinary jobs remain in place.

7. **Inspect real messages in the browser.** Run:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\mailpit-smoke.ps1 -KeepMessages
   ```

   Copy the printed `issue4-...` marker. In Mailpit's **Search** field type that marker and press **Enter**. Expect two rows whose subjects end with `direct` and `queued`. Click each row and inspect the recipient (`<marker>@example.test`) and body containing the same marker and either `direct SMTP body` or `queued SMTP body`. The queued body appears in the HTML preview. The completed worker has already exited; there is no permanent queue-worker service.

8. **Exercise the README copy/paste message.** Run:

   ```powershell
   docker compose exec app php artisan tinker --execute="Illuminate\Support\Facades\Mail::to('mailpit-preview@example.test')->send((new Illuminate\Mail\Mailable)->subject('LeggTix Mailpit preview')->html('Local SMTP preview from Laravel.'));"
   ```

   Clear the browser search and search `LeggTix Mailpit preview`. Expect one new matching message from this invocation with recipient `mailpit-preview@example.test` and body `Local SMTP preview from Laravel.` The command sends immediately; it does not require a queue worker.

9. **Check a refused unsafe configuration without editing `.env`.** Run:

   ```powershell
   docker compose exec -T -e APP_ENV=production app php scripts/support/mailpit-smoke.php
   $LASTEXITCODE
   docker compose exec -T -e MAIL_URL=smtp://external.example.test:2525 app php scripts/support/mailpit-smoke.php
   $LASTEXITCODE
   docker compose exec -T -e REDIS_URL=redis://external.example.test:6379 app php scripts/support/mailpit-smoke.php
   $LASTEXITCODE
   docker compose exec -T -e REDIS_QUEUE_CONNECTION=cache app php scripts/support/mailpit-smoke.php
   $LASTEXITCODE
   ```

   Each must print `REFUSED` and exit `1` before creating a message or job. These `-e` values affect only that short-lived command; the running app and `.env` retain their local settings. Refresh the inbox: no new probe messages should appear.

10. **Inspect failures if a happy-path check fails.** Run:

    ```powershell
    docker compose ps
    docker compose logs --tail=100 mailpit app
    docker compose exec app php artisan queue:failed
    ```

    On a clean setup expect healthy services, SMTP/API listeners in the Mailpit log, no relevant connection/transport errors, and `No failed jobs found.` Existing failed jobs can be unrelated; identify by queue name. The smoke runner cleans its own failure records and prints its error first. A `REFUSED` message means effective configuration is wrong; revisit steps 3 and 4. Do not use `queue:flush`, Redis `FLUSHALL`, Mailpit **Delete all**, or a worker for `default` as troubleshooting shortcuts.

11. **Clean only the messages you deliberately kept.** In Mailpit search for the exact printed marker from step 7, open one of its two messages and click **Delete** in the top toolbar; repeat for the other matching message. Search for the README subject from step 8 and delete only the message you just created (match its timestamp and recipient if older copies exist). Expect those previews to disappear while unrelated messages remain. The default smoke run already removes its own messages and queue data. Do not use the inbox's **Delete all** button.

12. **Return to the normal local stack.** No account, reservation, event, schema reset or queue worker cleanup is required after a successful smoke run. Leave the app running for development. To stop all project services while preserving MySQL data, run `docker compose down`; Mailpit preview storage is temporary and will be lost. Use `docker compose up -d` to start again. Do not add `--volumes` unless you deliberately intend to delete the project's MySQL data.
