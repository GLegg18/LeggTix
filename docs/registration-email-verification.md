# Required email ownership before registration

New customers must prove access to their email address before creating an account. After signup, all accounts including legacy/demo users also use the separate [email login factor](login-email-verification.md), with optional seven-day trusted-browser cookies. No authenticator-app flow or booking-specific gate is added. The [MFA assessment](mfa-assessment.md) records that scope decision.

The [Nuxt SPA](frontend.md) exposes this same three-step workflow under `/api/spa/*` with session cookies and CSRF protection. Its final registration response contains the user and establishes a regenerated browser session; no API token is created or returned. The bearer paths below retain their existing token contract.

## API flow

Use JSON request bodies and the [local Mailpit inbox](http://localhost:8025). The [Swagger explorer](http://localhost:8000/docs/api) exposes all three public operations. Successful verification responses and all verification-endpoint errors carry `Cache-Control: no-store`; do not put codes or registration proofs in URLs, logs or shared command history.

| Step | Request | Success |
| --- | --- | --- |
| Request or resend | `POST /api/registration/email-verifications` with `{"email":"customer@example.test"}` | `202`, `challenge_id` and UTC `expires_at`; no account/token is created. |
| Verify code | `POST /api/registration/email-verifications/{challenge_id}/verify` with `{"code":"012345"}` | `200`, one-use `registration_token` and UTC `expires_at`. |
| Complete account | `POST /api/register` with `name`, that same `email`, `password`, `password_confirmation`, and `registration_token` | Existing `201` user/Sanctum token shape; creates only `regular_user`. |

Email is trimmed and lowercased. New verification operations accept only their documented fields in a JSON object and reject query parameters. Challenge IDs and registration proofs are independent random 256-bit values represented by 64 lowercase hex characters. The code is a random six-digit **string**, preserving leading zeros.

Codes expire 10 minutes after issuance and allow at most five failed code guesses. Verification consumes the code and returns the registration proof once. That proof expires 10 minutes after verification. Repeating successful verification cannot mint another proof. If the response is lost, request a new code and repeat the flow.

Requesting again is the resend operation: it replaces the previous challenge, code and any unused registration proof for that exact normalized address. Wait for the per-address throttle before resending. The email includes its request reference, so a delayed older message can be identified. Superseded queued jobs skip delivery when they observe a newer generation; an email already being sent may still arrive after a resend, and its old challenge is unusable.

## Errors and limits

| Outcome | HTTP response |
| --- | --- |
| Malformed JSON / wrong media type | `400` / `415` on the two verification operations. |
| Missing/invalid/unknown fields or query parameters | Standard Laravel `422` validation errors. |
| Already registered email | `422` under `errors.email`, preserving the existing registration uniqueness behavior. |
| Wrong, expired, exhausted, unknown, superseded or already verified code | Same generic `422` under `errors.code`; wrong attempts remain committed. |
| Missing, invalid, expired, consumed or wrong-email registration proof | `422` under `errors.registration_token`; no user/token is created. |
| Malformed challenge ID in the URL | `404`; challenge routes accept exactly 64 lowercase hex characters. |
| Rate limit | `429` with `Retry-After`. |
| Verification email cannot be queued | Sanitized `503` with `Retry-After: 1`; the pending challenge remains, and recovery requires a new request after the applicable throttle delay. |

Limits apply to successful and unsuccessful requests. Redis is the local shared rate-limit store. Target limits apply across all IPs, so changing IP or resending cannot reset the global email budget.

| Operation | IP limits | Target limits |
| --- | --- | --- |
| Request/resend | 10/minute, 30/hour | 1/minute, 5/hour per exact normalized email. |
| Verify code | 20/minute, 100/hour | 5/minute per challenge, 20/hour per email across challenge generations. |
| Complete registration | 10/minute | 5/minute, 20/hour per exact normalized email. |

An attacker who knows a target address can consume its shared budget or replace a pending challenge. These bounded public signup controls prioritize brute-force/mail-abuse limits; an authenticated recovery/resend channel is outside this registration-only flow.

## Persistence and concurrency

`registration_email_verifications` holds one transient row per exact normalized email. Its binary-collated email key avoids MySQL's ordinary accent-insensitive mailbox equivalence. Proof consumption also compares normalized emails byte-for-byte. A proof sent to one spelling cannot register a different delivery address.

Code verification uses an HMAC bound to the challenge ID and email with the application encryption key. Only an encrypted copy of the short-lived code is retained for queued rendering. The high-entropy registration proof is stored only as SHA-256. Codes/proofs and recipient addresses are absent from the verification job payload; it carries only the internal row ID and public challenge generation. No rendered email body is stored.

Issuance/resend, code attempts and proof consumption lock the verification row. Database UTC time is sampled after acquiring the lock, so waiting cannot extend validity. Registration consumes the proof in the same MySQL transaction that creates the user and Sanctum token. A write failure rolls everything back, leaving the proof reusable until expiry. Concurrent consumption creates at most one account; a concurrent resend and registration follow the row-lock winner. Consuming the proof clears it and the code fields. Successful code verification, expiry checks and five failed guesses clear the delivery code as appropriate.

New accounts receive a server-assigned `users.email_verified_at` audit timestamp. It is hidden, excluded from mass assignment and absent from the public user resource. Existing/demo accounts remain usable and fill a null marker after their first successful login code; an existing verification timestamp is preserved. Factories and demo seeders do not send verification messages.

## Worker, cleanup and delivery limits

The existing [transactional email worker](transactional-email.md) consumes the `emails` queue and delivers registration codes to Mailpit locally. Verification enqueue waits for the outermost database commit. A normal request returns `503` if enqueue fails; an enclosing application transaction would observe that sanitized failure when it commits, after the challenge row has committed. Delivery itself remains asynchronous and can fail or arrive late; check worker logs/failed jobs and request a fresh code if needed. Welcome/booking emails keep their best-effort enqueue behavior.

Apply the additive migration on an existing checkout before restarting the worker:

```powershell
docker compose exec app php artisan migrate
docker compose restart worker
```

The hourly `registration-email:prune` schedule deletes consumed records and fully expired code/proof state. A still-valid proof survives even if its original code has expired. Expiry is enforced immediately during verification/registration; deletion happens on the next cleanup run. Changing the application key invalidates active code HMACs; request new codes afterwards.

Compose does not start Laravel's scheduler. Run cleanup manually in local development or start the scheduler when operating the service:

```powershell
docker compose exec app php artisan registration-email:prune
```

No exactly-once or lossless email delivery is claimed. The existing commit/enqueue and SMTP acknowledgement windows remain described in the [transactional email guide](transactional-email.md). The [email verification acceptance report](issue-4-email-validation.md) records tests and live checks actually performed.
