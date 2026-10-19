# Email login factor and trusted browsers

Every new password login, including legacy/demo accounts and all roles, requires an emailed code unless the browser presents an unexpired trusted-browser credential. The password is always required. [Registration email ownership](registration-email-verification.md) still issues the first account token after its proof-backed signup; it does not require an immediate second email.

The [Nuxt SPA](frontend.md) uses the same factor workflow at `/api/spa/login` and its verification route. Completion establishes a regenerated server session and returns `{ "user": ... }`, without issuing an API token. The bearer paths below keep their token response. Password-only challenge creation never signs in the browser.

## API flow

Use JSON objects with the documented fields. The [Swagger explorer](http://localhost:8000/docs/api) exposes the operations and the [Mailpit inbox](http://localhost:8025) captures local codes.

| Request | Outcome |
| --- | --- |
| `POST /api/login` with `email`, `password` | Normally `202` with `challenge_id` and UTC `expires_at`; **no access token** is issued. |
| `POST /api/login/email-verifications/{challenge_id}/verify` with six-digit string `code` and optional boolean `remember_device` | `200` with the existing user/access-token response after both factors succeed. |
| Password login with a valid trusted-browser cookie | `200` with the existing token response and a rotated cookie validator. |
| `POST /api/logout` with bearer token | `204`; revokes that token and the presented trusted-browser family, and clears the cookie. |
| `DELETE /api/trusted-devices` with bearer token | `204`; forgets all the actor's trusted browsers, invalidates their pending login challenge, and clears the current cookie. |

Enter the emailed code as a string so leading zeros survive. Codes last 10 minutes and permit five failed guesses; failed attempts commit even when the response is `422`. A successful code is consumed atomically with token creation and optional browser trust. Replaying or concurrently submitting it cannot issue another token. A failed database write rolls back consumption so the unexpired code can be retried.

To resend, repeat `/api/login` with the password after the one-minute per-account issuance cooldown. The new challenge replaces the old code. There is no password-free resend operation. Expired, superseded, consumed, exhausted or credential-changed challenges all return the same `422` under `errors.code`. Unknown accounts/wrong passwords retain the generic `422` under `errors.email`; an invalid trusted cookie never skips the password or authenticates a request.

Wrong JSON/media types produce `400`/`415`; missing, malformed, unknown or query fields produce `422`. Challenge URLs require 64 lowercase hex characters; malformed IDs return `404`. Required-code enqueue failure produces sanitized `503` and no access token; retry the password step after its cooldown/rate-limit delay. Codes may still fail delivery asynchronously; inspect the worker/failed jobs and request a new code if needed. Responses from login/factor/logout/trust operations are not cached.

## Remember this browser for seven days

`remember_device` defaults to `false`. Only successful OTP verification with `true` can create a trust credential. It is returned exclusively in the host-only `__Host-leggtix_trusted_device` cookie, with **Secure, HttpOnly, SameSite=Strict and Path=/**. HTTPS, or a browser supporting Secure cookies on `http://localhost`, is required. Ordinary CLI clients can use a code at each login. Device secrets are never exposed in JSON or stored in browser local/session storage.

The cookie contains a random selector and a separate random 256-bit validator. The server stores the selector and a SHA-256 validator hash, not the raw credential. A trusted password login rotates the validator atomically and retains the original absolute expiry: use does not extend the seven-day window. Concurrent use of the old validator cannot create a second trusted login. If the rotated response is lost, the browser must verify a new code; the old validator is deliberately unusable.

The stable selector lets authenticated logout revoke the same browser family even if a concurrent login already rotated its validator. Selectors alone cannot authenticate. Logging out clears/revokes the presented browser family and the authentication used by the request (current bearer token or browser session). Forget-all affects only the authenticated account's trust and pending challenge; other users are untouched. Existing bearer tokens are not revoked by forget-all.

This cookie only influences the password-login decision. It does not authenticate `/api/user`, booking, logout or forget-all: those require a Sanctum bearer token or an established first-party session with the SPA/CSRF boundary. Swagger sends cookies only to this installation, keeps the origin/path guard and refuses redirects. Refreshing Swagger still forgets its bearer token; a remembered browser must submit its password again to obtain another token. CORS is closed by default and grants credentials only to explicitly configured frontend origins.

## Integrity, expiry and credential changes

`login_email_verifications` stores one transient challenge per user. `trusted_devices` stores browser families. Challenges use random 256-bit IDs, keyed code hashes, encrypted temporary delivery codes and five-attempt bounds. Queued jobs contain only the internal challenge row ID and public generation, and skip superseded, consumed, expired, deleted or credential-changed records. No email body, password or raw trust credential is queued.

Both challenges and trusted devices bind to an HMAC fingerprint of the user's ID, exact stored email and current password hash. Changing either credential invalidates old factor/trust state. Any future email-change workflow must also clear/reverify the ownership audit marker. Successful login OTP fills `users.email_verified_at` only if it was null; the original verification timestamp is preserved otherwise. The latest successful login code's timestamp lives in the consumed challenge until cleanup.

Authentication mutations lock the user first, then relevant challenge/device rows, then issue/revoke tokens. Database UTC is sampled after locking relevant rows, so waiting across expiry cannot admit an expired code or trusted browser. Code consumption, token creation and optional trust enrollment share one MySQL transaction. SMTP/Redis calls occur after the outermost commit; no delivery call runs while those locks are held.

| Operation | Limits |
| --- | --- |
| Password login | 30/minute per IP; 5/minute per account/IP and per account across IPs; 20/hour per account. Unknown identities use normalized email keys. |
| Login-code issuance/resend | At least 60 seconds between issuances per account, enforced under the user/challenge locks. |
| Code verification | 20/minute and 100/hour per IP; 5/minute per challenge; 20/hour per account across challenge generations/IPs. |

Equivalent email spellings resolving to the same MySQL account share the account budgets. Public account limits can be consumed by an attacker; they bound password/code guessing and mail abuse rather than providing authenticated recovery. Device names, IP addresses and user-agent strings are not authentication factors.

## Local setup, cleanup and rollout

For an existing checkout, apply the additive migration and reload the worker:

```powershell
docker compose exec app php artisan migrate
docker compose restart worker
```

The normal Compose worker processes `emails` and Mailpit captures the message. The hourly `login-email:prune` schedule removes expired challenges and expired/revoked trusted devices. Recently consumed challenges retain their issuance timestamp until the 60-second cooldown passes. Expiry/revocation is enforced immediately even before cleanup. Compose does not start Laravel's scheduler; run cleanup manually locally:

```powershell
docker compose exec app php artisan login-email:prune
```

Existing API tokens keep their original 24-hour expiry. The new requirement gates **new** login tokens; it does not forcibly log out current API clients. Logout/manual revocation still invalidates a selected token, and expired tokens fail immediately. Rotating the application encryption key invalidates active challenge/trust fingerprints and requires new factor verification.

Email codes are vulnerable to mailbox compromise, forwarding and phishing. This flow is not phishing resistant and makes no NIST assurance claim. Delivery remains asynchronous and may be delayed, lost or duplicated; stale jobs recheck current state before sending, but a resend/credential change can still race an SMTP send already in progress. The [MFA scope decision](mfa-assessment.md) and [login validation report](issue-4-login-validation.md) record decisions, actual acceptance/browser checks and remaining limits.
