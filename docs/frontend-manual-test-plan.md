# Frontend manual acceptance — issue #20

This plan covers the homepage, account registration with email ownership, password plus emailed-code login, the account shell, logout, responsive layouts, themes and logo options. Event/reservation screens belong to issue #21; password recovery is separate work. Use a local development database and invented `@example.test` addresses. Do not run PHPUnit in the normal app container.

## Prerequisites and startup

1. Install Git and Docker Desktop on Windows, select its WSL 2 backend and Linux containers, then open PowerShell. Run the following from the checkout. If your checkout is elsewhere, replace only the first path:

   ```powershell
   Set-Location C:\Users\georg\Documents\LeggTix
   docker version
   docker compose version
   ```

   Expected: Docker reports both a client and a running server; Compose reports version 2 or newer. Docker must be able to download images/dependencies on the first run. Ports 3000, 8000, 3306, 6379 and 8025 must be free.

2. For a new checkout, create the environment file and key without replacing existing configuration:

   ```powershell
   $leggTixNewEnvironment = -not (Test-Path -LiteralPath .env)
   if ($leggTixNewEnvironment) { Copy-Item .env.example .env }
   docker compose build app worker
   docker compose run --rm --no-deps app composer install --no-interaction
   if ($leggTixNewEnvironment) { docker compose run --rm --no-deps app php artisan key:generate }
   ```

   Expected: dependencies install and a newly generated environment has an application key. Preserve an existing `.env` and key. PHP, Composer, Node and npm are supplied by Docker.

3. For an existing checkout, open `.env` in your editor and set these four local HTTP values, leaving other settings intact:

   ```dotenv
   CORS_ALLOWED_ORIGINS=http://localhost:3000
   SANCTUM_STATEFUL_DOMAINS=localhost:3000
   SESSION_SECURE_COOKIE=false
   SESSION_SAME_SITE=lax
   ```

   Start/reload the services:

   ```powershell
   docker compose up -d --build
   docker compose up -d --force-recreate app frontend
   docker compose exec app php artisan config:clear
   docker compose exec app php artisan migrate
   docker compose restart worker
   docker compose ps
   ```

   Expected: app, frontend, worker, MySQL, Redis and Mailpit are running; services with health checks become healthy. Wait for dependency installation with `docker compose logs -f frontend`, then press Ctrl+C when Nuxt says it is ready. Ctrl+C only stops following logs. If migration initially fails while the app is starting, wait for app health then retry the migration. Seed data is not needed for the fresh-account flow below. Optional demo accounts can be added with `docker compose exec app php artisan db:seed`; it preserves existing passwords and history.

4. Open `http://localhost:8000/up`, `http://localhost:3000` and, in a separate tab, `http://localhost:8025`. Expected: successful Laravel health page, the LeggTix homepage and the Mailpit inbox. Always use `localhost`, including Mailpit and the frontend. Mixing `127.0.0.1` and `localhost` changes cookie scope.

## Homepage, keyboard, themes and logos

5. On the homepage, confirm that the pink ticket mark and LeggTix name link home, **Get started**, **Sign in** and **Create account** are reachable. Read the content: it must not present fabricated live event inventory, working booking controls or fabricated customer statistics. Open `/account` while signed out. Expected: a **Let’s get you signed in.** panel offers **Sign in**, without showing account details.

6. Reload the homepage, then use only Tab, Shift+Tab and Enter. The first Tab should expose **Skip to content**; Enter moves focus to the main content. Continue through navigation and account controls. Expected: visible focus, logical order, no keyboard trap, no requirement to hover. On a form, every input has a visible label and can be reached with the keyboard; password visibility buttons work with Enter/Space without submitting the form.

7. Use the **Dark mode** sun/moon slider, navigate to sign in, then reload. Expected: the moon/on state gives dark surfaces; the sun/off state gives light surfaces; text and pink actions remain readable; the explicit preference persists. Tab to the switch and use Space or Enter; both must toggle it and update the announced checked state. In a fresh browser profile with no saved preference, changing the operating system/browser colour scheme must update the page and slider until the user chooses a setting. Only the harmless theme preference should be stored in local storage; passwords, email codes, registration proofs and API credentials must not be stored there or in session storage.

