# Welcome and reservation confirmation emails

This extends the local Mailpit setup from [issue #4](https://github.com/GLegg18/LeggTix/issues/4) with registration codes, welcome messages after registration and confirmations after a successful API booking. New registration now requires [email ownership proof](registration-email-verification.md); successful account/token and booking response shapes remain unchanged. A welcome message itself is informational, and existing/demo account login is unchanged.

## Templates and content

`resources/views/mail/layout.blade.php` is the reusable HTML frame. The welcome and reservation-confirmation Blade templates supply the message content, with matching plain-text templates. Mailables populate their placeholders at delivery time; rendered bodies are not stored in an application table. Blade escapes customer names, event names and venues in HTML.

The registration-code message supplies the six-digit ownership code, UTC expiry and public request reference. The welcome contains the customer's current name and account-ready guidance. The confirmation includes the customer's name, one reserved place, event name, venue and reference `LT-<reservation ID>`. Start and optional end times are converted from stored UTC instants to the event's IANA timezone, including the timezone abbreviation and UTC offset; the IANA timezone is also printed explicitly. No access token, password, other attendee detail or admin data is included.

## Workflow and transaction boundaries

`AuthService::register()` consumes valid email proof atomically with account/token creation and schedules a welcome only after success. Login, duplicate/invalid registration, model factories and demo seeders do not schedule a welcome. Code requests enqueue the dedicated registration-verification job after commit; a failed mandatory enqueue returns a recoverable `503`, whereas welcome/confirmation enqueue remains best effort.

`ReservationController::store()` schedules confirmation only after a fresh successful booking. Rejected, duplicate, full, unauthorized and invalid booking requests schedule nothing. `ReserveEventAction` stays focused on allocation and sends no email itself, so seeders, factories and internal action calls remain silent.

`TransactionalEmailDispatcher` registers a database after-commit callback. With no open transaction it runs immediately; inside a larger transaction it waits for the outermost commit. Rolling back the enclosing transaction discards its callback. Queue/SMTP calls never run inside the allocation or registration transaction. This uses Laravel's [transaction callbacks](https://api.laravel.com/docs/13.x/Illuminate/Database/DatabaseTransactionsManager.html) and follows its [queue transaction guidance](https://laravel.com/framework/docs/13.x/queues#jobs-and-database-transactions).

The jobs carry scalar user/reservation IDs, use the configured queue connection (`redis` locally, `sync` in PHPUnit), and target the dedicated `emails` queue. `SendWelcomeEmail` re-reads its user and skips a deleted account. `SendReservationConfirmationEmail` re-reads the exact reservation attempt, its holder and event. It skips a deleted/cancelled attempt, a missing holder/event, and any event no longer published or whose start time has arrived. A later rebooking has its own ID: retrying an old cancelled attempt cannot send the replacement's confirmation.

The stale-state check occurs immediately before rendering/sending. A cancellation can still race the SMTP send; no database lock is held during delivery. Messages describe the state observed by the worker, and email is never the source of truth for current inventory.

## Local worker

Follow the [README first-run setup](../README.md#first-run). `docker compose up -d` starts the persistent `worker` with the rest of the services. It waits for the app's HTTP health check, so it cannot race the app's Composer installation against a missing/partial vendor directory. It processes only `emails`, leaving existing `default` jobs alone. The image includes PHP's `pcntl` extension for worker timeouts and graceful signals.

For an existing checkout after this runtime change:

```powershell
docker compose build app worker
docker compose up -d --force-recreate app
docker compose exec app php artisan config:clear
docker compose exec app php artisan migrate
docker compose up -d --force-recreate worker
docker compose logs -f worker
```

Press **Ctrl+C** to stop following logs. The worker continues in the background. Restart it after PHP code changes with `docker compose restart worker`; recreate it after `.env` changes because a plain restart retains the container environment. The app and worker share `.env` and reach SMTP at `mailpit:1025`. The inbox is [http://localhost:8025](http://localhost:8025).

Jobs allow three attempts with 10- and 30-second backoff delays. Their timeout is 30 seconds, shorter than Redis's default 90-second `retry_after`; a timeout marks the job failed. The worker has a 40-second stop grace period and restarts automatically unless explicitly stopped. Inspect failed jobs with `docker compose exec app php artisan queue:failed`; apply migrations first so `failed_jobs` exists. Keep `retry_after` greater than the worker/job timeout if changing the Redis settings.

## Failure and delivery limits

If welcome/booking queue submission fails after a successful database commit, the API still returns its normal success response. The dispatcher writes a sanitized error with message type, scalar record ID and exception class; raw exception messages/payloads and credentials are excluded. A failed log destination is also contained. This is best-effort notification enqueue, without a durable retry record for failed enqueue. Required registration-code enqueue instead returns a sanitized `503` and instructs the client to request a new code after throttling permits it.

SMTP failures in an accepted job use its bounded retries and Laravel failed-job storage. A crash between commit and enqueue can lose an email. A worker crash after SMTP acceptance but before acknowledging the job can produce a duplicate on retry. Retrying a job manually can also resend an eligible message. No exactly-once or lossless delivery is promised; durable outbox/delivery tracking remains future reliability work in [#31](https://github.com/GLegg18/LeggTix/issues/31). None of these outcomes changes a booking, allocates capacity or rolls back a committed account.

The [Mailpit transport smoke check](../README.md#local-email-preview) verifies direct and queued SMTP separately. The [email validation report](issue-4-email-validation.md) records the expanded workflow/template tests and live acceptance, including which checks actually ran. Waitlist promotion messages remain [#15](https://github.com/GLegg18/LeggTix/issues/15).
