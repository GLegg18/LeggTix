# Issue #20 — Nuxt foundation verification

Work date: 16 October 2026. This report describes local work on the existing `LT-20-Basic-Nuxt-Frontend` branch; it does not establish a hosted deployment or a GitHub CI result.

## Scope and implementation

The [issue](https://github.com/GLegg18/LeggTix/issues/20) requests a lightweight Nuxt shell, a Docker local-run path, an environment-configured Laravel URL and Sanctum SPA cookie authentication. The accompanying request extends presentation scope to a homepage, login/verified registration, account shell, responsive Tailwind styling, dark mode, neon pink and SVG logo choices. The [frontend guide](frontend.md) records setup, contracts and UX decisions; the [manual plan](frontend-manual-test-plan.md) supplies steps and expected outcomes.

Implemented surfaces: `/`, `/login`, `/register`, `/account`, `/brand` and a missing-page state. The homepage's event-inspired artwork is illustrative. No event discovery or reservation controls are claimed: those remain [#21](https://github.com/GLegg18/LeggTix/issues/21). Password recovery remains separate backend work.

Important files:

- `frontend/nuxt.config.ts`, `frontend/Dockerfile`, `frontend/package-lock.json` and `docker-compose.yml`: reproducible local app and server-only API base.
- `frontend/app/`: page hierarchy, shared form/navigation components, API/CSRF client and theme preference.
- `frontend/server/`: same-origin Laravel proxy for SPA and CSRF endpoints.
- `frontend/public/brand/`: three original SVG marks; A is provisional.
- `routes/api.php`, `SpaAuthController`, shared authentication services and `EnsureSpaOrigin`: session completion without issuing tokens, shared verification rules and explicit origin boundary.
- Sanctum/CORS/session configuration and `.env.example`: closed origin defaults, secure session defaults and deliberate local HTTP settings.
- `ApiSpecification` and related tests: explicit SPA operation contracts alongside existing bearer operations.

## Developer findings

| Symptom or risk | Cause and fix | Verification |
| --- | --- | --- |
| Password-only requests could populate the authentication guard while the browser is still awaiting its email factor. | The former bearer-only implementation used `Auth::once`. Credential validation now reads the attempted user without authenticating the guard; only completed verification/trusted login establishes a session. | SPA regression checks guest state after password-only challenge and wrong code. |
| Session logout could crash when treating Sanctum's transient session token as a database access token. | Logout now distinguishes token and session authentication, invalidates the browser session/CSRF state and preserves bearer token revocation. | SPA session logout and existing token logout acceptance. |
| Invalid SPA origin could receive an authentication result before the intended origin rejection. | Origin middleware is ordered before authentication. | Explicit invalid-origin regression. |
| Successful logout lacked an explicit no-store response. | Shared logout response now has cache protection. | Header regression. |
| An event API test stopped passing after 15 October 2026. | It booked a hardcoded event while the booking action correctly used real database time. Only that serialization fixture now uses a future database calendar year, retaining the UTC/microsecond assertions. | Final full-suite rerun records the result below. |

## Independent tester and security findings

- **Tester — fixed:** idle form borders were too faint against their surfaces. Dedicated control-border colours improve identification in both themes. The tester measured primary button text at 5.19:1 and small pink text at 6.21:1 in light / 7.07:1 in dark; these spot measurements do not establish full accessibility conformance.
- **Tester — fixed:** the skip link changed the URL hash without moving keyboard focus because the main landmark was not focusable. Main landmarks now have `tabindex="-1"`; browser regression and live retest cover the correction.
- **Tester — boundary coverage:** independent PHP cases check exact origin/referer behaviour, CSRF rejection without side effects, actor isolation when foreign IDs or bearer headers are supplied, and preservation of existing API tokens. The initial three cases passed with 41 assertions; final run includes the additional security regression below.
- **Security — fixed:** automatic password rehashing before the user lock could overwrite a concurrently changed password. The login workflow keeps the originally validated user snapshot unchanged until the locked fingerprint comparison. The tester added a deterministic `Validated` event regression that replaces the password between validation and the lock and asserts that the new hash survives and no authentication is issued. The 19 October review below records restoration of safe hash upgrades inside that lock; the earlier description of the previous behaviour as having no rehash was incorrect, because Laravel's former `once()` call performed one.
- **Security — fixed:** a frontend Docker build needs its own context ignore file. `frontend/.dockerignore` now excludes dependencies, generated output, environment files and test artifacts so they cannot replace the image's clean dependency install or enter build layers.
- **Security — fixed:** an overlapping request could save stale authentication after logout invalidated a session. The reviewer reproduced this with two installed Laravel `Store` instances and an in-memory handler; no development data was touched. Global Laravel session blocking now locks before loading and holds through saving, covering the CSRF route and every stateful API request. Instrumenting the installed `StartSession` middleware with an in-memory handler and isolated array cache confirmed the configured lock was held during both read and write and released after the response. This is a framework-persistence reproduction and actual middleware verification, not a reproduced exploit against the local single-worker HTTP server. A real multiworker HTTP race was not run.
- **Developer — fixed:** a slow initial current-user response could overwrite a newly completed sign-in. Authentication-state revisions discard stale restore responses; the desktop/mobile browser regression includes this ordering.

## Dependency advisories and security review outcome

The security reviewer inspected authentication/session/CSRF boundaries, configured origin enforcement, shared throttles, proof/code transactions, actor scoping, returned fields and the Nuxt proxy. The password-change race, stale-session-save race and Docker context omission above were fixed. No reservation inventory workflow was changed. The reviewer confirmed production `.output` excludes `braces`, `micromatch`, `globby`, `fast-glob`, `node-forge` and `listhen`; emitted JavaScript/JSON also lacked the affected package/signature markers.

**Deferred dependency follow-up:** `npm audit --omit=dev` reports eleven propagated high alerts arising from two distinct transitive advisories: [braces deep-nesting stack exhaustion](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) and [node-forge RSA signature verification](https://github.com/advisories/GHSA-86w9-cpqp-85rv). Neither had a patched version available when checked. The affected installation/development paths use source/build patterns or local certificate handling; no current request path to the vulnerable sinks was found, and the built production artifact omits these dependency paths. This does not make the installation audit clean. Keep development loopback-only, recheck upstream fixes before upgrading/deploying and replace the affected transitive versions once a supported patch exists. A forced Nuxt downgrade was not applied.

The review used installed framework/package source, audits, a session persistence reproduction and actual session-middleware instrumentation. A live multiworker logout race, assistive-technology audit and exhaustive browser matrix were not run. These limits remain separate from the passing functional checks.

The author's focused isolated MySQL run passed **112 tests, 1,244 assertions** for SPA authentication, existing authentication, email-factor login and API documentation. Additional session-ID/trusted-cookie assertions were added afterwards and require the final run recorded below.

## Verification status

| Check | Actual result |
| --- | --- |
| Complete Laravel suite with warnings treated as failures | **472 passed, 4,110 assertions**, 73.16 seconds, exit 0, including final session-blocking configuration. Disposable MySQL stack cleaned successfully. |
| Independent tester PHP boundaries | Included in the final full suite, including deterministic concurrent password-change regression. |
| Compose configuration | `docker compose config --quiet` passed. |
| Live HTTP smoke | Homepage `200`, CSRF handshake `204`, unauthenticated SPA current-user `401`. |
| Composer audit | Exit 0; no advisories or abandoned packages reported. |
| Frontend production build | Passed, 45 seconds; Nitro production output generated. |
| Final frontend type check | `npm run typecheck` passed with exit 0 in isolated Node 24 Docker runtime. |
| Desktop/mobile browser regressions | **12 passed**, 9.8 seconds, Playwright Chromium at desktop 1440×1000 and Pixel 7 mobile sizes, with production preview auto-started in the official Playwright Docker image. API responses are mocked; these cases are not claimed as live Laravel integration. |
| Security review | Performed; identified authentication/session/build-context findings are fixed and verified as described above. Two unpatched transitive installation advisories remain explicitly deferred. |
| Independent live browser acceptance | Passed: real email ownership/signup/welcome delivery; password login remained guest until email factor; wrong OTP produced focused/marked error; correct OTP established the exact account; reload restored the cookie session; logout and fresh account access showed signed-out state. Earlier live checks also exercised wrong password, remembered-browser revocation and skip-link focus. Browser warnings/errors were empty for the final tested states. |

The final browser regression used `CI=true`, the existing production output and `mcr.microsoft.com/playwright:v1.64.1-noble` (Node 24.21.0). It covered email-first signup with a leading-zero code and no secret storage, login/reload/logout, field-error focus and rate countdown, a single CSRF refresh/retry, persistent themes, viewport overflow, keyboard skip focus, correct missing-page recovery and delayed anonymous restore responses. Typecheck and browser verification containers were removed after successful completion. No frontend source fixes were required during these final checks.

Four local PNG previews were generated under ignored `frontend/test-results/previews/`: `home-desktop-light.png`, `home-desktop-dark.png`, `home-mobile-light.png` and `brand-gallery.png`. Both the implementation agent and coordinator visually inspected them. They are local review artifacts, not committed repository assets.

The resumed live pass used the dedicated local fixture `frontend-live-20261016-final@example.test` and Mailpit, with remembered-browser enrollment off. The fixture is retained and the browser ended signed out; existing application data was not reset. The earlier `frontend-live-20261016-acceptance@example.test` fixture also remains. The normal Compose stack was left running for review.

The relevant manual acceptance path is: verify email → create account → see exact details; reload → retain session; logout → no profile on a fresh account visit; enter password → remain guest while awaiting a Mailpit code; wrong code → focused field feedback; correct code → authenticated account; change theme/viewport and use keyboard skip navigation → readable layout and reachable content. The [full manual plan](frontend-manual-test-plan.md) supplies expected results and distinguishes additional unrun checks. Live expiry/throttle/419 recovery, optional Secure remembered-browser enrollment, screen-reader/zoom and exhaustive cross-browser checks were not performed; mocked regressions and PHP acceptance cover their stated boundaries separately.

The first broad run had 470 passing tests and one existing date-expired fixture failure; the fixture fix described above resolved it. A later full run passed before session blocking was added; the table records the final run including that mitigation. Docker Desktop failed during a frontend build and interrupted one tester run before it executed. A normal Docker Desktop restart recovered the engine without resetting development data. The interrupted disposable test stack was explicitly cleaned up; heavyweight verification was then run serially. Interrupted commands are not counted as passes.

## Limits

- No hosted deployment, pushed commit, merged change or successful remote CI run is implied by local verification.
- This shell is browser-rendered; richer public-page server rendering/search previews are future work.
- The HTTP local exception applies to the session cookie only. The optional trusted-browser cookie remains Secure; browser-specific HTTP loopback treatment may differ.
- Email delivery retains the documented asynchronous delivery limits. No formal accessibility certification, screen-reader audit or exhaustive cross-browser coverage is claimed.

The [startup and logging follow-up](frontend-startup-validation.md) records the later Docker Desktop startup report, lifecycle improvements, dual Laravel logging and targeted runtime checks. It distinguishes the reported immediate exit from the evidence actually reproduced.

## Sun/moon slider follow-up

The requested header dropdown was replaced with a compact binary sun/moon slider in `SiteHeader.vue`, with styles in `main.css`. `useTheme.ts` now exposes the resolved reactive dark state, so the displayed thumb and accessible checked state match both a saved choice and the initial system preference. An explicit toggle persists light/dark; click, Space and Enter are supported. The existing reduced-motion rule applies to the thumb animation. The manual plan was updated to reflect the new control.

`npm run typecheck` passed in Node 24 Docker. The existing theme regression passed **2/2 desktop/mobile cases** against the live development server, covering system preference changes, explicit overrides, keyboard toggling, persistence/reload, skip focus, viewport fit and missing-page recovery, with no uncaught page errors. Four header screenshots were inspected. PHP/authentication suites, a new production build and security review were not rerun for this UI-only change.

During verification, the mounted source contained the slider while the development server still served the dropdown: native Windows bind-mount file events had been missed. Compose now enables Chokidar polling at a 500-millisecond interval, and the frontend was recreated. Docker then reported healthy, served the new control and logged an HMR update after a timestamp-only source touch. The tester's initial external test hostname was also correctly rejected by Vite; rerunning through the frontend's own network namespace used permitted `localhost` without weakening host protections.

## 19 October review follow-up

The three pre-commit comments are fixed:

- **Password upgrade regression:** changing `once()` to credential-only `validate()` prevented premature authentication but also removed Laravel's automatic password rehash. `LoginEmailVerificationService` now compares the original validated fingerprint with the locked current user before any password write, honors `hashing.rehash_on_login`, and persists a required upgrade using the existing checked-save helper. It then recomputes the fingerprint for email challenges and trusted-browser checks. A password-cost upgrade therefore requires a fresh email factor for an old trusted-browser credential, as the original rehashing flow did. A canceled or failed save rolls back without authentication side effects.
- **Obsolete local tooling:** removed the unreferenced `frontend/capture-previews.mjs`, which still selected the deleted theme dropdown and could leave its preview server running after failure. Generated screenshots remain ignored; no replacement helper was added.
- **Startup documentation:** the manual plan and existing-checkout guide now stop the worker before recreating the app, worker and frontend, respecting the shared Composer volume and application-health ordering. The worker is restarted after clearing cached configuration. The trailing blank line reported by the whitespace check was also removed from the performance report.

The developer implemented the locked upgrade. The independent tester added six cases covering SPA enabled/disabled rehash policy, bearer compatibility, save veto/exception recovery and old trusted-browser rejection after an upgrade; the existing concurrent password-change regression remains unchanged. The security reviewer inspected the final service and approved it, with no remaining actionable findings in this follow-up. The prior claim that the bearer implementation had no rehash was corrected above.

Verification actually performed:

- Against the previous committed service mounted read-only in the isolated test container, the new SPA regression produced **one expected failure and one pass, 24 assertions**. Enabled rehash failed because the persisted old hash was unchanged; the disabled-policy control passed.
- With the fix, the complete disposable-MySQL suite passed **478 tests, 4,206 assertions**, in 78.13 seconds, exit 0, with `--display-warnings --fail-on-warning`. This includes all six new cases and the original concurrent-password-change case.
- PHP syntax, `docker compose config --quiet` and whitespace checks passed. The disposable stack/image and ignored source snapshot were removed; the normal development database was not used for tests.

To repeat the focused seven-case check, use the isolated runner from the repository root:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 '--filter=password_rehash_policy|password_cost_upgrade|password_rehash_save_failure|password_change_between_validation_and_lock' --display-warnings --fail-on-warning
```

Expected: seven passing cases and successful temporary-stack cleanup. The manual browser smoke path remains password plus current Mailpit code → correct account → reload retains session → logout → fresh account visit is signed out, as detailed in the [manual plan](frontend-manual-test-plan.md). A new browser pass, frontend build and live multiworker race were not run for this backend/tooling/documentation follow-up. The previously deferred npm advisories remain documented above; neither dependencies nor their advisory disposition changed.
