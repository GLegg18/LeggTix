# Issue #9 review follow-up: morning acceptance checks

These checks cover login identity throttling, fresh booking state, stable race verification, CI and the pinned Redis extension. Event discovery, cancellation and waitlist promotion retain their separate tickets. This checklist complements the [full reservation walkthrough](reservation-manual-test-plan.md); issue #9 should remain open until its remaining acceptance work is agreed.

Use only this local checkout and development database. Docker Desktop must be running with Linux containers and Compose v2 or newer. Open PowerShell and keep the same window for steps 4-10: its variables contain the captured fixture IDs and private tokens. PHP, Composer, MySQL and Redis run in Docker; Windows does not need separate installations. Windows PowerShell is sufficient for this checklist. The first image build needs network access.

1. **Prepare the local app and confirm the rebuilt runtime.** Run from the repository root:

   ```powershell
   Set-Location C:\Users\georg\Documents\LeggTix
   docker compose version
   docker info --format '{{.OSType}}'
   $reviewNewEnvironment = -not (Test-Path -LiteralPath .env)
   if ($reviewNewEnvironment) { Copy-Item .env.example .env }
   docker compose build app
   docker compose run --rm --no-deps app composer install --no-interaction
   if ($reviewNewEnvironment) { docker compose run --rm --no-deps app php artisan key:generate }
   docker compose up -d
   docker compose logs -f app
   ```

   Expect `linux`. Wait for `Server running on [http://0.0.0.0:8000]`, then press **Ctrl+C** to leave log following. Containers remain running. Continue:

   ```powershell
   docker compose exec app php artisan migrate
   docker compose ps
   (Invoke-WebRequest -UseBasicParsing http://localhost:8000/up).StatusCode
   docker compose exec app php -r "echo phpversion('redis'), PHP_EOL;"
   ```

   Expect app/MySQL/Redis running, health `200`, and extension `6.3.0`. Migrations include the five domain tables, authentication tokens and failed-job storage. `up -d` recreates the app when the rebuilt image changes. Preserve an existing `.env` and key. No seeding, database reset or queue worker is required.

2. **Run the isolated MySQL suite and confirm cleanup.**

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   Expect passing MySQL tests and successful disposable-stack cleanup. Authentication covers equivalent known-account spellings sharing a budget, distinct accounts, invalid email without database lookups, unknown-account fallback and the overall IP limit. Booking includes a returned event whose counter and timestamp match fresh persisted state. All eight concurrency cases use independent PHP/MySQL processes; the cutoff case establishes the start time after the competitor is waiting at the lock. Slow process startup must not decide the result. This command does not migrate the normal application database. The optional local helper checks are documented in [testing.md](testing.md).

