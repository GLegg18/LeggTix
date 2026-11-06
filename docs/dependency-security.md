# Dependency and runtime security maintenance

PHP, Composer, Node and npm run in Docker. Updating a Windows PHP installation does not update this application. Refresh the images, install the reviewed lockfiles and recreate the affected containers; restarting an old container alone retains its old runtime.

The [6 November 2026 validation report](dependency-security-validation.md) records the audit findings, chosen updates, checks and remaining limits. Earlier milestone audits are historical snapshots, not current guarantees.

## Runtime baseline

The backend uses `php:8.4.27-cli-bookworm` for the app, worker and isolated test image. Composer requires `~8.4.27`, keeping this project's supported runtime on patched PHP 8.4. The frontend development/production images use `node:24.22.2-bookworm-slim`; the frontend CI job uses `node:24.22.2-bookworm`. Its engine requirement is `>=24.22.2 <25`.

PHP 8.4.27 fixes the two October emergency remote-code-execution vulnerabilities, CVE-2026-109618 and CVE-2026-109619. The [PHP security announcement](https://www.php.net/archive/2026.php#2026-10-21-5) recommends updating; application request validation does not replace a runtime patch.

A later [PHP advisory for CVE-2026-115560](https://www.php.net/archive/2026.php#2026-10-23-1) remains unpatched in the current stable PHP 8.4.27 release. `docker/php/security.ini` disables the unused native PHP upload-progress feature at startup, following the upstream mitigation. It is copied into every backend image stage. Changing Laravel config or calling `ini_set()` in application code would be too late for upload-progress parsing. This feature is separate from Laravel's own session storage; browser login remains enabled. Keep the setting disabled unless a reviewed feature needs it, and apply the upstream runtime fix when released.

Node 24.22.2 includes the fixes from the [October Node security release](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases). The [announced 11 November Node security release](https://nodejs.org/en/blog/vulnerability/november-2026-security-releases) was not yet available on this review date; recheck and apply its Node 24 patch when published.

This Node release still bundles Undici 7.30.1. Updating the npm dependency to 8.12.4 does not replace Node's built-in `fetch` implementation. Evaluate upstream advisory preconditions separately and recheck the bundled version with the next Node patch; the November Node announcement does not specify which Undici changes it will include.

## Review and install an update

Check upstream release notes and affected/fixed ranges as well as package audits. npm/Composer databases can lag new advisories or retain rejected records. Audit output is a signal to investigate; do not silence it or force an unsupported downgrade merely to reduce the count.

Use targeted compatible updates first. Keep root package pins, generated lockfiles, Docker runtime tags and CI runtime tags consistent. A deliberate transitive override crossing a parent's major-version range also needs inspection of that parent's API use, a production build and interaction regressions. Revisit such overrides when the parent adopts the fixed major.

`npm run test:dependency-compat` exercises NFT's actual dynamic asset tracing and readdir-glob's pattern/ignore/skip/dotfile behavior through the installed parent packages; CI runs it after installation. Update the asserted versions and re-review compatibility when changing either override. The exact `esbuild@0.28.2` install script is reviewed and explicitly allowed; inspect a new script before changing that approval. `npm install-scripts ls` should show no unreviewed dependency scripts.

From the repository root, after reviewing the source and lockfile changes:

```powershell
docker compose build --pull app worker frontend
docker compose stop worker app frontend
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
docker compose run --rm --no-deps app php artisan route:clear
docker compose up -d --force-recreate app worker frontend
```

The stopped app/worker cannot read the shared vendor volume while Composer replaces packages. The frontend installs its locked dependencies on startup. The route cache clear applies Symfony's routing security-update instruction without flushing Redis, sessions or application data. Preserve the existing `.env`, key, database, inbox and named volumes.

Check the running processes, including the long-lived queue worker:

```powershell
docker compose exec app php --version
docker compose exec worker php --version
docker compose exec app php --ri session
docker compose exec worker php --ri session
docker compose exec frontend node --version
docker compose exec app composer check-platform-reqs
docker compose ps --all
```

Expect the reviewed versions, `session.upload_progress.enabled => Off`, healthy app/frontend dependencies and a running worker. Recheck the committed event time-zone snapshot against `timezone_identifiers_list()` after a PHP/time-zone-data update.

## Audit and verify

```powershell
docker compose exec app composer audit --locked
docker compose exec app composer audit --locked --no-dev
docker compose exec frontend npm audit
docker compose exec frontend npm audit --omit=dev
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
```

Composer audits assess PHP packages, not the PHP engine or operating-system image. npm audits assess the dependency tree, not every bundled production path or the Node runtime. Inspect production output when assessing reachability; absence from that output does not prove a development/build dependency harmless. Record unresolved entries and their exact status in the validation report.

For frontend updates, run locked installation, type checking, production build and desktop/mobile Playwright regressions in a separate working copy or with the development frontend stopped, so generated `.nuxt` files do not collide. The [frontend guide](frontend.md) and [testing guide](testing.md) describe these checks. Follow with a real Laravel/Mailpit login and event-management smoke check; mocked browser tests alone do not establish live authentication compatibility.
