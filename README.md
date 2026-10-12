# LeggTix

LeggTix is an API-first event reservation and waitlist service. The backend is a Laravel modular monolith with registration, bearer-token authentication, the five-table MVP schema, event models and factories, and concurrency-safe reservation creation. Event management, cancellation and waitlist workflows are the next milestones.

## Stack

- PHP 8.4 and Laravel 13
- MySQL 8 for application data
- Redis for cache and queue connections
- Mailpit 1.31.4 for local email preview
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

Open [http://localhost:8000/up](http://localhost:8000/up). Laravel's health endpoint should return a successful response. Migrations create `users`, `event_types`, `events`, `reservations`, `waitlist_entries`, Sanctum's `personal_access_tokens` and Laravel's `failed_jobs`, alongside migration history. Seeding adds the demo accounts, types, events and reservations below.

Redis holds pending queued work; MySQL stores failed-job records. After applying migrations, `docker compose exec app php artisan queue:failed` should report `No failed jobs found.` on a clean setup. Existing checkouts pick up new migrations with `docker compose exec app php artisan migrate`. The current booking flow dispatches no queued job and needs no queue worker. Mailpit captures local email at [http://localhost:8025](http://localhost:8025); the [local email preview guide](#local-email-preview) includes configuration and a queued-mail check.

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

All five demo accounts use **`demo-password`** when first created:

| Account | Email | Role |
| --- | --- | --- |
| Demo User | `demo.user@example.test` | `regular_user` |
| Demo Organiser | `demo.owner@example.test` | `organiser` |
| Demo Admin | `demo.admin@example.test` | `admin` |
| Demo Attendee One | `demo.attendee.one@example.test` | `regular_user` |
| Demo Attendee Two | `demo.attendee.two@example.test` | `regular_user` |

These are local review credentials, including the privileged admin account, and must never be used for production. The demo seeder runs only with `APP_ENV=local` or `testing`; other environments are rejected even with `--force`. It creates no access token. Use the ordinary login endpoint, for example in PowerShell:

```powershell
$demoLogin = Invoke-RestMethod -Method Post -Uri http://localhost:8000/api/login -ContentType 'application/json' -Body (@{
    email = 'demo.user@example.test'
    password = 'demo-password'
} | ConvertTo-Json)
$demoLogin.user
```

Expect the customer profile and role `regular_user`. Change the email to `demo.owner@example.test` to log in as the organiser. The response also contains the bearer token described in the [authentication guide](docs/authentication.md).

Five active types (`concert`, `live-show`, `film-screening`, `workshop`, `meetup`) and eight organiser-owned events are created. Existing workshop/meetup fixtures remain available for older local checkouts.

| Event | Type | Status | Schedule at creation | Capacity | Confirmed |
| --- | --- | --- | --- | --- | --- |
| Demo: Riverside Acoustic Night | concert | published | 14 days ahead | 120 | 1 |
| Demo: Laravel Makers Workshop | workshop | published | 21 days ahead | 24 | 0 |
| Demo: Community Coffee Meetup | meetup | draft | 28 days ahead | 40 | 0 |
| Demo: Garden Session | concert | cancelled | 35 days ahead | 80 | 0 |
| Demo: Intro to Web APIs | workshop | completed | 14 days ago | 20 | 0 |
| Demo: Last Week's Community Meetup | meetup | published | 7 days ago | 30 | 0 |
| Demo: Studio Live Showcase | live-show | published | 18 days ahead | 2 | 2 |
| Demo: Independent Film Screening | film-screening | published | 25 days ahead | 30 | 0 |

Schedules are UTC instants with `Europe/London` as the display timezone. A fresh dataset has three confirmed reservations: Demo User holds one place at Riverside Acoustic Night, and the two Demo Attendees fill Studio Live Showcase. Each booking uses the ordinary reservation action and its MySQL transaction/locking rules; `confirmed_count` equals the number of confirmed reservations. The past published event demonstrates the start-time cutoff. No waitlist entries or cancelled/rebooked attempts are generated: those history scenarios depend on the cancellation, join/leave and promotion workflows in [#10](https://github.com/GLegg18/LeggTix/issues/10), [#13](https://github.com/GLegg18/LeggTix/issues/13) and [#14](https://github.com/GLegg18/LeggTix/issues/14). Event management and discovery endpoints remain follow-on work.

To find a published future event ID for a Swagger booking request:

```powershell
docker compose exec app php artisan tinker --execute="dump(App\Models\Event::query()->where('status', 'published')->whereRaw('starts_at > UTC_TIMESTAMP(6)')->get(['id', 'name', 'capacity', 'confirmed_count'])->toArray());"
```

A fresh demo has four matching events. Log in as Demo User and choose Independent Film Screening for a successful `201` booking; repeating it returns `409 already_reserved`. Studio Live Showcase returns `409 full` for Demo User. Riverside Acoustic Night already has Demo User's reservation, so it returns `409 already_reserved` immediately. Copy the chosen event's `id` into the reservation operation's `event` parameter and send `{}`.

Rerunning creates missing fixtures and preserves existing passwords, edits, dates, retired types and booking history. It also upgrades the earlier six-event dataset by adding the new accounts, types, events and missing demo bookings. A demo booking is added only when that event/user pair has no reservation or waitlist history; a cancelled booking or a former waiter is never automatically booked again. Fixture identities are the demo emails, type slugs and owner/event names: changing an identity or deleting a sample can cause its original to be created again.

Conflicting account roles, new events requiring retired types, inconsistent occupancy, or a missing demo booking that cannot be allocated abort the whole seed transaction. Existing data is never silently repaired to force the demo into place. The seeder does not reset credentials or move old events into the future. An unchanged seeded dataset can be replayed after its events pass; an older unbooked event may require the explicit local reset below before its missing demo booking can be created. Reset only when you intend to delete local records.

The [issue #3 verification report](docs/issue-3-validation.md) records checks, review outcomes, deferred history scenarios and a short manual plan. The [earlier demo report](docs/demo-data-validation.md) records the original six-event dataset.

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
- If a port is already in use, stop the other service using port `8000`, `3306`, `6379`, or `8025`, or change the host port while keeping the `127.0.0.1` loopback address in the corresponding mapping in `docker-compose.yml`.
- If MySQL is unhealthy or the app reports a database connection error, check `docker compose ps` and `docker compose logs mysql`; the app waits for MySQL's health check. Confirm `.env` has the Compose defaults (`DB_HOST=mysql` and matching database credentials), then retry `docker compose up -d`.
- For application startup errors, inspect `docker compose logs app`. The app container runs `composer install` on startup, so dependency installation errors may require network access or a later retry.
- If you copied `.env` yourself and its `APP_KEY` is empty, run `docker compose run --rm --no-deps app php artisan key:generate`, then `docker compose up -d --force-recreate app`. Recreating the app reloads `env_file` values after `.env` changes; a plain restart retains the old container environment. Preserve an existing application key.
- If the inbox is unavailable, check `docker compose ps` and `docker compose logs mailpit`. For missing email or SMTP errors, follow the configuration/cache steps under [local email preview](#local-email-preview); inside containers, the SMTP host is `mailpit`, not `localhost`.

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

## Local email preview

[Issue #4](https://github.com/GLegg18/LeggTix/issues/4) adds [Mailpit](https://mailpit.axllent.org/docs/install/docker/), pinned to [v1.31.4](https://github.com/axllent/mailpit/releases/tag/v1.31.4). Open [http://localhost:8025](http://localhost:8025) to inspect captured messages. Compose publishes the inbox only on `127.0.0.1` and sets Mailpit's [host allowlist](https://mailpit.axllent.org/docs/configuration/http/#dns-rebinding-mitigation) for local/container access. SMTP port `1025` is available only inside the Compose network. Laravel and any worker launched in the `app` container use the service name `mailpit` for SMTP.

New checkouts inherit these `.env.example` settings. For an existing checkout, update only the mail values in your `.env`, preserving its application key and other settings:

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

Mailpit's local SMTP listener requires no authentication or TLS. `MAIL_URL` must be unset or `null`: an old SMTP URL overrides the individual host/port settings. Laravel 13 supplies the SMTP configuration through its framework defaults, so no published `config/mail.php` is needed. After changing `.env`, reload the container environment and clear any cached Laravel configuration:

```powershell
docker compose up -d --force-recreate app
docker compose exec app php artisan config:clear
```

Compose starts Mailpit with the stack and waits for its health check before starting the app. Restart any manually launched queue worker after changing mail configuration; workers retain their boot-time settings. A simple synchronous message can be sent without adding application notification code:

```powershell
docker compose exec app php artisan tinker --execute="Illuminate\Support\Facades\Mail::to('mailpit-preview@example.test')->send((new Illuminate\Mail\Mailable)->subject('LeggTix Mailpit preview')->html('Local SMTP preview from Laravel.'));"
```

Expect one message with that subject and body in the inbox. To verify both direct delivery and a real Redis queued message from a separate worker process:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\mailpit-smoke.ps1
```

The smoke check uses unique `.test` recipients and a dedicated queue, checks the captured content through Mailpit's API, and removes only its own messages and temporary queue data. A worker runs in the `app` container for that queue and exits after the check; Compose does not start a background worker. See the [issue #4 verification report](docs/issue-4-validation.md) for acceptance results, the manual plan and any limits.

With these settings, messages are captured by Mailpit and no real recipient delivery is configured. Do not configure Mailpit SMTP relay/forwarding or external SMTP credentials for this local stack. The inbox is unauthenticated and intended for local development. It uses [temporary storage](https://mailpit.axllent.org/docs/configuration/email-storage/), so stopping or recreating Mailpit discards previews; the default limit retains the newest 500 messages. Automated PHPUnit runs remain isolated and use the `array` mailer without Mailpit.

## Current scope

Registration creates only `regular_user` accounts. Login issues a Laravel Sanctum token that expires after 24 hours; logout revokes the current token. The [authentication guide](docs/authentication.md) documents validation, response contracts, token handling, and rate limits.

The [MVP schema](docs/database-design.md) is implemented with MySQL constraints, generated active uniqueness keys, restricted foreign keys, and UTC `DATETIME(6)` domain timestamps. [Event models and factories](docs/events.md) provide ownership/type relationships, typed lifecycle statuses, scheduling and fixture states for issue #6. Local demo seeders provide the review accounts, sample types/events and confirmed reservations above.

[Reservation creation](docs/reservations.md) locks the current event row in MySQL, checks eligibility and duplicate/waiting state, and commits a guarded occupancy increment with a fresh attempt. Competing bookings for one event serialize through that lock; cached availability cannot claim a place. All inventory writers must follow this protocol. Direct SQL can break counter/row equality, and this milestone makes no throughput guarantee. Existing waiters block newcomer allocation until promotion is implemented. The [issue #9 validation report](docs/issue-9-validation.md) records the independent MySQL race checks and their limits.

Event management, reservation cancellation/history, waitlist join/leave/promotion and notifications, password reset, and email verification remain follow-on work. Booking is the first domain endpoint.

## Design documentation

The [documentation index](docs/README.md) links the [database design for issue #27](docs/database-design.md), its ER diagram, [two-customer reservation races](docs/reservation-races.md), and the [staged ticketing extension plan](docs/ticketing-extensions.md). The five-table MVP remains the initial schema; venues, seating, teams, overlapping roles and payments have separate future designs and ticket handoffs.

The [issue #5 verification report](docs/issue-5-validation.md) records the checks performed, review findings and remaining verification limits.
