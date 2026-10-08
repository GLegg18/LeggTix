# Running automated tests

Start Docker Desktop in Linux-container mode. In PowerShell at the repository root, run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
```

Expected: all tests pass, followed by removal of the temporary test stack. The [issue #6 verification report](issue-6-validation.md) records the latest executed suite and its runtime. The first build needs network access to download images and Composer dependencies. PHP, Composer and MySQL do not need to be installed on Windows. The normal development stack can be running or stopped.

The execution-policy override applies only to this PowerShell process. If your policy already permits local scripts, `.\scripts\test.ps1` can be run directly.

To run just the authentication tests:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=AuthenticationTest
```

Arguments after the script name are forwarded to Laravel's test command. Each invocation still uses a fresh, isolated test database.

To exercise the event model and factory acceptance cases with warnings treated as failures:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=EventModelTest --display-warnings --fail-on-warning
```

## What happens automatically

1. Check Docker Compose and the Linux Docker engine are available.
2. Create a unique `leggtix-test-<random ID>` project using only `docker-compose.test.yml`.
3. Start fresh MySQL 8.4 with its own test database and user, and wait until an authenticated database query succeeds.
4. Build the test image from current source and locked Composer dependencies. Local `.env`, vendor files, cached Laravel configuration and private uploads are excluded. An empty `.env` exists only inside this image.
5. Run `php artisan test --compact`. The tests migrate the schema and create the fixtures they need. No domain seed data is required.
6. In `finally`, remove that project's containers, network, volumes and generated test image. MySQL uses tmpfs storage, so its database data disappears with the container. Shared base images and build cache remain for later runs.

The test stack has no published ports or host mounts. Its internal network and MySQL storage are separate from the development stack. It does not change the host `.env`, normal database, or normal containers. Tests use array cache/session stores and synchronous queues. For live Redis throttling and API responses, follow the [manual acceptance checks](manual-test-plan.md); the [authentication guide](authentication.md) defines the API contract.

## Failures and cleanup

The script returns the test/build failure's nonzero exit code after attempting cleanup. A cleanup failure makes a successful run fail and prints an exact, project-specific retry command. Copy that printed command when Docker is available again.

| Failure | Next step |
| --- | --- |
| Docker or Compose unavailable, or Linux engine stopped | Start Docker Desktop in Linux-container mode and confirm Docker Compose is installed. |
| Image download or dependency build fails | Check the network connection, keep the error output and rerun the same command. |
| A test fails | Keep the failed test name and assertion; rerun with `--filter` when useful. |
| Unsafe database configuration or connection failure | Use this isolated runner, rather than invoking tests in the normal app container; see the database guard below. |
| Cleanup fails | Run the exact retry command printed by the script. |

Cleanup is attempted through `finally`; forcibly terminating PowerShell, shutting down the computer, or losing the Docker engine can prevent it. The opening output records the temporary project name. If cleanup could not run, substitute that exact name below:

```powershell
docker compose --project-name leggtix-test-PASTE-THE-EXACT-ID --file .\docker-compose.test.yml down --volumes --remove-orphans --rmi local
```

Use the exact temporary project printed by the runner. Running this command without the test file and project name would select a different stack.

## Database guard

The suite refuses unsafe database configuration before migrations. It requires `APP_ENV=testing`, the MySQL connection/driver, a database name ending in `_test`, and an actual connected database matching that name. The error identifies rejected settings; a separate message reports a connection failure.

The normal app container inherits `DB_DATABASE=leggtix`, which PHPUnit intentionally does not overwrite automatically. Cached Laravel configuration can also preserve application settings. The isolated runner supplies its own settings and excludes host configuration caches, so no manual database creation or grants are needed.

## Related checks and verification

The [manual acceptance checks](manual-test-plan.md) cover the running app, Redis, authentication, invalid requests and rate limits with copy/paste PowerShell commands. Live requests create demo accounts in the normal local database; the automated runner's fixtures are disposable.

The [issue #5 verification report](issue-5-validation.md) records completed automated/manual checks, review findings, exercised cleanup paths and limits, including behavior that has not been tested.

The [issue #6 verification report](issue-6-validation.md) records event model/factory verification and the developer, tester and security review outcomes. The [event guide](events.md) explains the model contracts and relevant manual checks.

The runner does not add booking workflows, domain seeds, browser tests or capacity-race coverage. Those remain downstream work; the [database design](database-design.md) records the required locking and concurrency checks.
