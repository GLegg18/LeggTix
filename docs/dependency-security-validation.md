# Dependency and runtime security review — 6 November 2026

This follow-up addresses the user's request to resolve dependency alerts and update PHP after the October security releases. Earlier dependency counts in the [date-picker report](event-date-time-picker.md), [Nuxt baseline](issue-20-validation.md) and [5 November pre-PR report](issue-8-pre-pr-validation.md) are historical snapshots. Runtime/package security maintenance is described in the [maintenance guide](dependency-security.md).

## Findings and chosen updates

The normal app and worker were actually running **PHP 8.4.26**, built 6 October, in images created 12 October. The floating `php:8.4-cli-bookworm` Dockerfile tag had not updated those existing containers. Native upload progress was enabled. The frontend was actually running **Node 24.22.0**. Updating a host PHP installer would not have changed either Docker runtime.

| Component | Before | Reviewed update |
| --- | --- | --- |
| PHP app, worker and tests | 8.4.26 in normal containers | 8.4.27, explicit official Bookworm CLI tag; Composer runtime constraint `~8.4.27`. |
| Native PHP upload progress | Enabled | Disabled at PHP startup in `docker/php/security.ini`, inherited by all backend image stages. |
| Node frontend development/production and CI | Normal frontend 24.22.0; floating Node 24 tags | 24.22.2 explicit Docker/CI tags and `>=24.22.2 <25` frontend engine. |
| Symfony HttpFoundation | 8.1.8 | 8.1.11. |
| Symfony HttpKernel | 8.1.8 | 8.1.11. |
| Symfony Mime | 8.1.7 | 8.1.10. |
| Symfony Routing | 8.1.8 | 8.1.11. |
| Nuxt / Nitro | 4.6.1 / 2.13.4 | 4.6.4 / 2.13.6. |
| Undici | 8.12.2 | 8.12.4, including the new upstream fixes not yet reflected in the baseline npm audit. |
| node-forge | 1.4.0 | 1.4.3. |
| serialize-javascript / seroval | 7.1.2 / 1.6.9 | 7.1.4 / 1.7.7. |
| NFT's glob / readdir-glob's minimatch | Vulnerable 13.x / 10.x parent ranges | Narrow overrides to 14.0.4 / 11.0.5, with parent-call compatibility review and build/interaction checks. |

The Composer update used `--with-dependencies --minimal-changes --no-install --no-scripts`; exactly four package versions changed. Unrelated lock metadata was refreshed by the newer Composer runtime. Laravel, Sanctum and PHPUnit were not upgraded. The app/worker were then stopped before replacing their shared vendor files, the compiled route cache cleared as instructed by Symfony, and both containers recreated. No schema, key, `.env`, domain policy or inventory workflow changed.

The frontend retains its Vue, Tailwind, Temporal and Playwright pins. The Nuxt security update and compatible transitive refresh address framework/dev-server/serialization/request handling issues. The two scoped overrides deliberately cross the parent packages' declared major ranges; their actual APIs were reviewed rather than treating a major upgrade as automatically compatible. Revisit them when the parents adopt the patched majors. No global override, audit suppression or forced Nuxt downgrade was used.

## Primary security sources

