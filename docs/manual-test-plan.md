# Manual acceptance checks

Follow the [first-run setup](../README.md#first-run), then use **PowerShell at the repository root** for these checks. Docker Desktop must be running in Linux-container mode. VS Code's PowerShell terminal works well.

Copy one code block at a time, press Enter, then compare the result with the **Expected** text. Keep the same terminal open: later steps reuse values saved earlier. All commands go in that PowerShell terminal.

Allow about 15 minutes, plus downloads on the first test run. This checks the implemented authentication and schema. Booking is covered by the [reservation acceptance plan](reservation-manual-test-plan.md); waitlist endpoints remain later work.

## 1. Check the five containers

```powershell
docker compose ps
```

**Expected:** `app`, `mysql`, `redis`, `mailpit` and `worker` are running. The first four should say `healthy`; the worker processes the `emails` queue.

If they are stopped, run this and check again:

```powershell
docker compose up -d
docker compose ps
```

## 2. Check the app, Redis and migrations

```powershell
curl.exe -sS -i http://localhost:8000/api/health
```

**Expected:** `HTTP/1.1 200` and `{"status":"ok"}`. The other header lines are normal.

```powershell
docker compose exec redis redis-cli ping
```

**Expected:** `PONG`.

```powershell
docker compose exec app php artisan migrate:status
```

**Expected:** all nine migrations say `Ran`. They create users, event types, events, reservations, waitlist entries, authentication tokens, failed-job storage, temporary registration/login verifications and trusted-browser records. If an existing checkout shows a pending migration, run `docker compose exec app php artisan migrate`, then check the status again. Restart the queue worker with `docker compose restart worker` after updating application code.

## 3. Run the automated tests

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
```

**Expected:** all tests pass. The [login and trusted-browser validation report](issue-4-login-validation.md) records the current suite size and results; runtime varies by machine.

The script makes a fresh test database, runs migrations and creates the test fixtures, then removes its temporary containers and database. No manual database creation, root password or `.env` change is needed. The execution-policy override applies only to this PowerShell process. The first run downloads and builds dependencies; the build output is normal.

These tests cover authentication, validation, privileges, token expiry, database checks, duplicate active entries, retained history, foreign keys, migration rollback/rerun, and event models/factories. The [event guide](events.md) gives the additional model checks and expected outcomes.

If any test fails, stop here and keep the failure text. See the troubleshooting table at the end.

## 4. Check that your app is still running

```powershell
docker compose ps
curl.exe -sS -i http://localhost:8000/api/health
```

**Expected:** your usual five containers are still running; app health returns `200` and `{"status":"ok"}`. The test run uses a separate stack and leaves your application data alone.

## 5. Set up a fresh demo account

This saves a few values for later steps. It does not send a request yet.

```powershell
$qaBase = 'http://localhost:8000'
$qaSuffix = [guid]::NewGuid().ToString('N').Substring(0, 8)
$qaEmail = "qa-$qaSuffix@example.test"
$qaPassword = 'local-demo-password'

$qaRegisterFields = @{
    name = 'Demo Customer'
    email = "  $($qaEmail.ToUpperInvariant())  "
    password = $qaPassword
    password_confirmation = $qaPassword
}
```

**Expected:** no output. That is fine: PowerShell saved the values. The random email makes this walkthrough repeatable.

## 6. Verify the mailbox, then register

Registration requires email ownership first. This local app sends to Mailpit, which captures `.test` addresses without sending to real recipients. No user, password or access token is created by requesting a code.

First prove that direct registration is refused:

```powershell
$qaRegisterFields | ConvertTo-Json | curl.exe -sS -i -H 'Content-Type: application/json' --data-binary '@-' "$qaBase/api/register"
```

**Expected:** `422` with an error under `registration_token`. No welcome email arrives.

Request a code:

```powershell
$qaChallengeResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$qaBase/api/registration/email-verifications" -ContentType 'application/json' -Body (@{ email = $qaEmail } | ConvertTo-Json)
$qaChallenge = $qaChallengeResponse.Content | ConvertFrom-Json
$qaChallengeResponse.StatusCode
$qaChallenge.challenge_id
$qaChallenge.expires_at
```

**Expected:** `202`, an opaque 64-character challenge ID and an expiry about ten minutes ahead. The response must not contain the code or a registration token.

Open **http://localhost:8025** in a browser. Enter the value of `$qaEmail` in the inbox search box, open **Verify your email for LeggTix**, and copy its six-digit code. The request reference must match `$qaChallenge.challenge_id`. If the inbox is empty, wait a few seconds; if still empty after 30 seconds, inspect `docker compose logs --tail 60 worker`. Do not request repeated codes while diagnosing: a resend rotates the code and is limited to one request per mailbox per minute.

```powershell
$qaCode = Read-Host 'Paste the six-digit code from this recipient in Mailpit'
$qaVerifyUrl = "$qaBase/api/registration/email-verifications/$($qaChallenge.challenge_id)/verify"
$qaWrongCode = if ($qaCode -eq '999999') { '999998' } else { '999999' }
@{ code = $qaWrongCode } | ConvertTo-Json | curl.exe -sS -i -H 'Content-Type: application/json' --data-binary '@-' $qaVerifyUrl
```

**Expected:** `422` with a generic error under `code`; no user or registration token is created. Five wrong guesses disable this code. Continue using the correct code immediately, keeping any leading zero:

```powershell
$qaProofResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri $qaVerifyUrl -ContentType 'application/json' -Body (@{ code = $qaCode } | ConvertTo-Json)
$qaProof = $qaProofResponse.Content | ConvertFrom-Json
$qaProofResponse.StatusCode
$qaProof.expires_at
$qaRegisterFields.registration_token = $qaProof.registration_token
$qaRegisterBody = $qaRegisterFields | ConvertTo-Json
```

**Expected:** `200` and an expiry about ten minutes ahead. The proof is saved in memory; do not print or share it. It works only for this normalized mailbox and only once. Both responses have `Cache-Control: no-store`.

Now create the account with that proof:

```powershell
$qaRegisterResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$qaBase/api/register" -ContentType 'application/json' -Body $qaRegisterBody
$qaAccount = $qaRegisterResponse.Content | ConvertFrom-Json
$qaHeadersA = @{ Authorization = "Bearer $($qaAccount.access_token)" }

$qaRegisterResponse.StatusCode
$qaAccount.user
$qaAccount.expires_at
```

**Expected:**

- Status `201`: the account was created.
- Name `Demo Customer` and email `qa-<your random letters and numbers>@example.test`, lowercase without spaces.
- Role `regular_user`.
- An expiry time about 24 hours ahead, shown in UTC.
- No password or password hash in the displayed user.

Registration also issued a token. The token is saved in `$qaHeadersA`; subsequent requests use it to identify your account. You do not need to copy it manually.

Refresh the Mailpit search. **Welcome to LeggTix** should arrive for the same recipient, containing the submitted name and no password, verification code, registration proof or access token. Login later in this plan must not send another welcome email.

## 7. Ask who is logged in

```powershell
$qaUserResponse = Invoke-WebRequest -UseBasicParsing -Uri "$qaBase/api/user?user_id=999999" -Headers $qaHeadersA
$qaUserResponse.StatusCode
$qaUserResponse.Content
$qaUserResponse.Headers['Cache-Control']
```

**Expected:** status `200`, your own account inside `data`, and a cache header containing `no-store`.

The user fields should be exactly `id`, `name`, `email` and `role`. The made-up `user_id` in the URL must not change whose account is returned.

## 8. Log in as a second client

Think of this as logging in from another device. Every new login, including demo accounts, requires the password and an emailed code unless that browser was explicitly remembered after a previous successful code. This PowerShell walkthrough uses a code each time and does not opt into browser trust.

```powershell
$qaLoginBody = @{
    email = " $($qaEmail.ToUpperInvariant()) "
    password = $qaPassword
} | ConvertTo-Json

$qaLoginResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$qaBase/api/login" -ContentType 'application/json' -Body $qaLoginBody
$qaLoginChallenge = $qaLoginResponse.Content | ConvertFrom-Json

$qaLoginResponse.StatusCode
$qaLoginChallenge.challenge_id
$qaLoginChallenge.expires_at
```

**Expected:** `202`, a challenge ID and a ten-minute expiry. There must be no user or access token in this response; the password has not completed login.

Refresh the Mailpit search for your exact `$qaEmail`. Open the newest **Your LeggTix login code** message. Its request reference must match `$qaLoginChallenge.challenge_id`. Copy the six digits, including any leading zero, then run:

```powershell
$qaLoginCode = Read-Host 'Paste this login challenge code from Mailpit'
$qaLoginVerifyUrl = "$qaBase/api/login/email-verifications/$($qaLoginChallenge.challenge_id)/verify"
$qaLoginProofResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri $qaLoginVerifyUrl -ContentType 'application/json' -Body (@{ code = $qaLoginCode; remember_device = $false } | ConvertTo-Json)
$qaClientB = $qaLoginProofResponse.Content | ConvertFrom-Json
$qaHeadersB = @{ Authorization = "Bearer $($qaClientB.access_token)" }

$qaLoginProofResponse.StatusCode
$qaClientB.user
$qaAccount.access_token -ne $qaClientB.access_token
```

**Expected:** `200`, the same user ID as registration, and `True` because the two tokens differ. Email spaces/casing should not prevent login. The code is one-use; repeating this verification must return `422` and issue no extra token. Wrong/expired codes also return `422`, and five wrong guesses disable a code. To resend, repeat the password request after at least 61 seconds; the replacement challenge invalidates the previous code.

For opt-in **Remember this browser for seven days**, use the real browser walkthrough in the [login validation report](issue-4-login-validation.md#manual-test-plan). It checks Chrome's automatic Secure/HttpOnly cookie handling. A CLI client's cookie behavior on plain HTTP is not a substitute for that browser check. The cookie never authenticates `/api/user` or bookings by itself; their bearer tokens remain required.

## 9. Log out the first client

```powershell
$qaLogoutResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$qaBase/api/logout" -Headers $qaHeadersA
$qaLogoutResponse.StatusCode
```

**Expected:** `204`. An empty response body is normal for logout.

Try that old token again:

```powershell
curl.exe -sS -i -H 'Accept:' -H "Authorization: Bearer $($qaAccount.access_token)" "$qaBase/api/user"
curl.exe -sS -i -H 'Accept:' -X POST -H "Authorization: Bearer $($qaAccount.access_token)" "$qaBase/api/logout"
```

**Expected:** both return `401` and `{"message":"Unauthenticated."}`. The old token has been revoked. `-H 'Accept:'` deliberately omits the Accept header to confirm that authentication errors still return JSON.

Now try the second client:

```powershell
Invoke-RestMethod -Uri "$qaBase/api/user" -Headers $qaHeadersB
```

**Expected:** your account is returned. Logging out client A must not log out client B.

## 10. Check that guests are refused

These are deliberately failing requests. `401` is a **pass** for this step.

```powershell
curl.exe -sS -i -H 'Accept:' "$qaBase/api/user"
curl.exe -sS -i -H 'Accept:' -X POST "$qaBase/api/logout"
curl.exe -sS -i -H 'Accept:' -H 'Authorization: Bearer 999999|invalid' "$qaBase/api/user"
```

**Expected:** all three return `401` and `{"message":"Unauthenticated."}`. No redirect or `500` error.

## 11. Check bad input and privilege protection

**Try registering the same email again:**

```powershell
$qaRegisterBody | curl.exe -sS -i -H 'Content-Type: application/json' --data-binary '@-' "$qaBase/api/register"
```

**Expected:** `422`, with an error under `email`. No duplicate account is created.

**Try registering with no fields:**

```powershell
'{}' | curl.exe -sS -i -H 'Accept:' -H 'Content-Type: application/json' --data-binary '@-' "$qaBase/api/register"
```

**Expected:** `422`, with errors for required fields.

**Try giving yourself admin access:**

```powershell
$qaAdminBody = @{
    name = 'Admin Attempt'
    email = "admin-$qaSuffix@example.test"
    password = $qaPassword
    password_confirmation = $qaPassword
    registration_token = $qaProof.registration_token
    role = 'admin'
} | ConvertTo-Json

$qaAdminBody | curl.exe -sS -i -H 'Content-Type: application/json' --data-binary '@-' "$qaBase/api/register"
```

**Expected:** `422`, with an error under `role`. This request must not create an admin account.

**Try the wrong password:**

```powershell
$qaWrongPasswordBody = @{ email = $qaEmail; password = 'wrong-password' } | ConvertTo-Json
$qaWrongPasswordBody | curl.exe -sS -i -H 'Content-Type: application/json' --data-binary '@-' "$qaBase/api/login"
```

**Expected:** `422`, with `The provided credentials are incorrect.` No new token is issued.

We use `curl.exe` for these checks so expected error responses are easy to read. PowerShell's `Invoke-WebRequest` normally shows such errors in red; that would still be expected for a deliberate invalid request.

## 12. Check rate limiting through the running app

The automated tests use an in-memory cache. This exercise checks throttling through the running Docker app, which normally uses Redis.

Login allows five requests per account/IP combination and 30 requests per IP per minute. Successful and failed requests both count. Equivalent email spellings that resolve to the same account share a budget. A distinct account or new unknown email has a separate identity counter but does not reset the IP counter; if repeating the walkthrough or sharing an IP with other testers, wait 61 seconds before this step.

```powershell
$qaThrottleEmail = "throttle-$([guid]::NewGuid().ToString('N'))@example.test"
$qaThrottleBody = @{ email = $qaThrottleEmail; password = 'wrong-password' } | ConvertTo-Json

1..6 | ForEach-Object {
    Write-Host "Attempt $_"
    $qaThrottleBody | curl.exe -sS -i -H 'Content-Type: application/json' --data-binary '@-' "$qaBase/api/login"
}
```

**Expected:** the first five return `422`; the sixth returns `429` with a `Retry-After` header. The account does not exist, so the first five credential failures are deliberate.

Wait **61 seconds**, then run:

```powershell
$qaThrottleBody | curl.exe -sS -i -H 'Content-Type: application/json' --data-binary '@-' "$qaBase/api/login"
```

**Expected:** `422` again. The rate-limit window has cleared.

## 13. Finish

Log out the second client:

```powershell
$qaFinalLogout = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$qaBase/api/logout" -Headers $qaHeadersB
$qaFinalLogout.StatusCode
```

**Expected:** `204`. The demo account remains, with both tokens revoked. It is safe to repeat the walkthrough: step 5 chooses a new email. In Mailpit, keep the search for your exact `$qaEmail` and delete only its matching registration-code, welcome and login-code messages if you do not need them. Do not use the global inbox deletion command. Temporary verification records are pruned by the documented scheduler; no database reset is needed.

If you are done with Docker for now, you can stop it with:

```powershell
docker compose down
```

This stops the services and keeps the database data. Leave the containers running if you want to continue developing.

## What a complete pass means

- Five running containers; app health `200`; Redis `PONG`; nine migrations `Ran`.
- Automated suite: all tests passed; use the latest verification report for the recorded test and assertion counts.
- Signup-code request `202`; wrong code `422`; correct code `200`; proof-backed registration `201`; password login `202` without a token; correct login-code `200`; current user `200`; logout `204`.
- Registration verification, welcome and login-code emails captured by Mailpit for the exact test recipient; no welcome on rejected signup or login.
- Guest/old token `401`; invalid input/privilege attempt `422`; excessive attempts `429`.
- Logging out one token leaves the other token usable.

That verifies the implemented authentication and database foundations. The [reservation manual plan](reservation-manual-test-plan.md) and [issue #9 report](issue-9-validation.md) cover implemented booking, its authorization boundaries and MySQL inventory races. Event management, cancellation and waitlist promotion remain separate work. The automated suite already checks token expiry and the bcrypt-shaped-password regression; you do not need to wait 24 hours or construct special hashes by hand.

## If something fails

| What you see | What to do |
| --- | --- |
| Containers stopped or connection refused | Start Docker Desktop, run `docker compose up -d`, then check `docker compose ps`. If the app is still installing dependencies, wait for its startup to finish. |
| A database error or `Tests require ... a dedicated MySQL database ending in _test` | Use the isolated command in step 3, which creates its own database and supplies test settings. Save any error that still appears with this command. |
| Script execution is disabled | Use the complete step 3 command, including `powershell -NoProfile -ExecutionPolicy Bypass -File`; the override applies only to that process. |
| Test image download/build fails | Check Docker Desktop and your network connection, then rerun step 3. |
| `Cleanup failed` | Copy the exact retry command the script prints. It includes the temporary project's name. See the [testing guide](testing.md). |
| Expected 201/200 but got 422 | Read the `errors` fields. If repeating manually with an old email, restart from step 5 for a fresh one. |
| Verification code or registration proof expired | Request a new code after the mailbox cooldown, read the newest matching Mailpit message, then repeat verification and registration immediately. Each lifetime is ten minutes. |
| Verification request returned 503 | The required email could not be queued. Inspect worker/app/Redis logs, then request again after the mailbox cooldown; an existing code request does not mean a user exists. |
| 429 appeared before the throttle exercise | Wait 61 seconds and retry the failed request once. Repeated manual requests count toward the limits. |
| `$qaHeadersA`/`$qaClientB` is missing | Keep one PowerShell terminal open and complete steps 5-8 in order. |
| A test fails or an unexpected 500 appears | Save the failed test name/response, then inspect the logs below. |

```powershell
docker compose logs --tail 60 app
docker compose logs --tail 60 mysql
docker compose logs --tail 60 redis
docker compose logs --tail 60 worker
docker compose logs --tail 60 mailpit
```

If you share output for help, omit passwords and access tokens. The useful information is the failing step, HTTP status, error message and failed test name.
