# Automated tests with Docker

Start Docker Desktop in Linux-container mode. In PowerShell at the repository root, run:

```powershell
.\scripts\test.ps1
```

Expected: **106 passed (622 assertions)**, followed by removal of the temporary test stack. The first build needs network access to download images and Composer dependencies. PHP, Composer and MySQL do not need to be installed on Windows. The normal development stack can be running or stopped.

To run just the authentication tests:

```powershell
.\scripts\test.ps1 --filter=AuthenticationTest
```

If PowerShell blocks local scripts, use a policy override for this process:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
```

## What happens automatically

1. Check Docker Compose and the Linux Docker engine are available.
2. Create a unique `leggtix-test-<random ID>` project using only `docker-compose.test.yml`.
3. Start fresh MySQL 8.4 with its own test database and user, and wait until an authenticated database query succeeds.
4. Build the test image from current source and locked Composer dependencies. Local `.env`, vendor files, cached Laravel configuration and private uploads are excluded. An empty `.env` exists only inside this image.
5. Run `php artisan test --compact`. The tests migrate the schema and create the fixtures they need. No domain seed data is required.
6. In `finally`, remove that project's containers, network, volumes and generated test image. MySQL uses tmpfs storage, so its database data disappears with the container. Shared base images and build cache remain for later runs.

The test stack has no published ports or host mounts. Its internal network and MySQL storage are separate from the development stack. It does not change the host `.env`, normal database, or normal containers. Tests use array cache/session stores and synchronous queues; live Redis throttling is checked in the [manual walkthrough](manual-test-plan.md).

## Failures and cleanup

The script returns the test/build failure's nonzero exit code after attempting cleanup. A cleanup failure makes a successful run fail and prints an exact, project-specific retry command. Copy that printed command when Docker is available again.

Cleanup is attempted through `finally`; forcibly terminating PowerShell, shutting down the computer, or losing the Docker engine can prevent it. Real Ctrl+C and forced-termination behavior have not been tested. The opening output records the temporary project name. If cleanup could not run, substitute that exact name below:

```powershell
docker compose --project-name leggtix-test-PASTE-THE-EXACT-ID --file .\docker-compose.test.yml down --volumes --remove-orphans --rmi local
```

Use the exact temporary project printed by the runner. Running this command without the test file and project name would select a different stack.

## Why the earlier command failed

`Tests require APP_ENV=testing and a dedicated MySQL database ending in _test` is the safety guard refusing configuration before migrations. It does not establish that database creation failed. The normal app container inherits `DB_DATABASE=leggtix`, which PHPUnit intentionally does not overwrite automatically; cached Laravel configuration can also preserve application settings.

The isolated runner supplies its own settings and excludes host configuration caches. The guard still verifies the environment, MySQL connection/driver, database suffix and actual connected database. Its error now identifies the configuration it rejected and points to the script. Connection failures get a separate setup message.

## Verification and handoffs

- Developer implemented the runner, isolated Compose stack, test build stage, Docker context exclusions and clearer safety errors. Application authentication and schema behavior are unchanged.
- Coordinator ran the complete suite against real Docker/MySQL: **106 tests, 622 assertions**, **9.90 seconds**, exit `0`, with `--display-warnings --fail-on-warning`. No warnings remained. Temporary containers, network and generated image were removed.
- An intentionally invalid runner invocation under Windows PowerShell 5.1 returned exit `1` and still removed its temporary stack and image. Direct, network-disabled container probes rejected `DB_DATABASE=leggtix` before connecting and reported an unavailable test connection before migrations; both exited `1` with zero assertions, as expected.
- Final checks found zero containers, networks, volumes or images for all four projects created during verification. The original app/MySQL/Redis container IDs and start times were unchanged. App health returned `200`, Redis returned `PONG`, and all six normal migrations remained `Ran`. Host `.env`, `vendor/autoload.php` and bootstrap cache file hashes were unchanged.
- Tester passed **28 runner regression cases**, 14 each on Windows PowerShell 5.1 and PowerShell 7. They cover preflight failures, success/failure including native stderr, argument forwarding with spaces, distinct project names, exact cleanup scope, preserved failure codes, and cleanup failures. These use a mock Docker CLI; they do not substitute for the real database run.
- Security reviewer found no unresolved security or data-integrity defect. Reviewed isolation, configuration exclusions, guard timing and cleanup scope. Compose v5.5.1's dangling-image cleanup filters by the exact project label; it does not globally prune images. See its [image cleanup implementation](https://raw.githubusercontent.com/docker/compose/v5.5.1/pkg/compose/image_pruner.go).
- The first real build produced phpdotenv missing-file warnings in every test. Creating an empty `.env` inside the image fixed those warnings without copying local secrets; the complete warning-free rerun verified the fix.
- Unique generated image tags initially survived teardown. Adding project-scoped `--rmi local` to cleanup and the retry command fixed the accumulation; the final run removed its generated image.

PowerShell syntax checks, PHP lint for the changed test base, documentation command parsing/link checks and `git diff --check` passed. Real concurrent runs, Ctrl+C and force-kill cleanup were not exercised. Developer, tester and security-reviewer handoffs are complete with no deferred or unresolved findings for this runner.

For manual acceptance, follow the [beginner walkthrough](manual-test-plan.md): Docker health `200`, Redis `PONG`, six migrations `Ran`; automated suite passes; register `201`, current user/login `200`, logout `204`; guest/revoked token `401`, invalid input/role `422`, excessive requests `429`. Logging out one token must leave the other usable. The full live authentication walkthrough was not repeated for this infrastructure change.

The runner does not add booking workflows, domain seeds, browser tests or capacity-race coverage. Those remain downstream work as recorded in the [original issue #5 report](issue-5-validation.md).
