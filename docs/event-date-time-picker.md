# Event date/time entry follow-up

The [6 November dependency review](dependency-security-validation.md) supersedes this report's dependency-alert status. The audit below remains the original 29 October snapshot.

Date: 29 October 2026. The create/edit form previously asked organisers to type ISO timestamps such as `2027-12-01T18:00:00Z`. The user requested calendar/dropdown entry with an option to type. This follow-up changes the scheduling UI while retaining Laravel's existing explicit-instant API, permissions and lifecycle rules.

## Research and design decision

- [GOV.UK's date pattern](https://design-system.service.gov.uk/patterns/dates/) recommends calendars when users need to choose dates or understand their relation to weekdays/other dates, while retaining typed entry. This fits scheduling an event. Its separate memorable-date guidance is intended for dates such as a birthday, rather than a reason to require three text boxes for every scheduling task.
- [MDN's native `datetime-local` guidance](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/input/datetime-local) describes a browser date/time chooser with locale-dependent presentation. It has no time zone and can accept a local time that falls in a daylight-saving gap, so changing the HTML type alone is insufficient.
- [W3C's date-picker example](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/examples/datepicker-dialog/) demonstrates the keyboard, focus and screen-reader work needed for a custom calendar, and calls for assistive-technology testing. Native controls avoid introducing a custom calendar/grid interaction here. Native presentation still varies by browser/device; this is not a claim of universal identical UI or formal accessibility certification.
- [Temporal's time-zone documentation](https://tc39.es/proposal-temporal/docs/timezone.html) distinguishes wall-clock time from exact time and documents skipped/repeated local times. Use Temporal with an explicit zone instead of parsing local input with `new Date(...)`, which would apply the browser's zone and can silently normalize clock changes.

The resulting form uses plain labels, a native date/time picker that also accepts typing, an optional end, and a separate named time-zone selector. Organisers enter local clock values in the event's zone; the frontend converts resolved values to UTC for Laravel. Changing the display zone preserves the instant and updates the displayed clock values. Spring gaps are explained and rejected; autumn repeated times require an explicit first/second occurrence. Unchanged stored instants retain their original precision during metadata edits.

The default remains Europe/London. A frozen historical/terminal schedule remains frozen. Laravel continues to validate input, end-after-start ordering, permitted transitions and history restrictions. No API route, schema or business permission is changed.

## Verification

| Check | Executed result |
| --- | --- |
| Type check and production build | `npm run typecheck` and `npm run build` passed in the configured Node 24 Docker environment, including the final boundary-year fix. Development Nuxt was stopped during generated-output work and restored afterwards. |
| Initial browser suite | 50 passed, 28.0 seconds. Two additional stored-date boundary cases were then added with a source fix; this initial pass is not the final evidence for that change. |
| Final desktop/mobile Chromium suite | **54 passed**, 27.8 seconds, exit 0, with production preview and mocked API responses. Includes 28 baseline cases and 26 new schedule executions. Schedule tests deliberately use a Los Angeles browser zone while selecting event zones elsewhere. |
| Picker/visual checks | The coordinator inspected the native calendar-and-time popup opened by clicking its indicator, plus desktop/mobile light/dark screenshots. Responsive checks found no page overflow. Keyboard focus and typed values were exercised. Native browser presentation varies; no custom ARIA calendar or formal accessibility claim was introduced. |
| Real Laravel/Mailpit walkthrough | Passed organiser password/email-code login, local London start/end entry, correct persisted UTC despite a Los Angeles browser, reload, Kolkata-only display-zone change with both instants preserved, another reload, dedicated event cancellation and logout. Zero page JavaScript errors. |
| Security/correctness probes | Reviewer independently checked London clock changes, Lord Howe's 30-minute changes, Apia's skipped day, India's fractional offset, explicit ambiguity resolution, seconds/microseconds, UTC range boundaries and all 419 selector IDs in Node. |
| Dependency comparison | Exact baseline/current production lockfile audits both exited **1**, with identical nine findings: four low, four moderate, one high. Neither newly added package has a reported audit entry. This is not a clean audit. |
| Patch formatting | `git diff --check` passed. |

Important files are `frontend/app/components/ManagedEventForm.vue`, `EventDateTimeInput.vue`, `frontend/app/utils/eventDateTime.ts`, `frontend/app/data/eventTimeZones.json`, `frontend/tests/management.spec.ts`, and the scoped package manifest/lockfile changes. `@js-temporal/polyfill` is pinned to `0.5.1` with `jsbi` as its dependency so supported browser runtimes share the same conversion API. No unrelated package version was upgraded.

The zone list is a 419-ID snapshot generated from `timezone_identifiers_list()` in the configured PHP 8.4 application container. When changing the PHP/time-zone data runtime, regenerate this JSON from that runtime and recheck compatibility in supported browsers. Using the browser's enumeration directly can offer legacy aliases that Laravel's default `timezone` validator rejects.

## Role outcomes and findings

- **Developer:** implemented the component/helper/form integration, pinned dependency and focused conversion probes. Changed controls use minute precision; unchanged stored instants retain their original seconds/microseconds. No backend changes.
- **Tester — passed, finding fixed:** added independent browser coverage, including a boundary case where a valid UTC year 1000/9999 displays locally in 0999/10000. Native local-year bounds and fixed-width helper parsing could block unrelated metadata edits. Bounds were removed, expanded years are converted correctly, and both new desktop/mobile regressions passed in the final suite. The tester did not run a separate duplicate suite; execution was coordinated by root.
- **Coordinator — integration finding fixed:** some browser zone enumerations use aliases such as `Asia/Calcutta` rejected by Laravel's canonical list. The selector now uses the PHP snapshot; a Kolkata half-hour-offset regression and real API save passed.
- **Security reviewer:** no unresolved defect in the scheduling changes. Static review and independent probes confirmed explicit ambiguity handling, no silent gap normalization, precise existing-state preservation, existing authentication/CSRF controls and explicit-instant submission. Browser/build/live results belong to the coordinator, not an implied separate reviewer run.

**Unresolved pre-existing dependency finding:** the audit still reports one high [`node-forge` RSA signature-verification advisory](https://github.com/advisories/GHSA-86w9-cpqp-85rv), through Nuxt → Nitro → listhen, plus four moderate and four low findings. Baseline/current affected-package entries are identical. No application signature-verification request path was demonstrated; default local development is HTTP, and the inspected production server output omitted `node-forge`. That is not proof of unreachability. Targeted Nuxt/transitive remediation, verification of the available patched/removal path, rebuild and re-audit remain a separate dependency follow-up. A forced dependency upgrade was not mixed into the date-picker change.

## Manual acceptance and limits

Use the [current manual plan](event-management-manual-test-plan.md). Pick or type a start/end, select the event zone, submit/reload and confirm its displayed schedule. A skipped clock time must explain the gap without writing; a repeated time must request its first/second occurrence. Changing only metadata or the display zone must leave the stored instants exact. Clear an optional end to remove it. Existing history keeps schedule controls disabled.

The live walkthrough created dedicated event **54**, retained cancelled with zero occupancy; no existing event was edited or cancelled. The browser ended signed out and the development frontend was restored. The normal database/key/volumes were preserved. Changes remain local and uncommitted; the pre-existing `.gitignore` edit is preserved.

The original [issue #8 report](issue-8-validation.md) remains historical baseline evidence. No PHP suite rerun was needed for this frontend-only change; its earlier 509-test result is not claimed as newly executed here. Hosted CI, native Safari/Firefox/iOS picker behavior and assistive-technology testing were not run. Browser/operating-system locale controls the native date display. The full API still accepts explicit UTC/offset instants and Laravel remains authoritative for validation and lifecycle rules.

## Field-click interaction follow-up — 29 October

The user found the icon-only opening behavior disconnected: clicking the body of a date/time field selected a text segment instead of opening the picker. Pointer clicks anywhere in either enabled field now synchronously request the native picker through [`showPicker()`](https://developer.mozilla.org/en-US/docs/Web/API/HTMLInputElement/showPicker). Successful opening suppresses duplicate default icon activation. Merely focusing or tabbing into the field leaves normal keyboard entry available; fields are not readonly.

The handler checks effective disabled state, including a disabled ancestor fieldset, and readonly state. Missing picker support or a native browser exception leaves ordinary input editing available. It changes no values, timezone conversions, API calls or dependencies. The developer implemented the handler and pointer cursor; the security reviewer inspected it and found no actionable defect. Existing audit findings above remain unchanged; no new audit was needed for this event-handler-only addition.

Type checking and production build passed. The first full 66-case browser run passed 65 cases and exposed one test assumption: emulated mobile Chromium does not apply the desktop date-segment ArrowUp behavior. The test was corrected to retain focus/no-popup assertions on both platforms, verify ArrowUp editing on desktop, and confirm editable native values on both. No application change was needed for that harness correction.

The final focused run passed **12 pointer/keyboard cases**, 8.7 seconds, exit 0. It covers left/middle clicks on both start/end fields, native picker calls during the pointer gesture, Tab/focus without opening, keyboard/native value entry, history-disabled fields, an inherited disabled fieldset during publication, and unsupported/throwing picker fallback without page errors. Its observer delegates to the real native method; the coordinator visually inspected a popup opened by clicking the field body. The other 54 existing cases passed in the full run. A further full-suite rerun was not needed after the test-only correction.

The tester reported no outstanding implementation finding. Manual expectations are updated in the linked plan. The development frontend was restored after checking; no live database records were created for this click-handler follow-up. The earlier API walkthrough remains earlier evidence, not a newly repeated check. Safari/Firefox, real-device keyboards, assistive technology and PHP acceptance were not rerun for this small frontend interaction change.
