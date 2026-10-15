# Public event API acceptance plan — issue #7

This plan covers anonymous discovery, safe fields, published-only visibility, pagination, validation and read-only inventory. The execution record is in [issue-7-validation.md](issue-7-validation.md). A plan step is not a claim that it was executed.

Use Windows PowerShell in `C:\Users\georg\Documents\LeggTix`. Keep the same window open so variables remain available. Install/start Docker Desktop with the WSL 2 backend, Linux containers and Compose v2 or newer. The first build requires network access; PHP, Composer, MySQL and Redis run inside containers. Use only your local development checkout and database. This walkthrough preserves existing data; it does not require a database reset.

1. **Prepare and start the local stack.** Open PowerShell and paste:

   ```powershell
   Set-Location C:\Users\georg\Documents\LeggTix
   docker compose version
   docker info --format '{{.OSType}}'
   $issue7NewEnvironment = -not (Test-Path -LiteralPath .env)
   if ($issue7NewEnvironment) { Copy-Item .env.example .env }
   docker compose build app worker
   docker compose run --rm --no-deps app composer install --no-interaction
   if ($issue7NewEnvironment) { docker compose run --rm --no-deps app php artisan key:generate }
   docker compose up -d
   docker compose logs -f app
   ```

   Expect a Compose version, `linux`, then `Server running on [http://0.0.0.0:8000]`. Press **Ctrl+C** after the server starts; this stops log-following and leaves the containers running. Preserve an existing `.env` and application key. The app needs `APP_ENV=local` and the repository's local MySQL/Redis/Mailpit settings from `.env.example`. If ports 8000, 3306, 6379 or 8025 are occupied, resolve the local conflict before continuing.

2. **Install missing migrations and demo records.** These commands add missing local schema/sample data; they preserve existing records:

   ```powershell
   docker compose exec app php artisan migrate --seed
   docker compose ps
   docker compose exec app php artisan migrate:status
   (Invoke-WebRequest -UseBasicParsing http://localhost:8000/up).StatusCode
   docker compose exec app php artisan route:list --path=api/events
   ```

   Expect running/healthy containers, migrations marked `Ran`, health `200`, and `GET|HEAD api/events`, `GET|HEAD api/events/{event}` plus the existing reservation POST route. There is no customer event screen in this milestone. No account/token is needed to browse. The queue worker/Mailpit are needed only for the optional authenticated checks in step 10.

3. **Run the isolated automated checks.** These commands create and remove their own Docker/MySQL test project; they do not migrate/reset your normal database:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 '--filter=EventApiTest|PublicEventBoundaryTest|ApiDocumentationTest' --display-warnings --fail-on-warning
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   Expect all tests passing, no warnings, exit code 0 and temporary project cleanup. The public API tests include microsecond/tied-date cursor round trips, exact start-time cutoff, nullable ends, retired event types, empty lists, forged cursor filtering, owner/admin boundaries and unchanged rows/queue state after repeated reads. The full suite also runs real competing MySQL booking transactions and rollback/retry cases; capacity must never exceed the event limit and the same customer cannot consume two places. Do not substitute SQLite or run tests through the normal app container. If cleanup fails, run only the exact temporary-project recovery command printed by the runner; see [testing.md](testing.md).

4. **Read local fixture IDs and take an inventory snapshot.** Paste this block; it performs only database reads:

   ```powershell
   $issue7DemoJson = docker compose exec -T app php artisan tinker --execute="echo App\Models\Event::query()->where('name', 'like', 'Demo:%')->orderBy('id')->get(['id','name','status','starts_at','capacity','confirmed_count'])->toJson();"
   if ($LASTEXITCODE -ne 0) { throw 'Could not read local demo fixtures.' }
   $issue7Demo = $issue7DemoJson | ConvertFrom-Json
   $issue7Demo | Format-Table id,name,status,starts_at,capacity,confirmed_count
   $issue7InventoryBefore = docker compose exec -T app php artisan tinker --execute="echo json_encode(['events' => App\Models\Event::query()->orderBy('id')->get(['id','capacity','confirmed_count','updated_at'])->toArray(), 'reservations' => App\Models\Reservation::count(), 'waitlist' => Illuminate\Support\Facades\DB::table('waitlist_entries')->count()]);"
   ```

   A fresh seed contains eight demo events. `Demo: Community Coffee Meetup` is draft; `Demo: Garden Session` cancelled; `Demo: Intro to Web APIs` completed; `Demo: Last Week's Community Meetup` past/published. `Demo: Studio Live Showcase` is published and full (capacity/count 2). Existing checkouts may have edited/aged fixtures: seeding deliberately preserves them. Use the displayed current statuses/times when interpreting results; do not reset existing data to force a particular demo count. The automated tests provide fresh fixtures for every boundary regardless of demo age. Avoid other booking/edit traffic until the snapshot comparison in step 11.

