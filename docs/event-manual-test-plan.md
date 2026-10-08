# Event model manual acceptance checks

This is the tester's repeatable plan for issue #6. The [verification report](issue-6-validation.md) records the successful portable and Docker suites, portable console checks, cleanup and normal-stack preservation checks. The full console walkthrough was not repeated inside Docker. There are no event HTTP endpoints in this milestone.

Run shell commands in PowerShell at `C:\Users\georg\Documents\LeggTix`. Docker Desktop must use Linux containers with WSL 2 and Compose v2. Host PHP/MySQL installations are not required.

## 1. Check Docker and start the app

```powershell
Set-Location C:\Users\georg\Documents\LeggTix
docker compose version
docker info --format '{{.OSType}}'
```

Expect a Compose version and `linux`. Preserve an existing `.env`:

```powershell
$issue6NewEnvironment = -not (Test-Path -LiteralPath .env)
if ($issue6NewEnvironment) { Copy-Item .env.example .env }
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction
if ($issue6NewEnvironment) { docker compose run --rm --no-deps app php artisan key:generate }
docker compose up -d
docker compose logs -f app
```

Wait for `Server running on [http://0.0.0.0:8000]`, then press **Ctrl+C** to stop following logs; the services keep running. Continue with:

```powershell
docker compose exec app php artisan migrate
docker compose ps
docker compose exec app php artisan migrate:status
(Invoke-WebRequest -UseBasicParsing http://localhost:8000/up).StatusCode
```

Expect app/MySQL/Redis running, all six migrations `Ran`, and health `200`. No domain seeds or existing accounts are needed.

## 2. Run the isolated acceptance tests

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=EventModelTest --display-warnings --fail-on-warning
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
```

At this milestone, expect **44 tests/326 assertions**, then **150 tests/948 assertions**, and successful cleanup after each run. The runner uses a separate temporary database. The complete suite also verifies the existing physical constraints and migration lifecycle.

## 3. Create console fixtures inside a transaction

```powershell
docker compose exec app php artisan tinker
```

At the Tinker prompt, paste:

```php
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$before = [Event::count(), EventType::count(), User::count()];
DB::beginTransaction();
$owner = User::factory()->organiser()->create();
$type = EventType::factory()->create();
$event = Event::factory()->published()->for($owner, 'owner')->for($type, 'eventType')->create(['capacity' => 2]);
```

Expect successful creation. Keep this same console open; roll back in step 8 even if a check fails. The transaction allows fixture cleanup without deleting existing records.

## 4. Check relationships and eligibility

```php
[
    $event->fresh()->status->value,
    $event->owner->is($owner),
    $event->eventType->is($type),
    $owner->ownedEvents->contains($event),
    $type->events->contains($event),
    $event->isPast(),
    $event->isBookable(),
    Event::upcoming()->whereKey($event->id)->exists(),
    $event->confirmed_count,
];
```

Expect `["published", true, true, true, true, false, true, true, 0]`.

## 5. Check the five factory states

```php
foreach (['draft', 'published', 'cancelled', 'completed', 'past'] as $state) {
    $fixture = Event::factory()->{$state}()->create();
    dump([$state, $fixture->status->value, $fixture->isPast(), $fixture->isBookable()]);
}
```

| State | Stored status | Past | Bookable |
| --- | --- | --- | --- |
| draft | draft | false | false |
| published | published | false | true |
| cancelled | cancelled | false | false |
| completed | completed | true | false |
| past | published | true | false |

These read predicates do not reserve inventory. Exact cutoff and UTC/microsecond round trips are covered by the automated acceptance cases.

## 6. Check validation and protected catalogue fields

```php
try {
    $event->capacity = 0;
} catch (\Illuminate\Validation\ValidationException $exception) {
    dump($exception->errors());
}
$event->fresh()->capacity;

try {
    $event->timezone = '+01:00';
} catch (\Illuminate\Validation\ValidationException $exception) {
    dump($exception->errors());
}

try {
    $type->fill(['is_active' => false]);
} catch (\Illuminate\Database\Eloquent\MassAssignmentException $exception) {
    dump('Catalogue assignment rejected');
}
$type->fresh()->is_active;
```

Expect validation errors for `capacity` and `timezone`; stored capacity remains `2`. The catalogue rejects general assignment and its stored active flag remains `true`.

## 7. Check the database schedule invariant

```php
try {
    DB::table('events')->where('id', $event->id)->update([
        'ends_at' => $event->starts_at->format('Y-m-d H:i:s.u'),
    ]);
} catch (\Illuminate\Database\QueryException $exception) {
    dump($exception->errorInfo[1]);
}
$event->fresh()->ends_at;
```

Expect MySQL error `3819` and stored `ends_at` still `null`. Authorization endpoints, lifecycle mutations, booking/promotion and inventory concurrency remain later workflow checks.

## 8. Roll back the fixtures

```php
DB::rollBack();
$before === [Event::count(), EventType::count(), User::count()];
exit
```

Expect `true`, confirming the fixture rows were removed. If a check failed, retain its output and inspect:

```powershell
docker compose logs --tail 60 app
docker compose logs --tail 60 mysql
docker compose logs --tail 60 redis
```

No queue worker is required. `docker compose down` stops the local services and retains existing database data. If the isolated test runner's cleanup failed, use its exact temporary-project recovery command from the [testing guide](testing.md).
