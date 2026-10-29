# Event management manual acceptance — issue #8

These steps create dedicated local review events. Do not cancel seeded events or run the destructive PHPUnit suite in the normal application container. Completed/cancelled event and attempt rows are retained intentionally.

## 1. Prepare the local application

1. Open Docker Desktop, select Linux containers, and wait until its engine is running. Open PowerShell in `C:\Users\georg\Documents\LeggTix`:

   ```powershell
   Set-Location C:\Users\georg\Documents\LeggTix
   docker compose version
   docker info --format '{{.OSType}}'
   ```

   Expect a Compose version and `linux`. For a first checkout, perform the root README's **First run** instructions first; they prepare `.env`, the application key, images and Composer dependencies. Preserve an existing `.env`, key and database.

2. Start the stack and apply the schema/demo seed:

   ```powershell
   docker compose up -d
   docker compose exec app php artisan migrate --seed
   docker compose restart worker
   docker compose ps --all
   ```

   Expect healthy `app`, `frontend`, `mysql`, `redis`, `mailpit`, and a running `worker`. If image/dependency configuration changed, use the root README's stop-worker/recreate sequence before this step. Never run `migrate:fresh` here. Demo seeding is create-only; previously changed credentials are preserved.

3. Open `http://localhost:8000/up` (healthy response), `http://localhost:3000` (LeggTix frontend), `http://localhost:8025` (Mailpit inbox), and `http://localhost:8000/docs/api` (Swagger). Use `localhost` consistently so cookies remain on one host.

## 2. Check the organiser workspace

4. Open `http://localhost:3000/login`. Enter `demo.owner@example.test` and `demo-password`; request sign-in. In Mailpit, open the newest login-code email for that exact recipient, copy the six digits, and submit the code on the login screen. Expect a signed-in account with organiser access. The password step alone must not grant a session.

5. Follow **Manage events**, or open `http://localhost:3000/manage/events`. Expect the organiser's events, including private drafts, with status and occupancy. If there are more than one page, use the pagination controls and return to the previous page; no unrelated owner's events should appear. Repeat at a narrow/mobile browser width and with dark mode enabled; all form controls and action buttons should remain reachable without horizontal page scrolling.

6. Open `http://localhost:3000/manage/events/new`. Set **Event name** to `Issue 8 UI review`, choose an active **Event type**, enter **Description** and **Venue**, **Capacity** `2`, **Start instant (UTC or offset)** to a future UTC instant such as `2027-12-01T18:00:00Z`, and **Display timezone** to `Europe/London`. Leave **End instant (optional)** empty or supply a later explicit instant. Click **Create draft**. Expect a private detail page under `/manage/events/{id}`, draft status, zero confirmed reservations and capacity two. Note its numeric ID. Public `http://localhost:8000/api/events/{id}` must return `404` while it is draft.

7. Edit the event title/venue and click **Save changes**. Expect the committed values after reloading the page. Try capacity `0` and a schedule without `Z`/offset; expect a browser validation message or announced field error and no saved invalid values. Restore the valid values. Click **Publish event**. Expect `published`; the public event URL now returns its public fields. Try to change it back to draft through the API in step 15; Laravel must refuse.

