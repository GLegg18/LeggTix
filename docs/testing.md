# Running automated tests

Start Docker Desktop in Linux-container mode. In PowerShell at the repository root, run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
```

Expected: all tests pass, followed by removal of the temporary test stack. The [issue #9 validation report](issue-9-validation.md) records booking and independent MySQL race verification; the [demo data verification report](demo-data-validation.md) and [issue #6 verification report](issue-6-validation.md) record earlier milestones. The first build needs network access to download images and Composer dependencies. PHP, Composer and MySQL do not need to be installed on Windows. The normal development stack can be running or stopped.

The execution-policy override applies only to this PowerShell process. If your policy already permits local scripts, `.\scripts\test.ps1` can be run directly.

## Continuous integration

[The Tests workflow](../.github/workflows/tests.yml) runs on pushes and pull requests, and supports manual dispatch. `MySQL acceptance` runs the same disposable Compose suite on a Linux runner with PowerShell 7. `Windows wrapper (powershell)` and `Windows wrapper (pwsh)` exercise the runner's success, failure, argument forwarding and cleanup cases on Windows, using a mock Docker command rather than a Windows database stack. The checkout action is pinned to a reviewed commit with read-only repository permissions and credential persistence disabled.

These jobs become available after the workflow is pushed. Their first hosted execution must still be checked. To prevent merging failing changes, configure the protected branch or ruleset to require these three status checks; committing a workflow alone does not enforce branch protection.

Run the wrapper checks locally without starting Docker:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\tests\Test-TestRunner.ps1
pwsh -NoProfile -ExecutionPolicy Bypass -File .\scripts\tests\Test-TestRunner.ps1
```

Expect 14 passing cases per shell and unchanged application configuration. The Dockerfile pins the PHP Redis extension to `6.3.0`; update that version deliberately when rebuilding the runtime.

To run just the authentication tests:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=AuthenticationTest
```

Arguments after the script name are forwarded to Laravel's test command. Each invocation still uses a fresh, isolated test database.

To exercise the event model and factory acceptance cases with warnings treated as failures:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=EventModelTest --display-warnings --fail-on-warning
```

For local demo seeder acceptance:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=DemoSeederTest --display-warnings --fail-on-warning
```

For reservation business/API acceptance and the independent-process MySQL races:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=Reservation --display-warnings --fail-on-warning
```

The race class uses committed fixtures and separate PHP processes with independent MySQL connections. It coordinates contenders around an event lock and checks the final committed counter and confirmed rows. Barrier waits are bounded and worker errors fail the case. These are correctness checks for booking, not a load benchmark or the complete cancellation/waitlist race suite.

The concurrency suite also exercises real MySQL lock-timeout retry exhaustion through the Laravel HTTP kernel. It sets a one-second timeout only on its isolated worker session, verifies the three-attempt busy response and no allocation, then confirms booking after releasing the lock. It does not change normal database timeout settings.

To verify the running local app, PHP request parsing and Redis throttling:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify-reservations.ps1
```

This live runner requires the normal app/MySQL/Redis stack and `APP_ENV=local`. It creates dedicated tagged fixtures, sends real HTTP requests, checks committed rows, verifies equivalent-email login budgets and booking throttles in Redis, and deletes only its captured fixtures under event locks. Tokens are omitted from logs. Token-free recovery metadata is saved under ignored `storage/app/private` until cleanup succeeds; a cleanup failure prints an exact recovery command. Existing application data and shared Redis keys are preserved. See the [manual plan](reservation-manual-test-plan.md) for the detailed walkthrough and the [review follow-up checklist](issue-9-review-manual-checks.md) for morning acceptance.

To check the interactive API reference, generated contracts, local assets and environment guards:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --filter=ApiDocumentationTest --display-warnings --fail-on-warning
```

Open the [Swagger explorer](http://localhost:8000/docs/api) for a browser walkthrough. The [OpenAPI JSON](http://localhost:8000/docs/api.json) is the corrected specification used by the UI. Both require a local/testing environment and development dependencies. Login returns `access_token`; paste its raw value into **Authorize**. Swagger adds the `Bearer` prefix. Refreshing the page forgets authorization, and **Try it out** sends real requests.

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

The runner exercises the local demo seeders in their acceptance cases; normal tests create their own fixtures. Reservation tests cover booking and its MySQL races. Cancellation, waitlist/promotion races, load benchmarks and automated browser regression tests remain downstream work; the [database design](database-design.md) records the full verification contract. The [issue #9 report](issue-9-validation.md) records the live API and exploratory Swagger browser checks actually performed.
