# Frontend startup and logging follow-up

Work date: 16 October 2026. The user reported that the Docker Desktop Start button appeared to start and immediately stop the frontend, and that `storage/logs` was empty.

## Evidence and diagnosis

The existing frontend container was exited with code `255`, `OOMKilled=false`, and an empty Docker `Error` field. Its recorded start was `2026-10-16T10:13:19Z`, with exit at `10:24:47Z`: it had run for about eleven minutes. Its final Nuxt logs showed `Ready` and a successful first render, with no startup exception. A controlled start of that same container succeeded and stayed running. The reported immediate-exit symptom was therefore **not reproduced**, and the exit code alone does not establish whether the engine, UI action or application caused the earlier stop.

The original frontend had no restart policy, HTTP healthcheck or Docker init process. Nuxt ran behind shell/npm wrappers with Docker's default shutdown grace period. The installed Nuxt supervisor permits up to fifteen seconds for cleanup, while Docker's previous default was shorter. These were concrete lifecycle/recovery weaknesses, not proof of the original exit's cause. The CLI already clears genuinely stale dev locks when the old PID is dead or the old listen port is free; no lock bypass, dependency-volume deletion or database reset was applied.

The empty Laravel log directory had a direct configuration explanation: both `.env` and `.env.example` selected `LOG_STACK=stderr`. Laravel log records therefore went to process stderr instead of a file. Nuxt is a separate process and writes its startup/compiler/server output to its Docker log, regardless of Laravel's channel settings.

## Changes

- `docker-compose.yml`: frontend now uses `init: true`, direct `exec node .../nuxt.mjs` after locked dependency installation, `restart: unless-stopped`, a 20-second stop grace period and an HTTP healthcheck.
- `frontend/Dockerfile`: the development command also invokes Nuxt directly.
- Laravel's healthcheck gets a three-minute startup allowance and five-second probe timeout for Windows bind-mount Composer startup, so Compose does not abandon frontend startup too early.
- `.env.example` and the existing local `.env`: `LOG_STACK=single,stderr` enables Laravel file and stderr output. Existing credentials, application key and database were preserved.
- The root README and [frontend diagnostics guide](frontend.md#finding-startup-errors) now explain log ownership, health/startup state, safe state inspection and saving a Docker log snapshot.

Docker Desktop's Start button reuses an existing container; Compose file changes require recreation. The updated services were rebuilt/recreated with `docker compose up -d --build --wait --wait-timeout 300 app frontend worker`, which completed with healthy app/frontend services.

## Verification

- `docker compose config --quiet`: passed.
- Updated development images built and services reached healthy state.
- Deliberately sent `SIGTERM` to the frontend's container init process. It recovered automatically, reached `healthy`, and its restart count increased from zero to one. This is an intentional test restart, not an unexplained additional failure.
- Performed a normal frontend stop followed by `docker start` on the same container, matching Docker Desktop's Start action. The stop completed with exit code zero and no Docker error; the subsequent homepage request succeeded and the frontend returned to healthy state.
- Independent tester: frontend `running/healthy`, restart count one; homepage `200`, CSRF handshake `204`, unauthenticated SPA user `401`, and a missing-origin SPA request `403` as intended.
- Independent tester: one synthetic log marker appeared in `storage/logs/laravel.log` and its attached CLI stderr. A second marker, with the CLI stderr explicitly routed to the container's main stderr, appeared in both that file and `docker compose logs app`. An ordinary `docker exec` diagnostic is attached to its invoking terminal, so it is not incorrectly claimed to appear in the main container log without that routing.

The tester found no implementation defect in these checks. Initial unelevated HTTP probes were blocked by the tool sandbox; permitted elevated probes passed. Normal Docker Desktop use does not require a Codex sandbox override.

For a manual retest, start the stack with `docker compose up -d --build --wait --wait-timeout 300`, confirm healthy services with `docker compose ps --all`, then open `http://localhost:3000` and reload after a minute. Use `docker compose logs --timestamps --tail=100 frontend` for startup failures and inspect `storage/logs/laravel.log` for Laravel records. `docker compose stop` preserves local data; start it again with Compose. Do not remove volumes or reset the database for this check.

A combined local Docker log snapshot was saved as `storage/logs/docker-startup-20261016-113829.log` after the final successful stop/start check. It is ignored runtime output, alongside the newly created Laravel log file; it is not a committed repository artifact.

The read-only startup explorer found no Nuxt exception or lock defect explaining exit `255`; its lifecycle recommendations were applied. No new authentication, authorization, inventory or application business-rule code changed. The previous PHP/UI suites, browser interaction/console checks and security-review role were not rerun for these lifecycle/environment changes; verification targets the actual development container, readiness, HTTP and logging paths. Long-duration stability and a fresh Docker Desktop/WSL engine restart were not tested, because the latter would interrupt unrelated host containers. Automatic recovery of the frontend still requires its backend dependencies to be running.

Configuration references: [Docker Compose init/restart/healthcheck](https://docs.docker.com/reference/compose-file/services/) and [Laravel logging stacks](https://laravel.com/framework/docs/13.x/logging#building-log-stacks).