- PHP's [21 October emergency notice](https://www.php.net/archive/2026.php#2026-10-21-5), [OOM advisory](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) and [stale-state advisory](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp) identify **CVE-2026-109618 / CVE-2026-109619** and the fixed PHP 8.4.27 release. Application validation, memory-limit changes or an image being named `cli` do not replace the runtime fix for a process serving HTTP requests.
- The later [23 October PHP notice](https://www.php.net/archive/2026.php#2026-10-23-1) recommends disabling upload progress for **CVE-2026-115560** pending the next stable fix. PHP 8.4.27 does not fix that separate issue. The unused feature is disabled before request parsing; ordinary Laravel sessions remain functional. No affected native serializer-switching pattern was found in project source, but this is not treated as proof that the runtime feature is safe.
- The [October Node security release](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases) and [24.22.2 release](https://nodejs.org/en/blog/release/v24.22.2) support the Node update. A new [Node security release announced for 11 November](https://nodejs.org/en/blog/vulnerability/november-2026-security-releases) was not yet available on this review date.
- The seven locked Composer findings covered HttpFoundation [HEAD handling](https://symfony.com/cve-2026-118514), [path-rule interpretation](https://github.com/symfony/symfony/security/advisories/GHSA-3h27-hjv7-fqjx), [trusted proxies](https://github.com/symfony/symfony/security/advisories/GHSA-xg2q-4rrq-657q) and [base URL inference](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693); HttpKernel [fragment URI host/scheme/port validation](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9); Mime [header injection](https://symfony.com/cve-2026-45070); and Routing [compiled host-placeholder handling](https://symfony.com/cve-2026-118516). Audited dependencies were affected; no claim is made that every upstream exploitation precondition exists in this Laravel app.
- The [Nuxt 4.6.4 security release](https://github.com/nuxt/nuxt/releases/tag/v4.6.4) identifies the framework/Nitro patch boundary. The [Undici advisory](https://github.com/nodejs/undici/security/advisories/GHSA-mqg3-3v4r-cm32) supports the deliberate 8.12.4 refresh beyond the baseline scanner's coverage.

## Audit interpretation and outstanding upstream work

The fresh baseline differed from the older nine-alert snapshot: npm reported **15 affected package entries** in both full and production audits (**3 low, 11 moderate, 1 high**); Composer reported **seven advisories across four production packages**, with no abandoned packages.

Updated Composer full and production audits exited **zero**, with no advisories or abandoned packages. The updated frontend lockfile reduces the npm result to **seven low package entries**, **zero moderate/high/critical**. These seven entries propagate from one `deepmerge` advisory; they are not seven independent vulnerabilities.

The underlying [deepmerge CVE-2026-93753 record](https://github.com/CVEProject/cvelistV5/blob/main/cves/2026/93xxx/CVE-2026-93753.json) was **REJECTED on 2 November 2026** by its CNA because it did not describe a security vulnerability. The [upstream discussion](https://github.com/TehShrike/deepmerge/issues/273#issuecomment-6584747954) explains the returned-object/global-prototype distinction. The [GitHub/npm advisory](https://github.com/advisories/GHSA-gcqx-w48m-w2h4) still reports a low finding with no patched release. It remains visible, unignored and unmodified. The npm audit consequently still exits nonzero; this is not a clean audit or a claim that the library was patched. A forced downgrade/fork solely to hide this rejected record was not applied.

**Pending real upstream runtime work:** apply the next stable PHP 8.4 fix for CVE-2026-115560 when released, retaining the upload-progress mitigation meanwhile; recheck/apply the announced 11 November Node 24 security patch when available. No unpublished version or release candidate was installed, and no reminder automation was requested or created.

**Node bundled dependency limit:** the actual Node 24.22.2 runtime reports bundled Undici **7.30.1**, while the npm lock now resolves **8.12.4**. The npm patch does not replace built-in `fetch`. Some new Undici fixes start at 7.30.2; assess each advisory's prerequisites rather than inferring exposure from the version alone. The High [cache/redirect advisory](https://github.com/nodejs/undici/security/advisories/GHSA-mqg3-3v4r-cm32) requires an originless Agent with shared caching and composed redirect/cache interceptors. It is not automatically established by using ordinary Node fetch. No unsupported global dispatcher shim or custom Node build was introduced. Recheck the official Node patch and its bundled dependency versions; the announced November release does not promise a particular Undici fix.

The reviewer assessed all six 5 November Undici advisories against the actual proxy and freshly emitted server output:

| Upstream advisory | Severity / required feature |
| --- | --- |
| [Intermediate redirect cache poisoning](https://github.com/nodejs/undici/security/advisories/GHSA-mqg3-3v4r-cm32) | High; originless Agent, shared cache and redirect/cache interceptor composition. |
| [Same-origin redirect disclosure](https://github.com/nodejs/undici/security/advisories/GHSA-7xg3-fphp-28hg) | Moderate; shared caching combined with redirect handling. |
| [Cache response replay exception](https://github.com/nodejs/undici/security/advisories/GHSA-3g9m-rv3r-w5w2) | Moderate; cache interceptor response replay. |
| [Cross-origin redirect revalidation disclosure](https://github.com/nodejs/undici/security/advisories/GHSA-5rm8-gv32-r954) | Moderate; shared caching combined with redirect revalidation. |
| [Tuple-header redirect stripping bypass](https://github.com/nodejs/undici/security/advisories/GHSA-9v6h-8rgp-m69f) | Moderate; tuple headers, redirects and an interceptor that normalizes headers. |
| [Whitespace in Subresource Integrity metadata](https://github.com/nodejs/undici/security/advisories/GHSA-r4pc-4jcp-7pqc) | Moderate; fetch with affected whitespace-containing `integrity` metadata. |

Bundled 7.30.1 falls within their component version ranges. The reviewed app/server output uses configured-host proxying through `globalThis.fetch` and `Headers`; no affected shared-cache/interceptor composition or `integrity` option was found. This is a confirmed runtime component gap with **no demonstrated current application exploit**, not a declaration that all Node vulnerabilities are fixed. Avoid introducing those affected features before updating. Replacing the dispatcher would not replace fetch's SRI parser and is not a complete fix. Verify bundled Undici is at least **7.30.2** in a supported Node 24 release before closing this follow-up.

## Verification

The independent tester ran the complete disposable MySQL suite after the PHP/Composer updates: **545 tests, 4,905 assertions passed**, **90.14 seconds**, warnings treated as failures, exit zero. Project `leggtix-test-8684cf33b89a4d4eab0e4c5d7ebe3e06` removed its disposable database, network and generated test image. PHPUnit did not use the normal development database.

Running app and worker both reported PHP **8.4.27** with upload progress disabled. Redis extension **6.3.0** and PDO MySQL were present. The frontend's committed **419 time-zone IDs** still matched the patched PHP runtime exactly, so no snapshot rewrite was required. The normal queue had no failed jobs.

Local audit/build/test evidence is retained under ignored `storage/app/private/dependency-review-20261106/`; these are local review artifacts, not files bundled in the PR. Baseline and updated Composer JSON, metadata and the full suite log are recorded separately. The earlier API error changes and pre-existing `.gitignore` edit were preserved.

| Check | Actual result |
| --- | --- |
| Composer audit, all and production | Both exit zero; no advisories or abandoned packages. |
| Installed Composer platform requirements, all and production | Passed; installed Symfony versions match the four reviewed patches. |
| PHP isolated suite | 545 tests / 4,905 assertions passed, 90.14 seconds; warnings treated as failures; successful cleanup. |
| Frontend locked installation and types | `npm ci --no-audit --no-fund` and `npm run typecheck` passed on Node 24.22.2. |
| Parent-package override compatibility | Actual NFT `nodeFileTrace` included its dependency and both dynamically globbed assets. Actual readdir-glob matched expected files with/without dotfiles while honouring ignore/skip patterns. Resolved child versions were glob 14.0.4 and minimatch 11.0.5. |
| Durable compatibility and install-script checks | Added `frontend/scripts/verify-dependency-overrides.cjs`, `npm run test:dependency-compat` and the matching CI step. Exact `esbuild@0.28.2` postinstall reviewed and approved in `allowScripts`; fresh installation/probe passed and `npm install-scripts ls` reported no unreviewed scripts. Coordinator also ran both checks successfully against the normal frontend. |
| Frontend production build | Passed on Node 24.22.2 with Nuxt 4.6.4, Nitro 2.13.6, Vite 8.3.6 and unchanged Vue 3.5.44. |
| Desktop/mobile browser regressions | **66 tests passed**, 38.8 seconds, no failures or retries. Preview and test runner used Node 24.22.2 in the official Playwright 1.64.1 browser image; API responses were mocked. |
| Real API / worker / Mailpit acceptance | **56 checks passed**, including 54 real HTTP requests and booking confirmations delivered before cancellation. New review events **60/61** retained cancelled; attempts **23/24** retained cancelled; tokens **53/54/55** revoked; cleanup recorded no errors. |
| Real development frontend / Laravel browser walkthrough | Passed organiser/customer/admin email-factor login, password-only denial, owned/responsive list, date picking/keyboard/DST/zone persistence, draft/public privacy, edit/reload, publication, named cancellation/dismissal, terminal metadata and access denial. New UI event **62** retained cancelled; all three sessions logged out; no page JavaScript errors. |
| Final local stack | App/frontend/MySQL/Redis/Mailpit healthy, worker running, no failed jobs, no remaining disposable PHP test container. Compose configuration validates. |
| Final source/documentation checks | `git diff --check` passed; all 160 local file links across 12 affected Markdown guides resolved. |

The live API evidence is `storage/app/private/issue8-api-202752cdae4dc4f83be1.json`; its two confirmation message IDs were `35weMknfVryvLQGiRUYVtm` and `1e9MMfQDVAoMAK3HvXnFUT`. The current browser evidence/screenshots are in `storage/app/private/dependency-review-20261106/live-browser/`. Existing events, reservations, messages, key and named volumes were preserved. All fixture writes used authorised API/UI operations.

The frontend production package-manifest inventory found no separate `deepmerge`, glob, minimatch, node-forge, Undici, seroval or serialize-javascript entries in `.output`. This is limited evidence about the traced package inventory, not proof that no related code or runtime dependency can be reached. Node's own bundled libraries must be assessed separately from this npm lockfile.

The bounded local log sample after 11:17 UTC contained npm's esbuild install-script review notice and Nuxt's warning that its development server listens on a network interface. The host port remains bound to `127.0.0.1`; no application exception/fatal error was identified. These startup messages are not treated as a failing booking/authentication flow.

The esbuild notice was subsequently resolved by reviewing and approving only the exact pinned package script. Its binary selection/version checks and fallback download/checksum behavior were inspected; no allow-all or ignore-scripts policy was added. A fresh private `npm ci` and the running frontend's install-script inventory both confirmed no unreviewed scripts. Four unattached, task-specific Docker review volumes were removed by exact name after verification; normal app/dependency/database volumes and ignored host evidence were preserved.

## Role outcomes and execution limits

**Developer — completed:** researched primary Nuxt/Undici releases, made the scoped manifest/lock update, and ran private installation, parent compatibility checks, typecheck, build and all 66 browser regressions. Initial verification-only failures were an incorrectly relative probe fixture entry and a Docker volume mounted directly on `.output`, which Nitro could not remove (`EBUSY`). Corrected the fixture path and removed that private output mount; final checks passed. No application source or existing browser test was weakened to obtain a pass.

**Independent tester — passed:** identified all seven production Composer advisories, verified primary fixed versions, re-audited both dependency scopes, checked the running PHP/installed packages and platform requirements, ran the complete isolated suite and the real API/Mailpit sequence. No additional application defect was found. Its supplied repeat plan expects healthy services, patched app/worker, clean Composer audits, passing platform requirements, passing isolated tests and the live runner's `passed:true` / `cleanup.errors:[]`; use the commands in the [maintenance guide](dependency-security.md).

**Security reviewer — no blocking diff finding:** independently inspected PHP/Node primary advisories, the rejected CVE authority record, manifest/lock and Docker/INI/CI consistency, actual parent call sites and compatibility results. No reservation/ownership/data-integrity workflow changed. The review was static/read-only; it did not independently run builds, tests or exploit probes. The upstream runtime follow-up and visible rejected-advisory entries above remain recorded rather than being called patched or suppressed.

The coordinator performed final source review of the later compatibility script, exact esbuild approval and CI wiring. Those last additions were not independently re-reviewed by the security reviewer; the developer's fresh installation/probe and coordinator's normal-runtime checks passed. No claim is made that the reviewer executed those checks.

No whole-image/OS vulnerability scan, load benchmark, physical-device/Firefox/Safari/accessibility audit, hosted CI run or deployment was performed. The production shell was built and exercised locally; a hosted deployment is not implied. No GitHub write, commit, staging, push or merge was performed. The previous API-error work remains in the working tree alongside this requested follow-up.
