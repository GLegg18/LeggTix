# Nuxt customer frontend

[Issue #20](https://github.com/GLegg18/LeggTix/issues/20) adds a lightweight Nuxt customer shell. Laravel remains the only owner of account permissions, verification rules and reservation capacity. This milestone includes the homepage, verified signup, login, account summary, logout, theme controls and an SVG logo studio. Event list/detail, reservation history, booking/cancellation and waitlist interactions belong to [#21](https://github.com/GLegg18/LeggTix/issues/21). Password recovery requires a separate backend workflow.

## Local run

Use Docker Desktop with Linux containers and Compose v2, as described in the root README. No host Node/npm installation is necessary. From the repository root:

```powershell
docker compose up -d --build
docker compose logs -f frontend
```

Open [http://localhost:3000](http://localhost:3000). Laravel remains available at [http://localhost:8000](http://localhost:8000), its API explorer at `/docs/api`, and Mailpit at [http://localhost:8025](http://localhost:8025). Use the email codes in Mailpit to complete registration or login. Ctrl+C stops log following, not the services.

The application has a three-minute health-check startup allowance for Composer installation, including the first download into a fresh dependency volume. PHP source stays bind-mounted, while the app and queue worker share the Linux-native `php-vendor` volume to avoid Windows filesystem overhead when Laravel autoloads its dependencies. Containers use this volume; an existing host `vendor` directory is left untouched. Nuxt waits for a healthy application, then installs its locked dependencies before serving; a running container may still show `health: starting` during that work. The frontend has its own HTTP healthcheck, uses Docker's init process with a direct Nuxt command for signal handling, and allows 20 seconds for graceful shutdown. Its `unless-stopped` policy restarts an unexpected exit; an explicitly stopped container stays stopped until started again. If Composer takes longer than its startup window, wait for `Server running` and a healthy app, then retry `docker compose up -d frontend`.

To adopt changed dependency/volume configuration or apply a changed PHP lockfile, stop the queue worker before the app installs dependencies and restart through Compose so the worker waits for a healthy app:

```powershell
docker compose stop worker
docker compose up -d --build --force-recreate app worker frontend
```

Docker Desktop's Start button does not apply changed Compose configuration or enforce dependency health ordering. Existing containers can be started there after provisioning; use the sequence above after dependency/configuration changes. Stop both `app` and `worker` before manually running Composer commands that change installed dependencies. Preserve the existing database and volumes.

The frontend uses Node 24, Nuxt 4 and Tailwind CSS 4 through its [official Nuxt/Vite integration](https://tailwindcss.com/docs/installation/framework-guides/nuxt). Exact JavaScript dependency versions are recorded in `frontend/package-lock.json`. Compose mounts frontend source for hot reload and uses a 500-millisecond Chokidar polling interval because Windows bind mounts can miss native file-change events; named volumes keep Linux `node_modules` and generated `.nuxt` files inside Docker. `npm ci` runs on startup so a changed lockfile is applied reproducibly. Changing image/runtime configuration requires rebuilding the frontend.

### Existing checkout configuration

Preserve the existing `.env`, application key and database. Add or update only these settings for the default local HTTP setup:

```dotenv
CORS_ALLOWED_ORIGINS=http://localhost:3000
SANCTUM_STATEFUL_DOMAINS=localhost:3000
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
```

Then reload Laravel's environment and clear cached configuration:

```powershell
docker compose stop worker
docker compose up -d --build --force-recreate app worker frontend
docker compose exec app php artisan config:clear
docker compose restart worker
```

The frontend `NUXT_API_BASE` defaults to `http://app:8000` in Compose. This is a **server-only** environment-configured Laravel origin, not a browser URL. Nuxt forwards the browser's API and CSRF requests to Laravel, preserving the intended browser origin and response cookies. For a host-run Nuxt process, point this setting at `http://localhost:8000`; Node 24 and npm are needed only for that optional path. Never configure an untrusted API origin because the proxy forwards authentication cookies.

Use `localhost` consistently. `localhost` and `127.0.0.1` do not share cookies. A different frontend port/origin requires matching changes to the explicit CORS origin and Sanctum domain (including its port). CORS does not use a wildcard. Keep the frontend and API on the same site outside local development. HTTPS is required outside loopback; leave the secure session-cookie default enabled there. The optional trusted-browser cookie retains its Secure, HttpOnly, host-only attributes even during HTTP development; ordinary email-code login does not depend on it.

## Browser authentication

Following [Sanctum's first-party SPA flow](https://laravel.com/framework/docs/13.x/sanctum#spa-authentication), the browser calls `GET /sanctum/csrf-cookie` before mutations, includes cookies and sends the decoded `XSRF-TOKEN` value in `X-XSRF-TOKEN`. The session credential is HttpOnly. Successful SPA registration/login returns a user and establishes a regenerated Laravel session; it does not create or return a bearer token. Nuxt restores the signed-in user from Laravel after a reload. Route guards improve navigation; Laravel enforces authentication.

Laravel serializes session requests across their complete read/action/save cycle. This includes the CSRF handshake and stateful API requests, preventing an overlapping request from restoring a session after logout on a multiworker server. Locks use the default cache store (Redis locally; array inside isolated tests), or an explicit `SESSION_BLOCK_STORE` override. Keep a shared lock-capable store when running multiple workers. The lock lease is 120 seconds, with a 10-second wait; future long-running session endpoints need to revisit that bound.

The SPA routes live under `/api/spa`, with the same bodies, verification rules, expiry and shared throttles as their bearer counterparts:

| Method/path | Purpose |
| --- | --- |
| `POST /api/spa/registration/email-verifications` | Request or resend an ownership code |
| `POST /api/spa/registration/email-verifications/{challenge}/verify` | Exchange the code for a one-use signup proof |
| `POST /api/spa/register` | Consume proof, create a regular customer account and establish the session |
| `POST /api/spa/login` | Check password; return an email challenge or complete trusted-browser login |
| `POST /api/spa/login/email-verifications/{challenge}/verify` | Complete email-factor login and establish the session |
| `GET /api/spa/user` | Restore the session user as `{ "data": ... }` |
| `POST /api/spa/logout` | End the browser session and revoke presented browser trust |
| `DELETE /api/spa/trusted-devices` | Forget trusted browsers and pending login verification |

Authentication secrets, codes and signup proofs stay in transient component memory. They are not stored in browser local/session storage, URLs or logs. Only the non-sensitive theme preference persists in local storage. Reloading a partially completed signup or login restarts that flow. Existing non-browser API clients retain the documented bearer response/expiry contracts in the [authentication guide](authentication.md).

This milestone is a browser-rendered Nuxt shell (`ssr: false`), so account state is loaded on the client rather than serialized into shared HTML. Public-page server rendering and richer search previews can be evaluated alongside the event discovery pages; they are not part of this shell's acceptance.

## Visual and interaction decisions

- **Neon pink with deliberate contrast:** bright pink is the brand/accent colour. Dark text accompanies bright pink surfaces; darker pink supports readable links on light backgrounds. Light and dark themes use their own neutral surfaces instead of blindly inverting colours.
- **Responsive hierarchy:** the homepage introduces the product with a clear primary account action. Flexible grids and fluid type stack on small screens; forms stay at a readable width. Event artwork is illustrative rather than a fabricated live listing or booking control.
- **Accessible navigation:** semantic landmarks, a skip link, visible keyboard focus and descriptive controls make navigation usable beyond a pointer. Small-screen navigation keeps account actions reachable without crowding the header.
- **System-aware appearance:** the initial theme follows the device, with an explicit persisted override. Applying it before the app paints avoids a bright flash when dark mode was selected. A sun/moon slider switches between light and dark; its accessible switch label and checked state communicate the setting without relying only on icon shape. The control supports click, Space and Enter with a 44-pixel touch target, and its animation respects reduced motion.
- **Step-by-step signup:** email ownership comes first, matching Laravel's contract and explaining why a code is needed. A single paste-friendly numeric code input supports autocomplete and leading zeroes. Labels, hints and password visibility support password managers and reduce avoidable mistakes.
- **Actionable feedback:** submitting states prevent duplicate actions, server validation maps to fields, and summaries give recovery guidance for expired codes, session expiry, throttling and network failure. Errors are announced and focus is moved where it helps recovery. Backend validation remains authoritative.
- **Honest scope:** no fabricated customer counts, testimonials, purchasable tickets or working reservation CTAs. Account copy explains available actions without promising unfinished workflows.

The [logo studio](http://localhost:3000/brand) compares three original scalable marks in light, dark and small-size contexts: A, a tilted ticket stub with an L; B, a ticket containing a live pulse; C, a compact LT monogram. A is provisional. SVG files live in `frontend/public/brand/` and can be downloaded from that page. The ticket shape is brand artwork, not an admission credential.

## Verification

Use the isolated MySQL runner in the [testing guide](testing.md) for Laravel acceptance; never point PHPUnit at normal development data. Frontend checks, executed results, review findings and remaining limits are recorded in the [issue #20 validation report](issue-20-validation.md), alongside the [manual acceptance plan](frontend-manual-test-plan.md).

The frontend's `npm run typecheck` and `npm run build` commands run inside the Node 24 environment. `npm test` runs desktop/mobile Chromium Playwright cases against production output with mocked authentication responses, starting `npm run preview` automatically unless `PLAYWRIGHT_BASE_URL` is supplied. Install Chromium with `npx playwright install --with-deps chromium` in a Linux browser-test environment first. The `Nuxt acceptance` CI job performs these steps independently of the MySQL job. CI configuration has been added; a hosted run is not implied by local checks.

When running a build manually in the Compose frontend container, stop the development frontend first so development and build processes do not write the same generated `.nuxt` directory:

```powershell
docker compose stop frontend
docker compose run --rm --no-deps frontend sh -c "npm ci --no-audit --no-fund && npm run typecheck && npm run build"
docker compose up -d frontend
```

The browser tests need their browser runtime; the production build and type check alone do not exercise the browser. See the [manual acceptance plan](frontend-manual-test-plan.md) for the real Laravel/Mailpit account flow and the [validation report](issue-20-validation.md) for actual coverage and any dependency-advisory follow-up.

Automated checks and manual evidence describe their actual scope; they do not establish formal WCAG conformance or replace assistive-technology testing.

## Finding startup errors

Nuxt is a separate Node process. Its install, startup, Vite/Nitro and server errors are written to Docker's frontend log, not Laravel's `storage/logs/laravel.log`:

```powershell
docker compose ps --all
docker compose logs --timestamps --tail=100 frontend
docker compose logs --timestamps --tail=100 app worker
```

Laravel now defaults locally to `LOG_CHANNEL=stack` and `LOG_STACK=single,stderr`, sending application log records both to `storage/logs/laravel.log` and Docker. For an existing `.env` that still says `LOG_STACK=stderr`, change only that line, then recreate `app` and `worker` to reload their environment. A file is created when Laravel actually writes a log record; successful requests alone do not require a log entry. Nuxt failures still need its own Docker log.

To retain a combined timestamped snapshot under the ignored log directory:

```powershell
docker compose logs --no-color --timestamps --tail=300 frontend app worker | Set-Content -LiteralPath .\storage\logs\docker-diagnostics.log
```

If the container exited before writing a useful message, inspect its exact state without dumping environment secrets:

```powershell
$leggTixFrontendId = docker compose ps --all --quiet frontend
docker inspect --format '{{json .State}}' $leggTixFrontendId
```

`ExitCode`, `OOMKilled`, `Error`, `StartedAt` and `FinishedAt` help distinguish an application failure from a stopped container or engine interruption. Preserve those timestamps and the last frontend log lines when reporting a recurrence; an exit code alone does not establish its cause. Do not delete database/dependency volumes or bypass Nuxt's process locks as a routine recovery step.