8. Open `http://localhost:3000/brand`. Compare the ticket-L, pulse ticket and LT monogram options, including the small marks and light/dark examples. Open each SVG download. Expected: a scalable SVG with no missing external image/font dependency. Return home through the logo.

9. Use the browser's responsive device toolbar (F12, then the device icon) and set a viewport width of 375 pixels, then 320 pixels. Repeat homepage, registration, login, account and brand pages; also inspect a tablet width of 768 pixels and desktop width of 1440 pixels. Expected: no horizontal scrolling, clipped text or controls, overlapping navigation, unreadably small form text, or inaccessible primary actions. At 200% browser zoom, controls remain usable and content reflows. Switch light/dark at a mobile size too.

## Fresh registration and signed-in session

10. Click **Create account**, enter a new invented address such as `frontend-check-20261016-01@example.test`, then click **Send verification code**. If the address already exists, increase the final number. Record the exact address for cleanup. Immediately try the action again if the UI permits it. Expected: one pending action at a time; **Check your inbox.** shows the address, a **Six-digit code** field and a disabled **Resend in …s** action. No account has been created yet.

11. In Mailpit, open the newest ownership-verification message addressed to that exact email. Copy its six-digit code, including any leading zero. Return to LeggTix, enter a deliberately wrong six-digit code chosen to differ from the email, and click **Verify email**. Expected: a readable error summary receives focus, the code field is identified, and the form remains recoverable. Enter the correct code and click **Verify email** once. Expected: **Make it yours.** shows **Your name**, **Password** and **Confirm password**. Do not exhaust all five allowed wrong guesses unless intentionally testing a fresh disposable challenge.

12. Enter name `Frontend Acceptance`, password `Frontend-example-2026!`, and a deliberately different confirmation. Submit. Expected: field-specific validation, no account/session, and no loss of the verified flow. Correct confirmation to the same password and submit once. Expected: successful account page, exact name/email, regular customer role if shown, and a welcome email in Mailpit. No second factor is needed immediately after verified signup. Password fields should support paste/password managers and the show/hide button should preserve their values.

13. Reload `/account`, then close/reopen the tab at `http://localhost:3000/account`. Expected: the same account is restored through the server session and the header offers the account action. In browser Developer Tools → Network inspect `/api/spa/user`: expected `200`, a `data` object with only `id`, `name`, `email`, `role`, and `Cache-Control` containing `no-store`. Inspect successful registration: expected `201`, only a `user` profile, no `access_token`. Developer Tools → Application/Storage → Cookies should show a session cookie marked HttpOnly and an XSRF cookie readable to the app; no authentication token belongs in local/session storage. Do not copy cookie/credential values into logs or screenshots.

14. Click **Sign out** in **Your details**. Expected: logout `204`, the sign-in page, signed-out navigation and inaccessible account details after reload. Open `/account` directly and use the browser Back button. Expected: protected data remains unavailable. Repeated logout must not cause a server error or restore the old session.

## Login, failure paths and recovery

15. Open `http://localhost:3000/login`. Submit the recorded email with a wrong password once. Expected: generic credentials error that does not distinguish unknown email from a wrong password, field association and focused error summary. Submit the correct password from step 12. Expected: `202`, a code-entry step and no signed-in account yet. Opening `/account` in a second tab before code completion must still require sign in.

16. In Mailpit open the newest login-verification email for the address. Submit a wrong code once using **Verify and sign in**, then the correct six-digit code with **Remember this browser for 7 days** left off. Expected: wrong code gives a recoverable field error; correct code gives `200`, only the user profile, and the signed-in account. Reload to confirm restoration. Replaying a successful verification request from Developer Tools must return `422` and issue no second credential; never paste codes into shared logs. Sign out. A new password login must ask for a new email code.

