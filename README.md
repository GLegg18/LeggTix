# LeggTix

LeggTix is an API-first event reservation and waitlist service. The backend is a Laravel modular monolith with registration, bearer-token authentication, and the five-table MVP schema. Event and reservation workflows are the next milestones.

## Stack

- PHP 8.4 and Laravel 13
- MySQL 8 for application data
- Redis for cache and queue connections
- Docker Compose for the local services
- PHPUnit for the Laravel test runner

The API is the priority. A Nuxt frontend and Filament admin panel are optional follow-on work.

## Prerequisites

- Windows 10/11 with WSL 2 enabled
- Docker Desktop with the WSL 2 backend and Docker Compose v2
- Git

PHP and Composer do not need to be installed on the host; they run in the application container. The first image build downloads the PHP base image and Composer dependencies, so it needs network access.

## First run

From the repository root in PowerShell:

```powershell
Copy-Item .env.example .env
docker compose up --build -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open [http://localhost:8000/up](http://localhost:8000/up). Laravel's health endpoint should return a successful response. Migrations create `users`, `event_types`, `events`, `reservations`, `waitlist_entries`, and Sanctum's `personal_access_tokens`, alongside Laravel's migration history. No sample accounts or event data are inserted.

Use the [authentication API guide](docs/authentication.md) to register, log in, fetch the current user, and revoke a token. The API returns JSON errors even when a client omits its `Accept` header.

After migrating, use the [testing guide](docs/testing.md) for the isolated Docker runner and the [manual acceptance checks](docs/manual-test-plan.md) for the live app walkthrough and expected results.

To stop the services while keeping the MySQL data, run `docker compose down`.

## Reset local data

To drop and recreate the application's tables while keeping the containers and MySQL volume, run:

```powershell
docker compose exec app php artisan migrate:fresh
```

To remove all local MySQL data as well as stop the services, run:

```powershell
docker compose down --volumes
```

Then start the stack and migrate again:

```powershell
docker compose up --build -d
docker compose exec app php artisan migrate
```

`migrate:fresh` and `down --volumes` are destructive to local database data.

## Troubleshooting

- If Compose cannot connect to Docker, start Docker Desktop and wait for its engine to finish starting, then retry `docker compose up --build -d`.
- If a port is already in use, stop the other service using port `8000`, `3306`, or `6379`, or change the host port while keeping the `127.0.0.1` loopback address in the corresponding mapping in `docker-compose.yml`.
- If MySQL is unhealthy or the app reports a database connection error, check `docker compose ps` and `docker compose logs mysql`; the app waits for MySQL's health check. Confirm `.env` has the Compose defaults (`DB_HOST=mysql` and matching database credentials), then retry `docker compose up -d`.
- For application startup errors, inspect `docker compose logs app`. The app container runs `composer install` on startup, so dependency installation errors may require network access or a later retry.

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

The [MVP schema](docs/database-design.md) is implemented with MySQL constraints, generated active uniqueness keys, restricted foreign keys, and UTC `DATETIME(6)` domain timestamps. Its row constraints do not implement booking capacity allocation or cross-table reservation/waitlist rules. Event management, reservation and waitlist actions, their policies and locking protocol, domain seed data, password reset, and email verification remain follow-on work. There are no domain endpoints yet.

## Design documentation

The [documentation index](docs/README.md) links the [database design for issue #27](docs/database-design.md), its ER diagram, [two-customer reservation races](docs/reservation-races.md), and the [staged ticketing extension plan](docs/ticketing-extensions.md). The five-table MVP remains the initial schema; venues, seating, teams, overlapping roles and payments have separate future designs and ticket handoffs.

The [issue #5 verification report](docs/issue-5-validation.md) records the checks performed, review findings and remaining verification limits.