3. **Confirm the hosted workflow and merge protection separately.** After the changes have been pushed, open [GitHub Actions](https://github.com/GLegg18/LeggTix/actions), select **Tests**, and open the run for the exact commit being reviewed. Expect one green job: `MySQL acceptance`. Expand `Run isolated MySQL tests` and confirm the full suite passes. Expand `Clean up test stack` and confirm the temporary project is removed.

   A locally passing suite or valid workflow file does not establish hosted execution. If no run exists for the revised workflow, push the change and check its run. Require `MySQL acceptance` on the intended protected merge branch or ruleset, removing the old Windows wrapper requirements if configured, then verify a failing required status blocks merging. Record that as pending until configured; a workflow alone does not enforce the gate.

4. **Create tagged fixtures for the live requests and capture cleanup information.**

   ```powershell
   $reviewFixtureJson = docker compose exec -T app php scripts/support/verify-reservations.php create
   if ($LASTEXITCODE -ne 0) { throw 'Fixture creation failed; do not continue.' }
   $reviewFixture = $reviewFixtureJson | ConvertFrom-Json
   if ($reviewFixture.tag -notmatch '^[a-f0-9]{24}$') { throw 'Unexpected fixture tag.' }
   $reviewRecovery = Join-Path (Get-Location) "storage/app/private/issue9-live-$($reviewFixture.tag).json"
   New-Item -ItemType Directory -Force -Path (Split-Path $reviewRecovery) | Out-Null
   [pscustomobject]@{
       tag = $reviewFixture.tag
       events = $reviewFixture.events
       users = $reviewFixture.users
       type_id = $reviewFixture.type_id
   } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $reviewRecovery -Encoding UTF8
   $reviewFixture.events
   $reviewFixture.users
   $reviewActorEmail = "issue9.actor.$($reviewFixture.tag)@example.test"
   $reviewActorAlias = 'issue9.{0}ctor.{1}@example.test' -f [char]0x00E1, $reviewFixture.tag
   $reviewOtherEmail = "issue9.other.$($reviewFixture.tag)@example.test"
   $reviewReservationUrl = "http://localhost:8000/api/events/$($reviewFixture.events.available)/reservations"
   ```

   Expect five new event IDs and four new account IDs. The `available` event has one free place; draft/past events cannot be booked; the waiting event has one waiter. `actor` and its accented alias must refer to the same account. Factory passwords are `password`. Fixtures require `APP_ENV=local`, a Compose MySQL host and Redis cache. Recovery metadata contains IDs and the tag, with no token. Do not print `$reviewFixtureJson` or the complete fixture object because they contain private bearer tokens.

5. **Define a UTF-8 HTTP helper that handles both success and error statuses.**

   ```powershell
   Add-Type -AssemblyName System.Net.Http
   $reviewClient = [System.Net.Http.HttpClient]::new()
   $reviewClient.Timeout = [TimeSpan]::FromSeconds(45)
   function Invoke-ReviewRequest {
       param([string] $Url, [string] $Body = '{}', [string] $Token = '')
       $request = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Post, $Url)
       $response = $null
       try {
           if ($Token -ne '') { $request.Headers.Authorization = [System.Net.Http.Headers.AuthenticationHeaderValue]::new('Bearer', $Token) }
           $request.Content = [System.Net.Http.StringContent]::new($Body, [System.Text.Encoding]::UTF8, 'application/json')
           $response = $reviewClient.SendAsync($request).GetAwaiter().GetResult()
           $retry = if ($null -ne $response.Headers.RetryAfter) { $response.Headers.RetryAfter.ToString() } else { '' }
           [pscustomobject]@{
               Status = [int]$response.StatusCode
               Body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
               RetryAfter = $retry
           }
       } finally {
           if ($null -ne $response) { $response.Dispose() }
           $request.Dispose()
       }
   }
   ```

   This defines the helper and makes no request. Capture successful login responses in variables; their bodies contain bearer tokens.

6. **Check real Redis throttling across equivalent MySQL email spellings.** Run this block once on fresh step-4 fixtures:

   ```powershell
   $reviewLoginUrl = 'http://localhost:8000/api/login'
   foreach ($reviewEmail in @($reviewActorEmail, $reviewActorAlias, $reviewActorEmail.ToUpperInvariant(), "  $reviewActorAlias  ", $reviewActorAlias)) {
       Invoke-ReviewRequest -Url $reviewLoginUrl -Body (@{ email = $reviewEmail; password = 'wrong-password-for-review' } | ConvertTo-Json -Compress)
   }
   Invoke-ReviewRequest -Url $reviewLoginUrl -Body (@{ email = $reviewActorEmail; password = 'password' } | ConvertTo-Json -Compress)
   Invoke-ReviewRequest -Url $reviewLoginUrl -Body (@{ email = $reviewActorAlias; password = 'password' } | ConvertTo-Json -Compress)
   $reviewOtherResponse = Invoke-ReviewRequest -Url $reviewLoginUrl -Body (@{ email = $reviewOtherEmail; password = 'password' } | ConvertTo-Json -Compress)
   $reviewOtherResponse.Status
   $reviewOtherLogin = $reviewOtherResponse.Body | ConvertFrom-Json
   $reviewOtherLogin.user
   Invoke-ReviewRequest -Url $reviewLoginUrl -Body (@{ email = @('invalid'); password = 'password' } | ConvertTo-Json -Compress)
   ```

   Expect five `422` responses with `errors.email`; both subsequent actor spellings return `429` with nonempty `RetryAfter`, even with a correct password. The distinct other account returns `200` with its own fixture ID and role `regular_user`. Array email returns `422`; no account/token is created. If unrelated requests already consumed localhost's 30/minute IP limit, wait for the reported `RetryAfter` and repeat with fresh fixtures. Do not clear shared Redis keys.

   Wait at least 61 seconds before the next login (use a clock), then run:

   ```powershell
   $reviewActorResponse = Invoke-ReviewRequest -Url $reviewLoginUrl -Body (@{ email = "  $($reviewActorAlias.ToUpperInvariant())  "; password = 'password' } | ConvertTo-Json -Compress)
   $reviewActorResponse.Status
   $reviewActorLogin = $reviewActorResponse.Body | ConvertFrom-Json
   $reviewActorLogin.user
   ```

   Expect `200`, the original `actor` ID, and the canonical unaccented fixture email. Both successful logins create independent tokens; cleanup revokes them. Account-wide limits across multiple IPs remain public-launch hardening, not evidence from this single-IP check.

7. **Check the interactive documentation, authentication boundary and request validation.** Open [Swagger](http://localhost:8000/docs/api), allow several seconds for schema generation, and open the browser developer tools (**F12**, then **Console** and **Network**). Confirm the page has no relevant JavaScript error or failed local asset request; `/docs/api.json` returns `200`.

   Before booking, run:

   ```powershell
   Invoke-ReviewRequest -Url $reviewReservationUrl
   Invoke-ReviewRequest -Url $reviewReservationUrl -Token $reviewActorLogin.access_token -Body (@{ user_id = $reviewFixture.users.other } | ConvertTo-Json -Compress)
   ```

   Expect `401 Unauthenticated.` and `422` rejecting the supplied field; available occupancy stays zero. In Swagger, click **Authorize**, paste the raw actor token, click **Authorize**, then **Close**. You can copy it without logging it with `Set-Clipboard -Value $reviewActorLogin.access_token`. Swagger supplies `Bearer`; do not add that prefix yourself.

   Expand `POST /api/events/{event}/reservations`, click **Try it out**, enter the numeric `available` ID from step 4 in `event`, send `{}` in the body if shown, and click **Execute**. Expect `201`, confirmed status, the actor/event IDs, null `cancelled_at`, UTC dates and only the seven documented reservation fields. Passwords, account email and generated keys must be absent. Click **Execute** again: expect `409`, `code: already_reserved`. Confirm these statuses in **Network** as well as the rendered response. Refresh the page, call `GET /api/user` through **Try it out** and **Execute** without authorizing again: expect `401`; page refresh must forget the token.

8. **Check full inventory, ineligible events and committed state.** After the Swagger booking:

   ```powershell
   Invoke-ReviewRequest -Url $reviewReservationUrl -Token $reviewOtherLogin.access_token
   Invoke-ReviewRequest -Url "http://localhost:8000/api/events/$($reviewFixture.events.draft)/reservations" -Token $reviewActorLogin.access_token
   Invoke-ReviewRequest -Url "http://localhost:8000/api/events/$($reviewFixture.events.past)/reservations" -Token $reviewActorLogin.access_token
   Invoke-ReviewRequest -Url "http://localhost:8000/api/events/$($reviewFixture.events.waiting)/reservations" -Token $reviewActorLogin.access_token
   Invoke-ReviewRequest -Url 'http://localhost:8000/api/events/999999999999/reservations' -Token $reviewActorLogin.access_token
   $reviewFixtureJson | docker compose exec -T app php scripts/support/verify-reservations.php inspect
   ```

   Expect `409 full`, two `409 event_not_bookable`, `409 queue_has_priority`, then `404`. The inspection emits exactly five fixture events: `available` has capacity/count/confirmed rows `1/1/1` and only the actor holder; all other counters and confirmed-row counts stay zero; only `waiting` has one waiter. Replay and competing requests must not change the original reservation or silently join the waitlist. Use step 2's MySQL race suite for simultaneous last-place and same-actor attempts; sequential full/replay checks alone do not prove concurrency safety.

9. **Run the complete automated live walkthrough and inspect diagnostics.** Either before creating the manual fixtures or after step 10 cleans them up, run:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify-reservations.ps1
   docker compose logs --tail 80 app
   docker compose logs --tail 80 mysql
   docker compose logs --tail 80 redis
   docker compose exec app php artisan queue:failed
   ```

   Expect `PASS Redis-login-throttle`, HTTP/authentication/payload/real-multipart checks, booking/replay/capacity/FIFO checks, Redis actor throttling, persisted invariants, and all-zero fixture cleanup; exit code `0`. The runner creates and removes its own separate tagged fixtures. Localhost's IP limits are shared, so allow a fresh minute after heavy manual testing. Logs must not show an unexpected exception during these requests. Expect `queue:failed` to report `No failed jobs found.` on a clean setup. A missing-table exception requires applying pending migrations with `docker compose exec app php artisan migrate`, then repeating the check. Existing failed jobs should be inspected separately. Booking dispatches no queue job and needs no queue worker. If the live runner reports cleanup failure, copy its printed recovery command exactly.

10. **Remove only the manual walkthrough's fixtures and tokens.** From the same repository-root PowerShell window:

    ```powershell
    Get-Content -LiteralPath $reviewRecovery -Raw | docker compose exec -T app php scripts/support/verify-reservations.php cleanup
    if ($LASTEXITCODE -ne 0) { throw 'Cleanup failed; keep the recovery file and retry the same command.' }
    Remove-Item -LiteralPath $reviewRecovery
    $reviewClient.Dispose()
    Set-Clipboard -Value ''
    Remove-Variable reviewFixture,reviewFixtureJson,reviewActorResponse,reviewActorLogin,reviewOtherResponse,reviewOtherLogin -ErrorAction SilentlyContinue
    ```

    Expect every cleanup count zero: reservations, waitlist entries, events, tokens, users and type. The helper validates the tag and captured identities, locks fixture events, and leaves unrelated data and Redis counters intact. If PowerShell closes before cleanup, reopen it at the repository root, find this run's `storage/app/private/issue9-live-<tag>.json`, and substitute that exact full path for `$reviewRecovery` in the first command. Keep recovery metadata until zero counts are confirmed. Stop development services if wanted with `docker compose down`; this preserves the MySQL volume. Do not use `--volumes` or `migrate:fresh` on this development stack.
