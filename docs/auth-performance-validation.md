# Local authentication performance investigation

## Request and architecture

On 16 October 2026, login and logout felt slow in the Windows Docker Desktop setup. The browser already uses JavaScript → Nuxt's same-origin proxy → Laravel/PHP → MySQL and the session store. An API endpoint is the PHP entry point, not an additional authentication system. Replacing it with another PHP URL would still require an HTTP request and server-side validation. Browser code must not receive database credentials or become the authority for authentication.

The frontend uses the successful login response to update its user state and makes one logout request. There is no automatic duplicate `/user` fetch after either action. A page reload intentionally restores the session from Laravel. The CSRF handshake runs when its cookie is absent, with a bounded retry after session expiry. Email is queued; SMTP delivery is not performed inside the password-login request.

## Cause and change

Even Laravel's `/up` health endpoint, which does not authenticate or query the application database, frequently took about 2.2 seconds. The PHP runtime and Composer dependencies were all read through the repository's Windows bind mount. A controlled comparison with the same image, source, environment and PHP built-in server demonstrated substantially lower latency when only `vendor` moved to a Linux Docker volume.

`docker-compose.yml` now mounts a shared `php-vendor` volume at `/var/www/html/vendor` for both `app` and `worker`. Source, storage and logs remain in the existing repository bind mount. Composer still installs locked dependencies before the application becomes healthy; Compose then starts the email worker. Existing host dependencies, application key and database contents were preserved. The active local containers were recreated with this layout.

No authentication endpoint, password cost, email-factor requirement, session store, session lock, or cookie policy changed. PHP opcode-cache experiments did not justify a configuration change: forcing timestamp checks on every request made the Windows-mounted case slower. The committed fix changes dependency storage only.

The PHP development server still handles requests serially, so unrelated long requests can queue. This is a development measurement, not a production capacity or concurrency claim.

## Controlled filesystem comparison

Temporary internal-only servers used the same `leggtix-app` image, repository bind, router and `opcache.enable_cli=0`. They had no healthchecks or published ports. Requests alternated between Windows-mounted dependencies and the copied Linux dependency volume. The dependency copy had finished before measurement. Each endpoint has six samples, including the first request; all expected statuses matched.

| Endpoint | Windows vendor: milliseconds | Linux vendor: milliseconds |
| --- | --- | --- |
| `GET /up` (200) | 4168, 1666, 142, 145, 152, 143 | 274, 44, 43, 47, 48, 73 |
| Guest `GET /api/spa/user` (401) | 2020, 307, 238, 157, 4476, 1864 | 84, 59, 59, 82, 97, 55 |

These small local samples show the filesystem effect and variability; they do not establish a fixed speedup or percentile guarantee. The earlier live-stack baseline also showed roughly 0.4–2.3 seconds for guest auth/CSRF requests, before the dependency-copy experiment began.

## Verification and review

The developer implemented the volume mounts and setup/dependency-update documentation in `docker-compose.yml`, `README.md` and `docs/frontend.md`. `docker compose config --quiet`, `git diff --check` and `composer check-platform-reqs` passed. Runtime inspection confirmed app and worker share the same native dependency volume. The existing first-run Composer command populates the volume; host `vendor` is now intentionally unused by containers. Stop the worker before dependency updates, and use Compose to recreate services and honor readiness ordering. Docker Desktop's Start button does not apply changed volume definitions.

The tester used Chromium against the actual Nuxt → Laravel → MySQL/Redis/Mailpit flow, with one unique development-only account. The baseline completed ownership verification and signup, then login and logout. Two post-change cycles reused the same account with fresh legitimate emailed codes. No API responses were mocked. Timings below are API duration followed by total visible-action duration; ranges cover both post-change cycles.

| Operation | Before: milliseconds | After: milliseconds |
| --- | --- | --- |
| Password submission (202; email code still required) | 8224 / 8273 | 300–374 / 356–437 |
| Correct email-code verification (200) | 692 / 730 | 104–126 / 171–188 |
| Logout (204) | 702 / 759 | 86–189 / 140–256 |
| Authenticated user reads (200; API only) | 244, 4065, 3239 | 66–110 |
| Reload and session restore | 1667 / 1762 | 80–118 / 181–221 |
| Post-logout user read (401; API only) | 4332 | 81–89 |

The earlier signup logout took 5217 / 5627 milliseconds, illustrating how variable the baseline was. These are a few local development runs, not an average, guarantee or production benchmark. Password hashing deliberately still takes work. Queued email delivery was measured separately: one post-change code arrived about 1037 milliseconds after password submission completed, which includes worker polling and is not API latency.

Tester acceptance passed:

- Signed-out and pre-email-factor `/user` requests returned 401; login did not establish authentication prematurely.
- Successful verification returned the correct user, without an `access_token`; reload restored that session.
- Logout returned 204 and a subsequent user read returned 401. The interface waited for the server response.
- No authentication secrets were stored in browser local/session storage. There were zero uncaught browser errors. A second cycle classified all three console resource errors as the expected 401 responses, with no other console errors.
- A read-only database check confirmed the fixture's email was verified, its login code consumed, and zero bearer tokens, trusted devices, events or reservations attached to that account.

The security reviewer found no actionable defect in this scope. The recommendation to stop a running worker before replacing shared dependencies was **fixed** in the setup documentation. There are no deferred or not-reproduced security findings for this change. Session serialization and authorization remain unchanged. The reviewer independently checked Compose validity and the live shared mounts; browser behavior was verified by the tester.

All normal services were running after verification, with app, frontend, MySQL, Redis and Mailpit healthy. The completed temporary benchmark containers and their temporary dependency volume were removed. No database volumes were removed.

The unique tester account `auth-performance-1792151129526@example.test` remains signed out in the development database, with its local Mailpit messages and no events or reservations. Its temporary plaintext random credential file was deleted and absence confirmed. Other accounts and existing fixtures were preserved. The tester's helper scripts are ignored local artifacts; no committed application tests were added for this storage-only change.

This infrastructure-only follow-up did not rerun the full PHPUnit suite, frontend production build, dependency audits, fresh-volume network installation/failure tests or a multiworker race. Dependencies and authentication source did not change; earlier #20 checks are recorded in [the milestone report](issue-20-validation.md).

## Repeatable manual check

1. Apply the current Compose layout using the dependency-update sequence in [the frontend guide](frontend.md#local-run), preserving `.env`, the application key and database volumes. Expect a healthy app before the worker starts.
2. Open `http://localhost:3000/login` and browser Network tools. Submit an existing verified account's password. Expect one password-login POST returning 202 and the email-code screen; no duplicate automatic user request. A fresh browser may first make the documented CSRF handshake and initial session restore.
3. Open Mailpit at `http://localhost:8025`, copy that account's current login code and submit it. Expect 200, the account page and the correct identity. Code-delivery time is separate from password-request time.
4. Reload the account page. Expect one session restore request returning 200 and the same account.
5. Log out. Expect one POST returning 204, the signed-out interface and a subsequent protected user request returning 401. Repeat login later with a fresh emailed code; local authentication requests should normally be a fraction of a second on the measured machine.
6. Restart the already-configured app/worker through Compose and repeat. Expect Composer to use the shared dependency volume and the worker to start after app health. Docker Desktop Start can start these existing containers, but configuration changes still require Compose recreation.