8. Scroll to **Reservations** and **Waitlist history** on the event page. A new event should show explicit empty states. **Previous records**/**Next records** should be disabled when no further page exists. No account email, password, token or internal generated key should be displayed. Click **Cancel event**; confirmation must name the selected event and describe the impact on active reservations/waiters. Click **Keep event** once to dismiss it without changes, then **Cancel event** and **Confirm cancellation**. Reload; expect `cancelled`, zero confirmed occupancy and no publish/reopen control. Public detail becomes `404`. Name/description/venue corrections remain available on closed events.

9. Sign out. Sign in as `demo.user@example.test` with the same seeded password and a fresh Mailpit code. The management link must be absent; direct `http://localhost:3000/manage/events` must show a clear access-denied state without private records. As a guest, the same URL must offer sign-in. Sign in as `demo.admin@example.test` to inspect organiser-owned events; admin management must not silently reassign their owner.

## 3. Make concrete API requests

10. In the same PowerShell window, define this login helper. It uses the real password/email-factor workflow and keeps tokens only in memory:

    ```powershell
    function Get-ReviewToken([string] $Email) {
        $start = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/login' -ContentType 'application/json' -Body (@{
            email = $Email; password = 'demo-password'
        } | ConvertTo-Json)
        $code = Read-Host ('Enter the newest six-digit Mailpit login code for ' + $Email)
        $login = Invoke-RestMethod -Method Post -Uri ('http://localhost:8000/api/login/email-verifications/' + $start.challenge_id + '/verify') -ContentType 'application/json' -Body (@{
            code = $code; remember_device = $false
        } | ConvertTo-Json)
        return $login.access_token
    }
    $reviewOwnerToken = Get-ReviewToken 'demo.owner@example.test'
    $reviewCustomerToken = Get-ReviewToken 'demo.user@example.test'
    $reviewOwnerHeaders = @{ Authorization = 'Bearer ' + $reviewOwnerToken; Accept = 'application/json' }
    $reviewCustomerHeaders = @{ Authorization = 'Bearer ' + $reviewCustomerToken; Accept = 'application/json' }
    ```

    Each password submission returns a challenge; complete the corresponding Mailpit code before requesting another login for that account. Do not paste tokens into issue comments or screenshots.

11. Read the active choices and create a dedicated draft:

    ```powershell
    $reviewTypes = Invoke-RestMethod -Uri 'http://localhost:8000/api/manage/event-types' -Headers $reviewOwnerHeaders
    $reviewTypes.data | Select-Object id, slug, name
    $reviewBody = @{
        event_type_id = [long]$reviewTypes.data[0].id
        name = 'Issue 8 API review'
        description = 'Dedicated local acceptance event.'
        venue = 'Review room'
        starts_at = [DateTime]::UtcNow.AddDays(30).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'")
        ends_at = $null
        timezone = 'Europe/London'
        capacity = 2
    }
    $reviewCreated = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/manage/events' -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body ($reviewBody | ConvertTo-Json)
    $reviewId = [long]$reviewCreated.data.id
    $reviewUrl = 'http://localhost:8000/api/manage/events/' + $reviewId
    $reviewCreated.data | Select-Object id, owner_id, status, capacity, confirmed_count
    ```

    Expect `201`, status `draft`, owner equal to the signed-in organiser, capacity two, confirmed zero. The type response contains only `id`, `slug`, `name`. Draft/public detail returns `404`; owner private detail returns `200` and `Cache-Control: no-store, private`.

12. Publish, reserve as the customer, and inspect the private history:

    ```powershell
    Invoke-RestMethod -Method Patch -Uri $reviewUrl -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body '{"status":"published"}' | Out-Null
    $reviewBooking = Invoke-RestMethod -Method Post -Uri ('http://localhost:8000/api/events/' + $reviewId + '/reservations') -Headers $reviewCustomerHeaders -ContentType 'application/json' -Body '{}'
    Invoke-RestMethod -Uri ($reviewUrl + '/reservations?per_page=1') -Headers $reviewOwnerHeaders | ConvertTo-Json -Depth 6
    Invoke-RestMethod -Uri ($reviewUrl + '/waitlist?per_page=1') -Headers $reviewOwnerHeaders | ConvertTo-Json -Depth 6
    ```

    Expect `200` publication, `201` reservation, one confirmed attempt with `user:{id,name}` and no email/credentials/generated active key; waitlist `data:[]`. Private detail has confirmed count one. If a list returns `links.next`, request that exact URL with the owner headers; the next page must preserve order and avoid duplicates.

13. For expected failures below, PowerShell prints an HTTP exception. Inspect its response status; these failures must not alter the committed event:

    ```powershell
    # Customer cannot edit or inspect an organiser's private list: expect 403.
    Invoke-RestMethod -Method Patch -Uri $reviewUrl -Headers $reviewCustomerHeaders -ContentType 'application/json' -Body '{"name":"Forbidden edit"}'
    Invoke-RestMethod -Uri ($reviewUrl + '/reservations') -Headers $reviewCustomerHeaders
    # No credentials: expect 401.
    Invoke-RestMethod -Uri $reviewUrl
    # Explicit writable-field boundary: expect 422 for owner_id.
    Invoke-RestMethod -Method Patch -Uri $reviewUrl -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body '{"owner_id":999999}'
    # JSON integer required: expect 422 for capacity.
    Invoke-RestMethod -Method Patch -Uri $reviewUrl -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body '{"capacity":"2"}'
    # Any history freezes schedule/type/timezone: expect 422 for starts_at.
    $reviewNewSchedule = @{ starts_at = [DateTime]::UtcNow.AddDays(31).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'") } | ConvertTo-Json
    Invoke-RestMethod -Method Patch -Uri $reviewUrl -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body $reviewNewSchedule
    ```

    Read the event again using `$reviewOwnerHeaders`; title, owner and schedule must retain their previous values. An ordinary metadata correction such as `{"venue":"Review room B"}` is allowed after booking.

14. Cancellation and replay:

    ```powershell
    Invoke-RestMethod -Method Delete -Uri $reviewUrl -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body '{}' | Out-Null
    $reviewAfterCancel = Invoke-RestMethod -Uri ($reviewUrl + '/reservations') -Headers $reviewOwnerHeaders
    $reviewAfterCancel.data | Select-Object id, status, cancelled_at
    Invoke-RestMethod -Method Delete -Uri $reviewUrl -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body '{}' | Out-Null
    Invoke-RestMethod -Uri $reviewUrl -Headers $reviewOwnerHeaders | ConvertTo-Json -Depth 4
    ```

    Expect `200` for both cancellations, the same reservation ID retained as `cancelled`, a non-null cancellation time unchanged by replay, event `cancelled`, confirmed count zero. No new reservation is created and no waiter is promoted by event cancellation.

15. Submit `{"status":"published"}` to the cancelled event and expect `422` (terminal events cannot reopen). Repeat the customer reservation request for it and expect `409` with `event_not_bookable`. On a still-published dedicated event, `{"status":"draft"}` must also return `422`. Do not cancel or edit a seeded event merely to run this check.

## 4. Verify concurrency and delivery in the isolated runner

16. Run the independent acceptance/race suite, from the repository root:

    ```powershell
    powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter='EventManagementAcceptanceTest|EventManagementConcurrencyTest' --display-warnings --fail-on-warning
    ```

    Expect every test to pass and the temporary `leggtix-test-...` stack to be removed. The race tests prove: a capacity reduction sees a booking committed while it waited; schedule edits see new attempt history despite an older repeatable-read snapshot; pending bookings reject a committed event cancellation; FIFO waiters receive newly added capacity before pending newcomers. Each checks counter equality with committed confirmed rows and the capacity bound. These use independent PHP/MySQL processes, not SQLite or normal development data.

17. Run the full isolated suite:

    ```powershell
    powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
    ```

    Expect no failures/warnings and successful cleanup. Promotion/batch tests also cover bounded FIFO work, safe replay/resume and stale notification suppression. No public join/leave waitlist endpoint is supplied by issue #8; do not invent SQL in the development database to emulate one.

18. Open Mailpit and find the customer's confirmation email for `Issue 8 API review`; its presence depends on the worker consuming the queued booking before cancellation. A queued stale confirmation may be deliberately suppressed after cancellation. Event-wide cancellation itself does not promise cancellation email delivery. For errors inspect:

    ```powershell
    docker compose logs --timestamps --tail=100 app worker frontend
    docker compose exec app php artisan queue:failed
    docker compose exec app php artisan waitlist:promote $reviewId
    ```

    The last command is the explicit resumable promotion command. Against this cancelled dedicated event it must report zero promotions and leave inventory closed. On a published event with waiting backlog/spare capacity it processes one bounded batch and queues continuation when necessary; see `event-management.md`. Queue/log failures must not silently consume or create a second place. Keep failure messages and timestamps without publishing tokens/codes.

## 5. Cleanup

19. Leave both dedicated review events cancelled; retained cancelled attempt history is the intended state. Sign out through the UI. Revoke the two temporary bearer tokens and remove in-memory variables:

    ```powershell
    Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/logout' -Headers $reviewOwnerHeaders -ContentType 'application/json' -Body '{}' | Out-Null
    Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/logout' -Headers $reviewCustomerHeaders -ContentType 'application/json' -Body '{}' | Out-Null
    Remove-Variable reviewOwnerToken, reviewCustomerToken, reviewOwnerHeaders, reviewCustomerHeaders, reviewBooking -ErrorAction SilentlyContinue
    ```

    Expect `204` logout. Do not drop tables, delete shared volumes, flush Redis or delete all Mailpit messages. If the isolated runner reports cleanup failure, use only its exact printed project-specific cleanup command. `docker compose stop` may be used when you want to stop the normal local services while retaining their data.
