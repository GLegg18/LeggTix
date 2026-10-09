# LeggTix

LeggTix is an API-first event reservation and waitlist service. The backend is a Laravel modular monolith with registration, bearer-token authentication, the five-table MVP schema, event models and factories, and concurrency-safe reservation creation. Event management, cancellation and waitlist workflows are the next milestones.

## Stack

- PHP 8.4 and Laravel 13
- MySQL 8 for application data
- Redis for cache and queue connections
- PHP Redis extension pinned to 6.3.0 in the Dockerfile
- Docker Compose for the local services
- PHPUnit for the Laravel test runner

The API is the priority. A Nuxt frontend and Filament admin panel are optional follow-on work.

## Prerequisites

- Windows meeting [Docker Desktop's current requirements](https://docs.docker.com/desktop/setup/install/windows-install/), with WSL 2 and hardware virtualization enabled
- Docker Desktop with the WSL 2 backend, Linux containers and Docker Compose v2 or newer
- Git

PHP and Composer run in the application container. Node.js and npm are not required: the API explorer's pinned JavaScript/CSS assets are committed under `resources/vendor/swagger-ui`, with their licenses and provenance. Installed PHP dependencies under the root `vendor/` and generated application files are ignored. The first build and Composer installation need network access.

## First run

For a new checkout, open PowerShell and run:

```powershell
git clone https://github.com/GLegg18/LeggTix.git
Set-Location LeggTix
```

From the repository root, prepare dependencies and the application key before starting the services. These commands preserve an existing `.env` and generate a key only for a newly created environment:

```powershell
$leggTixNewEnvironment = -not (Test-Path -LiteralPath .env)
if ($leggTixNewEnvironment) { Copy-Item .env.example .env }
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction
if ($leggTixNewEnvironment) { docker compose run --rm --no-deps app php artisan key:generate }
docker compose up -d
docker compose logs -f app
```

Wait for `Server running on [http://0.0.0.0:8000]`, then press **Ctrl+C** to stop following logs; the services keep running. Install the schema and local demo data:

```powershell
docker compose exec app php artisan migrate --seed
```

Preparing the key before `up` ensures Compose loads it from `.env` when creating the app container. The initial dependency installation also makes Artisan available before the first migration.

Open [http://localhost:8000/up](http://localhost:8000/up). Laravel's health endpoint should return a successful response. Migrations create `users`, `event_types`, `events`, `reservations`, `waitlist_entries`, Sanctum's `personal_access_tokens` and Laravel's `failed_jobs`, alongside migration history. Seeding adds the demo accounts, types and events below.

Redis holds pending queued work; MySQL stores failed-job records. After applying migrations, `docker compose exec app php artisan queue:failed` should report `No failed jobs found.` on a clean setup. Existing checkouts pick up new migrations with `docker compose exec app php artisan migrate`. The current booking flow dispatches no queued job and needs no queue worker.

Open the [interactive API explorer](http://localhost:8000/docs/api) to browse requests, response schemas and errors, and send requests to the running app. Its [OpenAPI JSON](http://localhost:8000/docs/api.json) can also be imported into an API client. The explorer is available only in local/test environments with development dependencies installed.

Allow several seconds for the schema to generate when opening the explorer.

Use the login operation to obtain a token, then click **Authorize** and paste the raw `access_token`; Swagger adds the `Bearer` prefix. Authorization lasts only for the current page session. **Try it out** sends real requests to your local database. Booking needs an existing event ID; event discovery and management endpoints are still separate work.

The [authentication guide](docs/authentication.md) and [booking guide](docs/reservations.md) retain implementation notes and reproducible checks. The API returns JSON errors even when a client omits its `Accept` header.

After migrating, use the [testing guide](docs/testing.md) for the isolated Docker runner and the [manual acceptance checks](docs/manual-test-plan.md) for the live app walkthrough and expected results.

The [Tests workflow](.github/workflows/tests.yml) runs MySQL acceptance on Linux using Docker Compose directly on pushes and pull requests. PowerShell is a convenience for local Windows testing. The [testing guide](docs/testing.md#continuous-integration) explains the hosted check and configuring its required status.

To stop the services while keeping the MySQL data, run `docker compose down`.

## Demo data and login

For an already migrated local app, add the sample data with:

```powershell
docker compose exec app php artisan db:seed
```

Both demo accounts use **`demo-password`** when first created:

| Account | Email | Role |
| --- | --- | --- |
| Demo User | `demo.user@example.test` | `regular_user` |
| Demo Organiser | `demo.owner@example.test` | `organiser` |

These are local review credentials. The demo seeder runs only with `APP_ENV=local` or `testing`; other environments are rejected even with `--force`. Seeding creates no admin account or access token. Use the ordinary login endpoint, for example in PowerShell:

```powershell
$demoLogin = Invoke-RestMethod -Method Post -Uri http://localhost:8000/api/login -ContentType 'application/json' -Body (@{
    email = 'demo.user@example.test'
    password = 'demo-password'
} | ConvertTo-Json)
$demoLogin.user
```

Expect the customer profile and role `regular_user`. Change the email to `demo.owner@example.test` to log in as the organiser. The response also contains the bearer token described in the [authentication guide](docs/authentication.md).

Three active types (`concert`, `workshop`, `meetup`) and six organiser-owned events are created:

| Event | Type | Status | Schedule at creation | Capacity |
| --- | --- | --- | --- | --- |
| Demo: Riverside Acoustic Night | concert | published | 14 days ahead | 120 |
| Demo: Laravel Makers Workshop | workshop | published | 21 days ahead | 24 |
| Demo: Community Coffee Meetup | meetup | draft | 28 days ahead | 40 |
| Demo: Garden Session | concert | cancelled | 35 days ahead | 80 |
| Demo: Intro to Web APIs | workshop | completed | 14 days ago | 20 |
| Demo: Last Week's Community Meetup | meetup | published | 7 days ago | 30 |

Schedules are UTC instants with `Europe/London` as the display timezone. Every new event has zero confirmed occupancy and no reservation/waitlist fixtures. The past published event demonstrates the start-time cutoff. Event APIs and management UI are still follow-on work; these rows are ready for database/model inspection without manually creating fixtures in Tinker.

To find a published future event ID for a Swagger booking request:

```powershell
docker compose exec app php artisan tinker --execute="dump(App\Models\Event::query()->where('status', 'published')->whereRaw('starts_at > UTC_TIMESTAMP(6)')->get(['id', 'name', 'capacity', 'confirmed_count'])->toArray());"
```

A fresh demo has two matching events. Copy an `id` into the reservation operation's `event` parameter and send `{}`. A first booking returns `201`; repeating it with the same account returns `409 already_reserved`.

Rerunning creates missing fixtures and preserves existing passwords, edits, dates, retired types and booking history. Fixture identities are the demo emails, type slugs and owner/event names: changing an identity or deleting a sample can cause its original to be created again. The seeder refuses conflicting account roles and new events that would use a retired type, rolling back the run. It does not reset existing credentials or move old events into the future; use the explicit local reset below for a fresh demo.

The [demo data verification report](docs/demo-data-validation.md) records checks, review outcomes and a short manual plan.

## Reset local data

To drop and recreate the application's tables and restore fresh demo data while keeping the containers and MySQL volume, run:

```powershell
docker compose exec app php artisan migrate:fresh --seed
```

To remove all local MySQL data as well as stop the services, run:

```powershell
docker compose down --volumes
```

Then start the stack and migrate again:

```powershell
docker compose up --build -d
docker compose exec app php artisan migrate --seed
```

`migrate:fresh` and `down --volumes` are destructive to local database data.

## Troubleshooting

- If Compose cannot connect to Docker, start Docker Desktop and wait for its engine to finish starting, then retry `docker compose up --build -d`.
- If a port is already in use, stop the other service using port `8000`, `3306`, or `6379`, or change the host port while keeping the `127.0.0.1` loopback address in the corresponding mapping in `docker-compose.yml`.
- If MySQL is unhealthy or the app reports a database connection error, check `docker compose ps` and `docker compose logs mysql`; the app waits for MySQL's health check. Confirm `.env` has the Compose defaults (`DB_HOST=mysql` and matching database credentials), then retry `docker compose up -d`.
- For application startup errors, inspect `docker compose logs app`. The app container runs `composer install` on startup, so dependency installation errors may require network access or a later retry.
- If you copied `.env` yourself and its `APP_KEY` is empty, run `docker compose run --rm --no-deps app php artisan key:generate`, then `docker compose up -d --force-recreate app`. Recreating the app reloads `env_file` values after `.env` changes; a plain restart retains the old container environment. Preserve an existing application key.

## Useful commands

```powershell
docker compose logs -f app
docker compose exec app php artisan
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
docker compose exec app php artisan migrate:status
docker compose exec app php artisan sanctum:prune-expired --hours=24
```

The test script creates a fresh, isolated MySQL container and test database, builds the test image, runs migrations and test fixtures, and removes its temporary stack afterwards. Docker Desktop must be running in Linux-container mode; the normal app stack does not need to be running. No manual database creation or grants are needed. See the [testing guide](docs/testing.md) for filters, cleanup and troubleshooting. Tests still refuse an unsafe database before migrations.

## Project shape

- `app/` — application code, organized around Laravel conventions
- `bootstrap/` — framework bootstrap and service providers
- `config/` — app, database, cache, queue, and logging configuration
- `routes/api.php` — stateless API routes
- `routes/console.php` — console commands
- `database/` — migrations, factories, and seeders

Controllers should validate requests and coordinate small action/service classes where a business workflow warrants one. Database transactions remain the source of truth for reservation capacity; Redis is for cache and queue workloads, not ticket inventory.

## Local configuration

`.env.example` contains development-only defaults for the Compose network. Copy it to `.env` before starting the stack. Do not put real credentials in `.env.example` or commit a populated `.env` file. The MySQL credentials in the example are local container credentials only.

## Current scope

Registration creates only `regular_user` accounts. Login issues a Laravel Sanctum token that expires after 24 hours; logout revokes the current token. The [authentication guide](docs/authentication.md) documents validation, response contracts, token handling, and rate limits.

The [MVP schema](docs/database-design.md) is implemented with MySQL constraints, generated active uniqueness keys, restricted foreign keys, and UTC `DATETIME(6)` domain timestamps. [Event models and factories](docs/events.md) provide ownership/type relationships, typed lifecycle statuses, scheduling and fixture states for issue #6. Local demo seeders provide the review accounts and sample types/events above.

[Reservation creation](docs/reservations.md) locks the current event row in MySQL, checks eligibility and duplicate/waiting state, and commits a guarded occupancy increment with a fresh attempt. Competing bookings for one event serialize through that lock; cached availability cannot claim a place. All inventory writers must follow this protocol. Direct SQL can break counter/row equality, and this milestone makes no throughput guarantee. Existing waiters block newcomer allocation until promotion is implemented. The [issue #9 validation report](docs/issue-9-validation.md) records the independent MySQL race checks and their limits.

Event management, reservation cancellation/history, waitlist join/leave/promotion and notifications, password reset, and email verification remain follow-on work. Booking is the first domain endpoint.

## Design documentation

The [documentation index](docs/README.md) links the [database design for issue #27](docs/database-design.md), its ER diagram, [two-customer reservation races](docs/reservation-races.md), and the [staged ticketing extension plan](docs/ticketing-extensions.md). The five-table MVP remains the initial schema; venues, seating, teams, overlapping roles and payments have separate future designs and ticket handoffs.

The [issue #5 verification report](docs/issue-5-validation.md) records the checks performed, review findings and remaining verification limits.
