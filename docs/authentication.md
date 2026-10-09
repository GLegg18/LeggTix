# Authentication API

Implemented for [issue #5](https://github.com/GLegg18/LeggTix/issues/5). This milestone uses [Laravel Sanctum API tokens](https://laravel.com/framework/docs/13.x/sanctum#api-token-authentication) and Laravel's credential checking/password hashing. It supports API clients with bearer tokens; a future Nuxt browser integration should use Sanctum's first-party session/cookie flow with CSRF protection.

Use the local [Swagger API explorer](http://localhost:8000/docs/api) for operation schemas and interactive requests. Log in there, copy the issued `access_token` into **Authorize** without adding `Bearer`, then call the protected operations. Tokens are not persisted across page reloads. The reference below records implementation decisions and console examples.

## Routes and responses

All paths below are relative to `http://localhost:8000`. Send JSON request bodies with `Content-Type: application/json`. Sending `Accept: application/json` is recommended; API errors still return JSON if it is omitted.

| Method and path | Input / authorization | Success |
| --- | --- | --- |
| `POST /api/register` | `name`, `email`, `password`, `password_confirmation` | `201`, user and new token |
| `POST /api/login` | `email`, `password` | `200`, user and new token |
| `GET /api/user` | `Authorization: Bearer <token>` | `200`, current user in `data` |
| `POST /api/logout` | `Authorization: Bearer <token>` | `204`, empty body; current token revoked |

Registration and login return:

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
- Registration requires matching password confirmation and at least eight characters. Passwords are at most 72 **bytes** and cannot contain null bytes, on registration and login. The byte limit prevents bcrypt from treating different long passwords as the same secret; multibyte characters can use several bytes each. Passwords are not trimmed.
- Registration always creates `regular_user`. Supplying a nonempty `role` is rejected; other unexpected fields cannot assign IDs, hashes, timestamps or privileges. `role` is excluded from model mass assignment and cast to `UserRole`.
- Trusted factories can create `organiser` and `admin` users for tests. The local/testing demo seeder creates a regular user and an organiser, as described in the [demo setup](../README.md#demo-data-and-login); it creates no admin or access token. Public role management, password reset and email verification remain separate work.

## Token lifecycle

The plaintext token appears only when registration/login issues it. Sanctum stores its SHA-256 hash, not the plaintext value. Each login creates an independent token. Tokens expire 24 hours after issuance, configured in `config/sanctum.php` and recorded in `expires_at`; there is no refresh-token flow. Log in again after expiry.

Use the token in the `Authorization` header. Do not put it in URLs, logs or version control. Use HTTPS outside the local loopback development environment. This flow does not create a browser session or accept session cookies as API authentication; Sanctum's unused CSRF-cookie route is disabled. CORS grants no cross-origin browser access or cookie credentials by default. Add explicitly trusted origins and choose the session/CSRF integration when introducing the frontend. Token abilities do not grant organiser/admin rights: future domain operations must check the current user's role and resource ownership on the server.

Logout deletes only the token used for that request, leaving other devices' tokens valid. Retrying with the deleted token returns `401`. An operator can revoke all tokens for a known user through trusted Laravel code with `$user->tokens()->delete()`; this is not a public API endpoint.

Expired tokens immediately fail authentication even before their rows are cleaned up. A daily `sanctum:prune-expired --hours=24` task is defined in `routes/console.php`. Compose does not run a scheduler process; invoke the cleanup command manually in local development, or start Laravel's scheduler when deploying:

```powershell
docker compose exec app php artisan sanctum:prune-expired --hours=24
```

## Rate limits

Registration allows 10 requests per minute per IP. Login allows 30 requests per minute per IP and five per minute for each account/IP combination. Limits count successful and unsuccessful requests. Known accounts are resolved through the same MySQL email lookup used for authentication, and the identity budget uses their account ID. This preserves the current accent-insensitive database matching: equivalent spellings cannot create separate budgets. Unknown email identities use a separate hashed, trimmed/lowercased key. The configured cache store supplies shared counters; Compose uses Redis. Test suites use the array store. Account-wide abuse controls across different IPs remain public-launch hardening work.

## PowerShell example

Start the stack and migrate using the root README, then run:

```powershell
$registrationBody = @{
    name = 'Alex Example'
    email = 'alex@example.com'
    password = 'example-password'
    password_confirmation = 'example-password'
} | ConvertTo-Json

$registration = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/register' -ContentType 'application/json' -Body $registrationBody
$authHeaders = @{ Authorization = "Bearer $($registration.access_token)" }
Invoke-RestMethod -Uri 'http://localhost:8000/api/user' -Headers $authHeaders
Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/logout' -Headers $authHeaders

$loginBody = @{ email = 'alex@example.com'; password = 'example-password' } | ConvertTo-Json
$login = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/login' -ContentType 'application/json' -Body $loginBody
```

The password above is example data, not a seeded account. A second registration with that email returns `422`; login returns a fresh token. Never paste real credentials into shared command history.

## Schema verification boundary

The migrations implement the five domain tables from [issue #27's design](database-design.md), plus Sanctum's published token-table structure. All five domain tables use non-null UTC `DATETIME(6)` creation/update fields. MySQL sessions use UTC and InnoDB. Named foreign keys restrict parent deletion/update; generated keys enforce one confirmed reservation and one waiting entry per event/user while allowing repeated terminal history. Named, case-sensitive checks constrain roles/statuses, terminal timestamps, positive capacity, bounded occupancy and end times.

Laravel's schema builder has no native CHECK helper, so migrations add those checks with MySQL `ALTER TABLE` statements. The application's SQLite connection remains configured, but these migrations do **not** add the checks on SQLite. SQLite results cannot establish the intended constraint, type or locking behavior. The committed PHPUnit suite requires MySQL and refuses databases whose names do not end in `_test` before its migration lifecycle begins. Run `.\scripts\test.ps1` from the repository root to create, use and clean up isolated MySQL 8.4 automatically; see the [testing guide](testing.md). The tests migrate and create their own fixture data, without needing domain seeds.

These tables alone do not prove `confirmed_count` equals confirmed child rows, prevent a user holding both an active reservation and waiting entry, or allocate only published future events. The implemented [booking action and MySQL race tests](reservations.md) enforce those rules for reservation creation. Cancellation, waitlist and future inventory writers must preserve the same event-first protocol; their workflows remain separate tickets.