17. Test **Remember this browser** using a fresh successful login code. After completing the code, inspect the `__Host-leggtix_trusted_device` cookie: expected Secure, HttpOnly, SameSite=Strict, Path=/, no Domain and a bounded seven-day expiry. Some browsers do not support Secure cookies on HTTP localhost; use an HTTPS local setup to establish this check there. Do not weaken cookie flags. Logout deliberately revokes that browser trust, so it must ask for a code again. On the account page, the forget-trusted-browsers action should show success and preserve the current signed-in session while clearing trust/pending login challenges for that account only.

18. At a login code step, reload the page. Expected: login starts again; transient code/proof/password state is not recovered from storage or a URL. For expiry, request a code and wait more than ten minutes before submitting it. Expected: recoverable expired/invalid-code feedback and a route to obtain a fresh code. A code resend after its cooldown must invalidate the previous generation; use the newest Mailpit message/reference.

19. For CSRF recovery, sign in with an ordinary code, open Developer Tools → Application/Storage → Cookies and delete only `XSRF-TOKEN`. Click logout. Expected: a new `/sanctum/csrf-cookie` handshake precedes the mutation and logout succeeds. To check stale CSRF, replace only that cookie value with `invalid`, then submit a login attempt. Expected: the first mutation returns `419`, the app fetches a new CSRF cookie and retries once, then displays the real result. It must not loop or duplicate accepted work. Do not edit the HttpOnly session cookie.

20. For throttling, use a fresh invented login address and any dummy password. Submit wrong credentials until the server returns `429` (five account attempts within a minute normally reaches the account limit), avoiding existing accounts. Expected: a wait message based on `Retry-After`, disabled submit/countdown during the delay, no misleading credentials error, and recovery after the stated wait. Do not flush Redis to clear a limit. For an offline path, use Developer Tools → Network → Offline and submit once. Expected: actionable network feedback, no indefinite spinner, no claimed success; restore **No throttling/Online** before retrying.

21. To inspect unauthenticated/origin rejection without changing data, run this in PowerShell:

   ```powershell
   curl.exe -i -H "Accept: application/json" -H "Origin: http://localhost:3000" http://localhost:8000/api/spa/user
   curl.exe -i -H "Accept: application/json" -H "Origin: https://untrusted.example" http://localhost:8000/api/spa/user
   curl.exe -i -X OPTIONS -H "Origin: https://untrusted.example" -H "Access-Control-Request-Method: POST" http://localhost:8000/api/spa/login
   ```

   Expected: first call `401` because command-line curl has no browser session; second `403`; untrusted preflight has no permissive `Access-Control-Allow-Origin`/credential grant. A bearer token alone must not authenticate SPA `/user`. SPA cookies must not bypass CSRF on state-changing protected API operations. The isolated backend tests cover those boundaries without exposing real tokens.

22. Open Developer Tools → Console and Network after the flows. Expected: no unexplained client exceptions, broken assets or failed redirects. An initial signed-out `/api/spa/user` `401`, deliberate validation `422`, CSRF `419` and rate-limit `429` from the above cases are expected. Inspect pending submits: buttons should disable while requests are in flight, with descriptive loading text. Never export a HAR containing cookies, passwords or verification secrets into the repository.

## Automated regression, diagnostics and cleanup

