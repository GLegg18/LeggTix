# Reservation acceptance checks for issue #9

Use a local checkout in `C:\Users\georg\Documents\LeggTix`, PowerShell, and Docker Desktop running Linux containers with Compose v2. The first image build needs network access. PHP, Composer, MySQL and Redis do not need to be installed on Windows. This milestone has an API endpoint; no reservation screen, cancellation endpoint or queue worker is required.

The automated tests use disposable MySQL 8.4. The live walkthrough creates its own accounts and events in the local development database, then deletes only those fixtures. Keep the same PowerShell window open so its variables remain available. Do not use a deployed site or production database.

For an automatic live check, first complete step 1, then run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify-reservations.ps1
```

Expect `PASS` lines for HTTP/authentication/payload/real multipart checks, exact persisted capacity and holders, Redis's 30-request actor limit, equivalent-email login throttling, and cleanup. The login checks share five failed attempts across canonical/accented/case/trim variants, then require `429` with `Retry-After` for either spelling while another account remains independent. This script requires the existing local Compose MySQL host and Redis cache. It creates five tagged events and four dedicated accounts, keeps tokens in memory, and removes their records in `finally` after acquiring event locks in ascending order. It does not clear shared rate limits, reset the normal database or stop services. Temporary per-user throttle keys expire naturally. Localhost shares the IP limit; if unrelated requests already consumed it, wait for `Retry-After` and rerun. Token-free fixture IDs are saved under ignored `storage/app/private/issue9-live-<tag>.json` until cleanup succeeds; a failed cleanup prints the exact recovery command. The [review follow-up checklist](issue-9-review-manual-checks.md) covers the new regressions and morning browser acceptance; the numbered walkthrough below remains useful for inspecting each request by hand.

1. **Check Docker and start the local application.** Run these commands in PowerShell:

   ```powershell
   Set-Location C:\Users\georg\Documents\LeggTix
   docker compose version
   docker info --format '{{.OSType}}'
   $issue9NewEnvironment = -not (Test-Path -LiteralPath .env)
   if ($issue9NewEnvironment) { Copy-Item .env.example .env }
   docker compose build app
   docker compose run --rm --no-deps app composer install --no-interaction
   if ($issue9NewEnvironment) { docker compose run --rm --no-deps app php artisan key:generate }
   docker compose up -d
   docker compose logs -f app
   ```

   Wait for `Server running on [http://0.0.0.0:8000]`, then press **Ctrl+C** to stop following logs. The services keep running. Continue with:

   ```powershell
   docker compose exec app php artisan migrate
   docker compose ps
   docker compose exec app php artisan migrate:status
   (Invoke-WebRequest -UseBasicParsing http://localhost:8000/up).StatusCode
   docker compose exec app php artisan route:list --path=reservations
   ```

   Expect `linux`, app/MySQL/Redis containers running, all seven migrations marked `Ran`, health `200`, and `POST api/events/{event}/reservations`. Preserve an existing `.env`; never regenerate an existing application key for this walkthrough. If port `8000` is occupied, stop the conflicting local service before starting this stack. Existing domain seed data is not needed.

2. **Run the isolated acceptance suite, including actual overlapping transactions.**

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=Reservation --display-warnings --fail-on-warning
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   Expect passing tests and removal of each temporary Docker project. The reservation suite includes model/factory guards, status and UTC dates, API authentication/input/resource contracts, replay/capacity/FIFO outcomes, rollback and model-save vetoes. Its eight concurrency cases start separate PHP processes/MySQL connections. They cover final-place competitors, same actor, stale repeatable-read snapshots, an event starting while booking waits, and actual lock-timeout retry exhaustion. Allocation cases pause the first booking after its guarded count increment, observe the second process at the held event lock, then release the first by commit or rollback. No normal database reset occurs. If cleanup fails, copy the exact temporary-project recovery command printed by the runner; see [testing.md](testing.md).

3. **Create dedicated live API fixtures.** Paste this whole block into the same PowerShell window:

   ```powershell
   $issue9FixtureJson = @'
   <?php
   require 'vendor/autoload.php';
   $app = require 'bootstrap/app.php';
   $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
   if (! $app->environment('local')) { throw new RuntimeException('This walkthrough requires APP_ENV=local.'); }
   $result = Illuminate\Support\Facades\DB::transaction(function () {
       $tag = bin2hex(random_bytes(8));
       $owner = App\Models\User::factory()->organiser()->create(['email' => 'issue9.owner.'.$tag.'@example.test']);
       $actor = App\Models\User::factory()->create(['email' => 'issue9.actor.'.$tag.'@example.test']);
       $other = App\Models\User::factory()->create(['email' => 'issue9.other.'.$tag.'@example.test']);
       $type = App\Models\EventType::factory()->create(['slug' => 'issue9-'.$tag]);
       $base = App\Models\Event::factory()->for($owner, 'owner')->for($type, 'eventType');
       $available = $base->published()->create(['name' => 'Issue9 available '.$tag, 'capacity' => 1]);
       $draft = $base->draft()->create(['name' => 'Issue9 draft '.$tag, 'capacity' => 1]);
       $past = $base->past()->create(['name' => 'Issue9 past '.$tag, 'capacity' => 1]);
       $waiting = $base->published()->create(['name' => 'Issue9 waiting '.$tag, 'capacity' => 1]);
       Illuminate\Support\Facades\DB::table('waitlist_entries')->insert([
           'event_id' => $waiting->id, 'user_id' => $other->id, 'status' => 'waiting',
           'joined_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
       ]);
       return [
           'available_id' => $available->id, 'draft_id' => $draft->id,
           'past_id' => $past->id, 'waiting_id' => $waiting->id,
           'actor_id' => $actor->id, 'other_id' => $other->id, 'owner_id' => $owner->id, 'type_id' => $type->id,
           'token' => $actor->createToken('issue9-manual', ['*'], now()->addHour())->plainTextToken,
           'other_token' => $other->createToken('issue9-manual', ['*'], now()->addHour())->plainTextToken,
       ];
   });
   echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
   '@ | docker compose exec -T app php
   if ($LASTEXITCODE -ne 0) { throw 'Fixture creation failed. Keep the output and fix the prerequisite before continuing.' }
   $issue9Fixture = $issue9FixtureJson | ConvertFrom-Json
   $issue9Url = "http://localhost:8000/api/events/$($issue9Fixture.available_id)/reservations"
   $issue9Headers = @{ Authorization = "Bearer $($issue9Fixture.token)" }
   $issue9OtherHeaders = @{ Authorization = "Bearer $($issue9Fixture.other_token)" }
   $issue9Fixture | Select-Object available_id,draft_id,past_id,waiting_id,actor_id,other_id,owner_id,type_id
   ```

   Expect numeric IDs for four new events and three new accounts. The available event has one place; the draft and past events cannot accept bookings; the waiting event has one free place and an existing waiter. Tokens last one hour. Treat these local bearer tokens as private and do not paste them into an issue/report. If an hour passes before finishing, rerun this fixture step and later clean up each fixture set separately.

4. **Define a request helper that shows both successful and rejected responses.** This works in Windows PowerShell without silently stopping on HTTP errors:

   ```powershell
   function Invoke-Issue9Reservation {
       param(
           [string] $Url,
           [hashtable] $Headers = @{},
           [string] $Body = '{}',
           [string] $ContentType = 'application/json'
       )
       try {
           $response = Invoke-WebRequest -UseBasicParsing -Method Post -Uri $Url -Headers $Headers -ContentType $ContentType -Body $Body
           [pscustomobject]@{ Status = [int]$response.StatusCode; Body = $response.Content }
       } catch {
           if ($null -eq $_.Exception.Response) { throw }
           [pscustomobject]@{ Status = [int]$_.Exception.Response.StatusCode; Body = $_.ErrorDetails.Message }
       }
   }
   ```

   Every later call prints `Status` and JSON `Body`. A network/connection error is raised separately; check Docker and `/up` if that happens.

5. **Check authentication, holder tampering and invalid payloads before making a valid reservation.**

   ```powershell
   Invoke-Issue9Reservation -Url $issue9Url
   Invoke-Issue9Reservation -Url $issue9Url -Headers @{ Authorization = 'Bearer invalid-token' }
   Invoke-Issue9Reservation -Url $issue9Url -Headers $issue9Headers -Body (@{user_id=$issue9Fixture.other_id} | ConvertTo-Json -Compress)
   Invoke-Issue9Reservation -Url "$($issue9Url)?user_id=$($issue9Fixture.other_id)" -Headers $issue9Headers
   Invoke-Issue9Reservation -Url $issue9Url -Headers $issue9Headers -Body '{"user_id":'
   Invoke-Issue9Reservation -Url $issue9Url -Headers $issue9Headers -Body '[]'
   Invoke-Issue9Reservation -Url $issue9Url -Headers $issue9Headers -Body 'user_id=999999' -ContentType 'text/plain'
   ```

   Expect `401`, `401`, `422`, `422`, `400`, `422`, `415`, in that order. Holder overrides return a validation error for `user_id`; list/scalar JSON errors include `body`. Every request must leave occupancy at zero and create no reservation. No input field is writable, including `status`, `event_id`, `quantity`, generated fields or timestamps. The booking actor comes from the bearer token. Regular users, organisers and admins can all book their own place; this endpoint has no separate role restriction. For a bodyless request, omit `Content-Type` or declare JSON; any declared unsupported media type receives `415`, even if the raw body is empty.

6. **Book the one available place, then replay and compete with another account.**

   ```powershell
   $issue9Created = Invoke-Issue9Reservation -Url $issue9Url -Headers $issue9Headers
   $issue9Created
   $issue9CreatedBody = $issue9Created.Body | ConvertFrom-Json
   $issue9CreatedBody.data
   Invoke-Issue9Reservation -Url $issue9Url -Headers $issue9Headers
   Invoke-Issue9Reservation -Url $issue9Url -Headers $issue9OtherHeaders
   ```

   Expect `201` and one `data` object with `id`, `event_id`, `user_id`, `status`, `cancelled_at`, `created_at`, `updated_at`. `event_id` equals `available_id`; `user_id` equals `actor_id`; status is `confirmed`, cancellation time is null, and dates are UTC strings ending in `Z`. Generated keys, account contact details and passwords must be absent. Replay returns `409` with `code: already_reserved`; the other account returns `409` with `code: full`. The original reservation ID/holder remains unchanged. Neither rejection creates a waitlist entry.

7. **Check event eligibility and queue priority.**

   ```powershell
   Invoke-Issue9Reservation -Url "http://localhost:8000/api/events/$($issue9Fixture.draft_id)/reservations" -Headers $issue9Headers
   Invoke-Issue9Reservation -Url "http://localhost:8000/api/events/$($issue9Fixture.past_id)/reservations" -Headers $issue9Headers
   Invoke-Issue9Reservation -Url "http://localhost:8000/api/events/$($issue9Fixture.waiting_id)/reservations" -Headers $issue9OtherHeaders
   Invoke-Issue9Reservation -Url "http://localhost:8000/api/events/$($issue9Fixture.waiting_id)/reservations" -Headers $issue9Headers
   Invoke-Issue9Reservation -Url 'http://localhost:8000/api/events/999999999999/reservations' -Headers $issue9Headers
   Invoke-Issue9Reservation -Url 'http://localhost:8000/api/events/not-an-event/reservations' -Headers $issue9Headers
   ```

   Expect `409 event_not_bookable`, `409 event_not_bookable`, `409 already_waitlisted`, `409 queue_has_priority`, `404`, `404`. The waiter retains their waiting record; no one receives the free waiting-event place until the later promotion workflow exists. Cancelled/completed event status rejection, historical cancelled rebooking with a fresh ID, and inactive type behavior are also covered by the automated suite.

8. **Inspect actual stored rows and counter invariants.** Serialize only numeric fixture IDs into the console command:

   ```powershell
   $issue9EventIds = @($issue9Fixture.available_id,$issue9Fixture.draft_id,$issue9Fixture.past_id,$issue9Fixture.waiting_id) -join ','
   $issue9Inspect = @'
   <?php
   require 'vendor/autoload.php';
   $app = require 'bootstrap/app.php';
   $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
   foreach (App\Models\Event::whereIn('id', [FIXTURE_EVENT_IDS])->orderBy('id')->get() as $event) {
       $confirmed = $event->reservations()->where('status', 'confirmed')->count();
       echo json_encode([
           'event_id' => $event->id, 'capacity' => $event->capacity, 'confirmed_count' => $event->confirmed_count,
           'confirmed_rows' => $confirmed, 'consistent' => $event->confirmed_count === $confirmed && $confirmed <= $event->capacity,
           'reservations' => $event->reservations()->get()->toArray(),
           'waiting_count' => Illuminate\Support\Facades\DB::table('waitlist_entries')->where('event_id', $event->id)->where('status', 'waiting')->count(),
       ], JSON_THROW_ON_ERROR), PHP_EOL;
   }
   '@
   $issue9Inspect.Replace('FIXTURE_EVENT_IDS', $issue9EventIds) | docker compose exec -T app php
   ```

   Expect `consistent: true` for all four events. The available event has capacity/count/confirmed rows `1/1/1`, one confirmed row for `actor_id`, and no waiter. Draft/past events have `1/0/0` and no rows. Waiting event has `1/0/0`, no reservation, and `waiting_count: 1`. This verifies persistence rather than relying only on HTTP success.

9. **Repeat the deterministic capacity/rollback race checks if accepting the locking protocol separately.**

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=ReservationConcurrencyTest --display-warnings --fail-on-warning
   ```

   Expect eight passing cases. Commit yields one confirmed reservation and one full/duplicate rejection; injected rollback yields no first attempt and one second-actor confirmation. Stale snapshots cannot allow an extra place or duplicate; an event that starts during lock wait is rejected with zero occupancy. The tests verify that competitors actually reach the event lock before releasing it. The timeout case keeps an event lock held through three real one-second MySQL waits: expect `503 reservation_busy`, `Retry-After: 1`, zero occupancy/rows and a successful booking after release. These tests use an isolated test database, not the live fixtures. A deliberately constructed deadlock cycle, load/latency benchmarks, reserve-versus-cancel/join/promotion and multiple application servers remain separate checks; cancellation/join/promotion are outside issue #9.

10. **Inspect diagnostics if a response or assertion differs.**

    ```powershell
    docker compose logs --tail 80 app
    docker compose logs --tail 80 mysql
    docker compose logs --tail 80 redis
    docker compose exec app php artisan queue:failed
    ```

    Expect `No failed jobs found.` on a clean local setup; a missing-table exception is a setup failure. If `failed_jobs` is absent, apply pending migrations with `docker compose exec app php artisan migrate` and repeat the command. Booking has no notification side effect and needs no queue worker in this milestone. If existing failed jobs are listed, inspect them separately; their presence alone does not establish a failed booking. A busy reservation returns `503`, `code: reservation_busy`, with `Retry-After: 1`; it must not return `full`. Automated checks cover both an injected database exception and real isolated MySQL lock-timeout exhaustion; the live walkthrough does not intentionally force a deadlock. Actor booking requests are limited to 30/minute and IP requests to 120/minute. If troubleshooting creates `429`, wait for its `Retry-After` before continuing.

11. **Remove only this walkthrough's fixtures and revoke its tokens.** Keep `$issue9Fixture` from step 3. This block uses only those captured IDs, guards the local environment and checks counts afterwards:

    ```powershell
    $issue9Cleanup = @'
    <?php
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->environment('local')) { throw new RuntimeException('Cleanup requires APP_ENV=local.'); }
    $events = [FIXTURE_EVENT_IDS];
    $users = [FIXTURE_USER_IDS];
    $type = FIXTURE_TYPE_ID;
    Illuminate\Support\Facades\DB::transaction(function () use ($events, $users, $type) {
        App\Models\Event::whereIn('id', $events)->orderBy('id')->lockForUpdate()->get();
        Illuminate\Support\Facades\DB::table('reservations')->whereIn('event_id', $events)->delete();
        Illuminate\Support\Facades\DB::table('waitlist_entries')->whereIn('event_id', $events)->delete();
        App\Models\Event::whereIn('id', $events)->delete();
        foreach (App\Models\User::whereIn('id', $users)->get() as $user) { $user->tokens()->delete(); }
        App\Models\User::whereIn('id', $users)->delete();
        App\Models\EventType::whereKey($type)->delete();
    });
    echo json_encode([
        'remaining_fixture_events' => App\Models\Event::whereIn('id', $events)->count(),
        'remaining_fixture_users' => App\Models\User::whereIn('id', $users)->count(),
        'remaining_fixture_type' => App\Models\EventType::whereKey($type)->count(),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    '@
    $issue9UserIds = @($issue9Fixture.actor_id,$issue9Fixture.other_id,$issue9Fixture.owner_id) -join ','
    $issue9Cleanup.Replace('FIXTURE_EVENT_IDS',$issue9EventIds).Replace('FIXTURE_USER_IDS',$issue9UserIds).Replace('FIXTURE_TYPE_ID',[string]$issue9Fixture.type_id) | docker compose exec -T app php
    Remove-Variable issue9Fixture,issue9FixtureJson,issue9Headers,issue9OtherHeaders -ErrorAction SilentlyContinue
    ```

    Expect all three remaining counts `0`. Existing unrelated data stays intact. If you want to stop the development services, run `docker compose down`; this retains the existing MySQL volume. Do not add `--volumes` to the development-stack stop command. No `migrate:fresh`, truncation, seeder reset or database recreation is needed.
