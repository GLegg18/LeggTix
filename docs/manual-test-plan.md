# Manual acceptance checks

Follow the [first-run setup](../README.md#first-run), then use **PowerShell at the repository root** for these checks. Docker Desktop must be running in Linux-container mode. VS Code's PowerShell terminal works well.

Copy one code block at a time, press Enter, then compare the result with the **Expected** text. Keep the same terminal open: later steps reuse values saved earlier. All commands go in that PowerShell terminal.

Allow about 15 minutes, plus downloads on the first test run. This checks the implemented authentication and schema. Booking is covered by the [reservation acceptance plan](reservation-manual-test-plan.md); waitlist endpoints remain later work.

## 1. Check the three containers

```powershell
docker compose ps
```

**Expected:** `app`, `mysql` and `redis` are running. MySQL and Redis should say `healthy`.

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

**Expected:** all six migrations say `Ran`. They create users, event types, events, reservations, waitlist entries and authentication tokens.

## 3. Run the automated tests

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
```

**Expected:** all tests pass. The [demo data verification report](demo-data-validation.md) records the latest suite size and results; runtime varies by machine.

The script makes a fresh test database, runs migrations and creates the test fixtures, then removes its temporary containers and database. No manual database creation, root password or `.env` change is needed. The execution-policy override applies only to this PowerShell process. The first run downloads and builds dependencies; the build output is normal.

These tests cover authentication, validation, privileges, token expiry, database checks, duplicate active entries, retained history, foreign keys, migration rollback/rerun, and event models/factories. The [event guide](events.md) gives the additional model checks and expected outcomes.

If any test fails, stop here and keep the failure text. See the troubleshooting table at the end.

## 4. Check that your app is still running

```powershell
docker compose ps
curl.exe -sS -i http://localhost:8000/api/health
```

**Expected:** your usual `app`, `mysql` and `redis` containers are still running; app health returns `200` and `{"status":"ok"}`. The test run uses a separate stack and leaves your application data alone.

## 5. Set up a fresh demo account

This saves a few values for later steps. It does not send a request yet.

```powershell
$qaBase = 'http://localhost:8000'
$qaSuffix = [guid]::NewGuid().ToString('N').Substring(0, 8)
$qaEmail = "qa-$qaSuffix@example.test"
$qaPassword = 'local-demo-password'

$qaRegisterBody = @{
    name = 'Demo Customer'
    email = "  $($qaEmail.ToUpperInvariant())  "
    password = $qaPassword
    password_confirmation = $qaPassword
} | ConvertTo-Json
```

**Expected:** no output. That is fine: PowerShell saved the values. The random email makes this walkthrough repeatable.

## 6. Register

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

Think of this as logging in from another device. It uses the same account but gets its own token.

```powershell
$qaLoginBody = @{
    email = " $($qaEmail.ToUpperInvariant()) "
    password = $qaPassword
} | ConvertTo-Json

$qaLoginResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$qaBase/api/login" -ContentType 'application/json' -Body $qaLoginBody
$qaClientB = $qaLoginResponse.Content | ConvertFrom-Json
$qaHeadersB = @{ Authorization = "Bearer $($qaClientB.access_token)" }

$qaLoginResponse.StatusCode
$qaClientB.user
$qaAccount.access_token -ne $qaClientB.access_token
```

**Expected:** `200`, the same user ID as registration, and `True` because the two tokens differ. Email spaces/casing should not prevent login.

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

**Expected:** `204`. The demo account remains, with both tokens revoked. It is safe to repeat the walkthrough: step 5 chooses a new email.

If you are done with Docker for now, you can stop it with:

```powershell
docker compose down
```

This stops the services and keeps the database data. Leave the containers running if you want to continue developing.

## What a complete pass means

- Three running containers; app health `200`; Redis `PONG`; six migrations `Ran`.
- Automated suite: all tests passed; use the latest verification report for the recorded test and assertion counts.
- Register `201`; current user/login `200`; logout `204`.
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
| 429 appeared before the throttle exercise | Wait 61 seconds and retry the failed request once. Repeated manual requests count toward the limits. |
| `$qaHeadersA`/`$qaClientB` is missing | Keep one PowerShell terminal open and complete steps 5-8 in order. |
| A test fails or an unexpected 500 appears | Save the failed test name/response, then inspect the logs below. |

```powershell
docker compose logs --tail 60 app
docker compose logs --tail 60 mysql
docker compose logs --tail 60 redis
```

If you share output for help, omit passwords and access tokens. The useful information is the failing step, HTTP status, error message and failed test name.