23. From the repository root run the isolated MySQL suite, including the session/bearer boundaries and existing reservation invariants:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 --display-warnings --fail-on-warning
   ```

   Expected: all tests pass and the uniquely named temporary test stack is removed. Never replace this with PHPUnit in the normal app container. Reservation capacity is unchanged by this frontend ticket; existing MySQL concurrency tests establish regression coverage. Booking/cancellation/waitlist UI checks are not applicable to this milestone.

24. If an email or response is missing, run these from the root:

   ```powershell
   docker compose logs --tail=100 frontend app worker mailpit
   docker compose exec app php artisan queue:failed
   ```

   Expected: frontend/app are serving, worker handles the `emails` queue, Mailpit is reachable, and a clean run has no failed jobs. Keep only sanitized error excerpts. A failed email job can be retried individually after resolving its cause with `docker compose exec app php artisan queue:retry PASTE-ITS-UUID`; avoid retrying all unrelated jobs.

25. Log out, close test tabs, restore Network to Online, restore normal viewport/zoom, and leave the theme slider in the preferred light/dark setting. To keep data but stop services, run `docker compose down`. Do not use `migrate:fresh`, `down --volumes` or Redis flushing as routine cleanup: they erase unrelated development state. Test accounts are harmless local review fixtures; retain the recorded address or remove only that exact fixture after confirming it has no events/reservations/waitlist history. Mailpit messages may be deleted individually by their exact recipient; do not empty an inbox containing unrelated review mail. Temporary automated test fixtures are removed by the isolated runner.

## Execution evidence

The numbered steps above are a reproducible plan, not a claim that every step has run. The issue #20 validation report records actual command results, browser checks, defects/fixes and remaining gaps separately.

### Actual local browser acceptance — 16 October 2026

The resumed acceptance run used the Codex in-app Chromium browser against the running Nuxt frontend (`localhost:3000`), Laravel (`localhost:8000`) and local Mailpit (`localhost:8025`). Authentication responses were **not mocked** for these checks. The normal development database was preserved; the existing acceptance account was not reset.

| Check performed | Observed result |
| --- | --- |
| Open login with the earlier acceptance session | The existing `frontend-live-20261016-acceptance@example.test` session restored and redirected to its correct account. Logout then returned to login; a fresh `/account` showed only the signed-out panel. This proves restoration of the earlier session, rather than independently establishing how its original login completed. |
| Fresh verified signup | Created one dedicated local fixture, `frontend-live-20261016-final@example.test` / `Frontend Final Acceptance`. The ownership email arrived in Mailpit, its real code opened the profile form, completing the form opened `/account?welcome=1` with the correct identity, and the welcome email arrived. |
| Explicit password plus email-factor login after signup logout | The fixture's password produced the code-entry screen and a real login email in Mailpit. A second tab at `/account` remained signed out before code completion. |
| Wrong login code, followed by correct code | One deliberately wrong code showed recoverable invalid/expired-code feedback. Read-only DOM inspection confirmed that the error summary held focus and the code input had `aria-invalid="true"`. The correct code then opened the correct account, with **Remember this browser for 7 days** left off. |
| Reload and final logout | Reload restored the signed-in fixture. Final logout returned to `/login`; a fresh direct `/account` displayed **Let's get you signed in.** and no account details. |
| Console and visual observation | Browser warning/error logs were empty after session restoration and at the final signed-out account check. Desktop account and email-code screens were visually inspected. No network interception/status capture was performed by this browser tool. |

Earlier acceptance in the same work item separately recorded desktop homepage light/system-dark checks, signup with a wrong ownership code, wrong-password feedback, forgetting remembered browsers while retaining the session, and the corrected skip link moving focus to `MAIN`. Those checks were not all repeated in the resumed run. The frontend's separate production-output desktop/mobile tests use mocked authentication; their results and screenshots belong to the validation report, not to the real Laravel flow above.

No new implementation defect was found in this resumed live run. A few browser locator waits reached the tool's short deadline while the app was loading; fresh observations confirmed the expected completed state before any result was recorded. Initial connection refusal occurred during Docker recovery and cleared after services became ready.

Not executed live here: mobile viewport flows (the earlier browser viewport override was unavailable), 200% zoom, assistive-technology/screen-reader review, exhaustive cross-browser checks, ten-minute expiry/resend, throttling, offline recovery, manual CSRF-cookie mutation, verification replay, Secure trusted-browser cookie persistence, or a real multiworker HTTP session race. Backend tests and mocked UI tests cover several corresponding boundaries, but are not substitutes for claiming those live scenarios ran. HTTP statuses and cookie flags are established only by separately recorded API/PHP checks where applicable.

Both acceptance accounts and their exact-recipient Mailpit messages remain as local review fixtures. The final fixture was signed out, agent-created browser tabs were closed, and the normal Docker stack was left running. No passwords, codes, cookies or verification proofs are saved in this report; no development data reset, Redis flush or unrelated inbox deletion was performed. Follow steps 23–25 for diagnostics and safe cleanup.