5. **Browse anonymously and inspect the exact public fields.** Do not log in or supply an Authorization header:

   ```powershell
   curl.exe --silent --show-error --include http://localhost:8000/api/events
   $issue7List = Invoke-RestMethod http://localhost:8000/api/events
   $issue7List.data | Format-Table id,name,status,starts_at,capacity,remaining_capacity
   $issue7List.meta
   ```

   Expect HTTP `200`, JSON top-level `data`, `links`, `meta`, and `Cache-Control: no-store, private`. Default `meta.per_page` is 20. Every listed event must be `published` with a start strictly later than current UTC time. Full future events remain visible. Each event has exactly `id`, `name`, `description`, `venue`, `starts_at`, `ends_at`, `timezone`, `capacity`, `remaining_capacity`, `status`, `event_type`. The nested type has exactly `id`, `slug`, `name`. No owner ID, owner/contact details, attendees, reservations, waitlist records, raw occupancy counter, internal cursor alias or timestamps are returned. Schedule strings are UTC with six fractional digits and `Z`; `ends_at` may be null. `timezone` remains the display zone. An empty `data: []` is valid if no future published events exist.

   For the browser explorer check, open [http://localhost:8000/docs/api](http://localhost:8000/docs/api), wait for the schema to load, and expand the **Events** section. Leave **Authorize** unused (if already authorized, use its **Logout** button). Expand **GET /api/events**, click **Try it out**, enter `2` in `per_page`, leave `cursor` empty, then click **Execute**. Expect server response `200`, at most two events, and the fields/envelope above. Copy a published event ID from that response. Expand **GET /api/events/{event}**, click **Try it out**, enter the ID in `event`, and click **Execute**: expect `200` and the same public fields. Replace `event` with the draft fixture ID from step 4 and execute again: expect the documented `404` JSON with no event information. In the browser's developer tools (**F12**), check **Console** for JavaScript errors, then **Network**, filter `events`, and inspect each request's status/response and the successful response's `Cache-Control` header. Expect no explorer JavaScript errors and the same `200`/`404` statuses. These GET operations do not create a booking.

6. **Follow pagination forward and backward.** With at least three future published events, paste:

   ```powershell
   $issue7First = Invoke-RestMethod 'http://localhost:8000/api/events?per_page=2'
   $issue7First.data | Format-Table id,starts_at
   $issue7Second = Invoke-RestMethod $issue7First.links.next
   $issue7Second.data | Format-Table id,starts_at
   $issue7Back = Invoke-RestMethod $issue7Second.links.prev
   (@($issue7First.data.id) -join ',') -eq (@($issue7Back.data.id) -join ',')
   ```

   Expect two rows in the first page, the next eligible rows in the second, and `True` for the backward comparison while records/time eligibility are unchanged. Pages sort by start time, then ID ascending for equal starts. First `links.prev` and last `links.next` are null. Copy returned links/cursors exactly; do not construct an offset `page` parameter. If fewer than three events qualify, inspect null next/previous links and use step 3's fresh-fixture pagination test. Pagination is a live view: publication/schedule changes between requests can change page contents.

7. **Open a published detail and check full/past behavior.** Copy a future published ID from step 5:

   ```powershell
   $issue7PublicId = Read-Host 'Paste a published event ID from the table'
   curl.exe --silent --show-error --include "http://localhost:8000/api/events/$issue7PublicId"
   $issue7Past = $issue7Demo | Where-Object name -EQ "Demo: Last Week's Community Meetup"
   curl.exe --silent --show-error --include "http://localhost:8000/api/events/$($issue7Past.id)"
   $issue7Full = $issue7Demo | Where-Object name -EQ 'Demo: Studio Live Showcase'
   curl.exe --silent --show-error --include "http://localhost:8000/api/events/$($issue7Full.id)"
   ```

   Expect published detail `200` with one `data` object using the same exact fields as the list and `no-store, private`. A still-published past event returns `200` by ID but is absent from the list. A still-published full event returns `200` and `remaining_capacity: 0`; it also appears in the list if its start is still in the future. Check `remaining_capacity = capacity - confirmed_count` against step 4's database table. This number is advisory: viewing does not hold a place; allocation still rechecks the locked MySQL event during a separate reservation request.

8. **Check hidden and missing resources.** Paste:

   ```powershell
   foreach ($issue7Hidden in @($issue7Demo | Where-Object status -NE 'published')) {
       Write-Output "$($issue7Hidden.name) [$($issue7Hidden.status)]"
       curl.exe --silent --show-error --include "http://localhost:8000/api/events/$($issue7Hidden.id)"
   }
   curl.exe --silent --show-error --include http://localhost:8000/api/events/18446744073709551615
   curl.exe --silent --show-error --include http://localhost:8000/api/events/not-an-event
   ```

   Expect JSON HTTP `404` for each draft/cancelled/completed event, missing numeric ID and nonnumeric route. Hidden and missing numeric IDs return the same generic JSON message with no event fields. These requests omit an explicit Accept header; errors must still be JSON. None of the hidden IDs may appear in any list page.

9. **Try invalid page sizes, cursors and filters.** Paste:

   ```powershell
   foreach ($issue7Query in @('per_page=0','per_page=101','per_page=all','per_page%5B%5D=2','cursor=e30','cursor=%25%25%25','cursor%5B%5D=abc','status=draft','page=2')) {
       Write-Output $issue7Query
       curl.exe --silent --show-error --include "http://localhost:8000/api/events?$issue7Query"
   }
   curl.exe --silent --show-error --include 'http://localhost:8000/api/events?per_page=100'
   ```

   Expect JSON HTTP `422` for every first-loop request, with `errors` naming the offending field (`per_page`, `cursor`, `status` or `page`). `per_page=100` returns `200` and at most 100 rows. This endpoint accepts only `per_page` (integer 1–100) and the returned opaque `cursor`; it does not support client-selected statuses or offset page numbers. Validation must not expose SQL/framework traces or modify inventory.

10. **Check that owner/admin login does not reveal hidden details through public routes.** This optional live check uses existing local demo credentials; step 3 always covers these permission boundaries automatically. Open [Mailpit](http://localhost:8025) in a browser. For each of `demo.owner@example.test` and `demo.admin@example.test`, run the block, select the newest email addressed to that account in Mailpit's left message list, and copy its six-digit login code when prompted:

    ```powershell
    $issue7Email = Read-Host 'Enter demo.owner@example.test or demo.admin@example.test'
    $issue7LoginStart = Invoke-RestMethod -Method Post http://localhost:8000/api/login -ContentType 'application/json' -Body (@{email=$issue7Email; password='demo-password'} | ConvertTo-Json)
    $issue7Code = Read-Host 'Paste the six-digit login code from the newest Mailpit message'
    $issue7Login = Invoke-RestMethod -Method Post "http://localhost:8000/api/login/email-verifications/$($issue7LoginStart.challenge_id)/verify" -ContentType 'application/json' -Body (@{code=$issue7Code; remember_device=$false} | ConvertTo-Json)
    $issue7Headers = @{Authorization='Bearer '+$issue7Login.access_token}
    (Invoke-RestMethod http://localhost:8000/api/user -Headers $issue7Headers).data | Select-Object id,email,role
    foreach ($issue7Hidden in @($issue7Demo | Where-Object status -NE 'published')) {
        try {
            (Invoke-WebRequest -UseBasicParsing "http://localhost:8000/api/events/$($issue7Hidden.id)" -Headers $issue7Headers).StatusCode
        } catch {
            if ($null -eq $_.Exception.Response) { throw }
            [int]$_.Exception.Response.StatusCode
            $_.ErrorDetails.Message
        }
    }
    Invoke-RestMethod -Method Post http://localhost:8000/api/logout -Headers $issue7Headers | Out-Null
    Remove-Variable issue7Login,issue7Headers,issue7Code
    ```

    Expect login start `202`, successful verification, the selected identity/role (`organiser` or `admin`), then `404` for each hidden event even for its owner. Public routes do not become private management endpoints after login. Logout returns `204` and revokes this temporary token. If demo passwords were changed, use their current local passwords; the seeder deliberately does not replace them. If mail is absent, inspect the worker/Mailpit logs in step 12; public reads themselves need no email/queue processing.

11. **Repeat reads and confirm they did not consume inventory.** Run steps 5 and 7 again, then paste:

    ```powershell
    $issue7InventoryAfter = docker compose exec -T app php artisan tinker --execute="echo json_encode(['events' => App\Models\Event::query()->orderBy('id')->get(['id','capacity','confirmed_count','updated_at'])->toArray(), 'reservations' => App\Models\Reservation::count(), 'waitlist' => Illuminate\Support\Facades\DB::table('waitlist_entries')->count()]);"
    $issue7InventoryBefore -ceq $issue7InventoryAfter
    ```

    Expect `True`: capacities, counters/event timestamps and reservation/waitlist counts are unchanged. The deterministic automated boundary test compares complete event/reservation/waitlist rows and asserts no queue dispatch after successful, invalid and missing reads. It also verifies that public reads immediately show zero remaining capacity after a real successful booking. Use the isolated full suite for competing bookings/replay/rollback; public GET requests do not allocate inventory.

12. **Inspect failures and finish without deleting records.** If a request failed unexpectedly, preserve its exact URL/status/body, then run:

    ```powershell
    docker compose logs --tail 60 app
    docker compose logs --tail 60 mysql
    docker compose logs --tail 60 worker
    docker compose logs --tail 60 mailpit
    docker compose exec app php artisan queue:failed
    ```

    Expect no new exceptions or failed jobs caused by browsing. Login email requests in step 10 create real local queued emails; Mailpit captures them. Public GET requests queue nothing. Steps 4–9 and 11 create no data needing cleanup. The normal demo seed remains available; logout in step 10 removes each temporary login token. The isolated test runner removes its own containers/database/image. To stop the normal services while preserving local MySQL data, run `docker compose down`. Do not use `migrate:fresh` or `docker compose down --volumes` for this plan.
