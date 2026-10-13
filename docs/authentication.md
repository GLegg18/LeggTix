# Authentication API

Implemented for [issue #5](https://github.com/GLegg18/LeggTix/issues/5). This milestone uses [Laravel Sanctum API tokens](https://laravel.com/framework/docs/13.x/sanctum#api-token-authentication) and Laravel's credential checking/password hashing. It supports API clients with bearer tokens; a future Nuxt browser integration should use Sanctum's first-party session/cookie flow with CSRF protection.

Use the local [Swagger API explorer](http://localhost:8000/docs/api) for operation schemas and interactive requests. Complete password login and its emailed code, copy the issued `access_token` into **Authorize** without adding `Bearer`, then call protected operations. Tokens are not persisted across page reloads. Optional browser trust is a separate Secure HttpOnly cookie; it still requires the password and never authenticates protected API calls by itself.

## Routes and responses

All paths below are relative to `http://localhost:8000`. Send JSON request bodies with `Content-Type: application/json`. Sending `Accept: application/json` is recommended; API errors still return JSON if it is omitted.

| Method and path | Input / authorization | Success |
| --- | --- | --- |
| `POST /api/registration/email-verifications` | JSON `email` | `202`, challenge ID and code expiry |
| `POST /api/registration/email-verifications/{challenge}/verify` | JSON six-digit string `code` | `200`, one-use registration proof and expiry |
| `POST /api/register` | `name`, `email`, `password`, `password_confirmation`, `registration_token` | `201`, user and new token |
| `POST /api/login` | JSON `email`, `password`; optional trusted-browser cookie | Normally `202`, login challenge/expiry; valid trust permits `200` after password check |
| `POST /api/login/email-verifications/{challenge}/verify` | JSON six-digit string `code`, optional boolean `remember_device` | `200`, user/new token; optional seven-day browser cookie |
| `GET /api/user` | `Authorization: Bearer <token>` | `200`, current user in `data` |
| `POST /api/logout` | `Authorization: Bearer <token>` | `204`, empty body; current token and presented browser trust revoked |
| `DELETE /api/trusted-devices` | `Authorization: Bearer <token>` | `204`, all actor browser trust/pending login challenge revoked |

Registration and completed login return:

```json
{
  "user": {
    "id": 1,
    "name": "Alex Example",
    "email": "alex@example.com",
    "role": "regular_user"
  },
  "access_token": "<new plaintext token>",
  "token_type": "Bearer",
  "expires_at": "<ISO-8601 UTC expiry>"
}
```

`GET /api/user` returns the same four user fields inside `{"data": {...}}`. Password hashes, token records and internal timestamps are never returned. Successful credential/current-user responses carry `Cache-Control: no-store`.

Validation errors and invalid login credentials return Laravel's `422` JSON shape, with `message` and field-specific `errors`. Unknown emails and wrong passwords both report `The provided credentials are incorrect.` under `errors.email`. A duplicate email, including a simultaneous registration rejected by the database unique index, produces a validation error. Missing, invalid, expired or revoked bearer tokens produce `401` with `{"message":"Unauthenticated."}`. Rate limits produce `429` JSON and a `Retry-After` header.

## Account rules

- Names are required strings up to 255 characters; emails are required valid addresses up to 255 characters.
- Registration and login both trim/lowercase email before validation and lookup. The model normalizes email on write too. A unique database index is the final duplicate-account guard.
- New registration requires [email ownership verification](registration-email-verification.md) before collecting the final name/password request. Codes expire in 10 minutes with five failed guesses; one-use registration proofs expire in 10 minutes and bind to the exact normalized address. Account/token creation consumes the proof in the same transaction. Every later login, including existing/demo accounts, requires the [email login factor](login-email-verification.md) or a valid trusted-browser cookie alongside the password.
- Registration requires matching password confirmation and at least eight characters. Passwords are at most 72 **bytes** and cannot contain null bytes, on registration and login. The byte limit prevents bcrypt from treating different long passwords as the same secret; multibyte characters can use several bytes each. Passwords are not trimmed.
- Registration always creates `regular_user`. Supplying a nonempty `role` is rejected; other unexpected fields cannot assign IDs, hashes, timestamps or privileges. `role` is excluded from model mass assignment and cast to `UserRole`.
- Trusted factories can create `organiser` and `admin` users for tests. The local/testing demo seeder creates three regular users, an organiser and an admin, as described in the [demo setup](../README.md#demo-data-and-login); these known credentials are local-only and no access tokens are seeded. Demo login codes are captured by Mailpit. Public role management, password reset and authenticator-app factors remain separate work.

Successful proof-backed registration schedules a [welcome email](transactional-email.md) after the outermost database commit. The welcome does not affect the returned token or restrict login/booking. Login never sends another welcome; it sends its own factor code when needed. Rejected/duplicate registration and demo seeding remain silent. Welcome enqueue failure does not turn an already committed registration into a failed API response; required code enqueue instead returns recoverable `503`.

## Token lifecycle

The plaintext token appears only when proof-backed registration or completed two-factor/trusted-browser login issues it. The initial `202` login challenge issues no token. Sanctum stores token SHA-256 hashes, not plaintext values. Tokens expire 24 hours after issuance, configured in `config/sanctum.php` and recorded in `expires_at`; there is no refresh-token flow. Already issued tokens retain that original expiry during rollout; the new factor requirement gates new logins.

Use the token in the `Authorization` header. Do not put it in URLs, logs or version control. Use HTTPS outside the local loopback development environment. This flow does not create a browser session or accept session/trusted-device cookies as API authentication; Sanctum's unused CSRF-cookie route is disabled. The trust cookie is considered only after a correct password at `/api/login`. CORS grants no cross-origin access or credentialed browser requests. Swagger sends same-origin cookies for the optional trust flow, with its origin/path guard intact. Token abilities do not grant organiser/admin rights: future domain operations must check the current user's role and resource ownership on the server.

Logout deletes the token used for that request and revokes the trusted-browser family identified by the presented cookie. It securely clears that cookie, leaving other API tokens/browser families valid. Retrying with the deleted token returns `401`. `DELETE /api/trusted-devices` forgets all of the actor's browsers and invalidates their pending login code; it does not revoke existing API tokens. An operator can revoke all tokens through trusted Laravel code with `$user->tokens()->delete()`; this is not a public API endpoint.

Expired tokens immediately fail authentication even before their rows are cleaned up. A daily `sanctum:prune-expired --hours=24` task is defined in `routes/console.php`. Compose does not run a scheduler process; invoke the cleanup command manually in local development, or start Laravel's scheduler when deploying:

```powershell
docker compose exec app php artisan sanctum:prune-expired --hours=24
```

## Rate limits

Registration allows 10 requests per minute per IP, plus five per minute and 20 per hour per exact normalized email across IPs. Code request/resend and verification have their own budgets in the [ownership verification guide](registration-email-verification.md). Password login allows 30/minute per IP, five/minute per account/IP and per account across IPs, and 20/hour per account. Login-code issuance has a one-minute per-account cooldown; factor verification has IP/challenge/account budgets in the [login guide](login-email-verification.md). Limits count successful and unsuccessful requests. Known identities use the MySQL account ID, so equivalent spellings share the same budgets. Unknown identities use hashed normalized email keys. Compose uses Redis for shared counters; PHPUnit uses the array store.

## PowerShell example

Start the stack/worker and migrate using the root README. Use a fresh address and read the ownership code in [Mailpit](http://localhost:8025), then run:

```powershell
$registrationEmail = 'alex@example.test'
$emailChallenge = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/registration/email-verifications' -ContentType 'application/json' -Body (@{ email = $registrationEmail } | ConvertTo-Json)
$ownershipCode = Read-Host 'Enter the six-digit code from Mailpit'
$registrationProof = Invoke-RestMethod -Method Post -Uri ('http://localhost:8000/api/registration/email-verifications/' + $emailChallenge.challenge_id + '/verify') -ContentType 'application/json' -Body (@{ code = $ownershipCode } | ConvertTo-Json)

$registrationBody = @{
    name = 'Alex Example'
    email = $registrationEmail
    password = 'example-password'
    password_confirmation = 'example-password'
    registration_token = $registrationProof.registration_token
} | ConvertTo-Json

$registration = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/register' -ContentType 'application/json' -Body $registrationBody
$authHeaders = @{ Authorization = "Bearer $($registration.access_token)" }
Invoke-RestMethod -Uri 'http://localhost:8000/api/user' -Headers $authHeaders
Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/logout' -Headers $authHeaders

$loginBody = @{ email = $registrationEmail; password = 'example-password' } | ConvertTo-Json
$loginStart = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/login' -ContentType 'application/json' -Body $loginBody
$loginCode = Read-Host 'Enter the six-digit login code from Mailpit'
$login = Invoke-RestMethod -Method Post -Uri ('http://localhost:8000/api/login/email-verifications/' + $loginStart.challenge_id + '/verify') -ContentType 'application/json' -Body (@{ code = $loginCode; remember_device = $false } | ConvertTo-Json)
```

The password above is example data, not a seeded account. A second registration with that email returns `422`; only completed login returns a fresh token. For browser remember-device behavior, use Swagger's `remember_device` boolean and the [login guide](login-email-verification.md); this CLI example opts out. Never paste real credentials into shared command history.

## Schema verification boundary

The migrations implement the five domain tables from [issue #27's design](database-design.md), plus Sanctum's published token-table structure. All five domain tables use non-null UTC `DATETIME(6)` creation/update fields. MySQL sessions use UTC and InnoDB. Named foreign keys restrict parent deletion/update; generated keys enforce one confirmed reservation and one waiting entry per event/user while allowing repeated terminal history. Named, case-sensitive checks constrain roles/statuses, terminal timestamps, positive capacity, bounded occupancy and end times.

Laravel's schema builder has no native CHECK helper, so migrations add those checks with MySQL `ALTER TABLE` statements. The application's SQLite connection remains configured, but these migrations do **not** add the checks on SQLite. SQLite results cannot establish the intended constraint, type or locking behavior. The committed PHPUnit suite requires MySQL and refuses databases whose names do not end in `_test` before its migration lifecycle begins. Run `.\scripts\test.ps1` from the repository root to create, use and clean up isolated MySQL 8.4 automatically; see the [testing guide](testing.md). The tests migrate and create their own fixture data, without needing domain seeds.

These tables alone do not prove `confirmed_count` equals confirmed child rows, prevent a user holding both an active reservation and waiting entry, or allocate only published future events. The implemented [booking action and MySQL race tests](reservations.md) enforce those rules for reservation creation. Cancellation, waitlist and future inventory writers must preserve the same event-first protocol; their workflows remain separate tickets.
