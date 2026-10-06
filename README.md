# LeggTix

LeggTix is an API-first event reservation and waitlist service. The backend is a Laravel modular monolith; the first milestone establishes the application and local development environment before event and reservation features are added.

## Stack

- PHP 8.4 and Laravel 13
- MySQL 8 for application data
- Redis for cache and queue connections
- Docker Compose for the local services
- PHPUnit for the Laravel test runner

The API is the priority. A Nuxt frontend and Filament admin panel are optional follow-on work and are not part of this bootstrap.

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

Open [http://localhost:8000/up](http://localhost:8000/up). Laravel's health endpoint should return a successful response. The initial migrations are intentionally empty; application tables arrive with their feature work.

To stop the services, run `docker compose down`. Add `-v` only when you also want to remove the local MySQL data volume.

## Useful commands

```powershell
docker compose logs -f app
docker compose exec app php artisan
docker compose exec app php artisan test
docker compose exec app php artisan migrate:status
```

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

This is the project skeleton only. Authentication, event management, reservations, waitlist promotion, seed data, and feature tests are planned for later milestones.

## Design documentation

The [documentation index](docs/README.md) links the [database design for issue #27](docs/database-design.md) and its ER diagram. It records the proposed MVP schema, ownership and role decisions, lifecycle constraints, concurrency protocol, and deferred ticketing features before migrations are implemented.
