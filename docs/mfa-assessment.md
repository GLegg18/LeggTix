# Registration email verification and MFA scope

Decision date: 12 October 2026. The requested first step is required email ownership verification before creating a new account: enter an email address, receive and verify a code, then submit name and password to complete registration.

This is registration email verification. Login continues to use the password and a Sanctum bearer token; there is no second-factor login challenge, authenticator-app enrollment or recovery-code workflow. Existing accounts and local demo credentials remain usable. The welcome email is informational and is sent after successful registration; the separate verification email supplies the signup code.

## Accepted registration contract

- Request a short-lived email code before creating an account or issuing an API token.
- Exchange a correct code for a short-lived, one-use registration proof bound to the exact normalized email address.
- Require that proof when submitting the name and password. Consume it atomically with account creation and token issuance.
- Bound code guesses, resends and request rates. A replacement challenge invalidates the previous code and registration proof.
- Keep credential material out of ordinary resources, logs and queue payloads. Generate the email from a template when the job runs.
- Record verification time on newly created accounts. Historical accounts may have no verification timestamp and are not forced through a new login policy.

The [authentication API guide](authentication.md) and [registration verification guide](registration-email-verification.md) describe the endpoints and their errors. The [expanded issue #4 validation report](issue-4-email-validation.md) records checks actually run; this scope note is not a claim that a test has passed.

## Why the distinction matters

Email ownership must be established before signup completes. [OWASP's email verification guidance](https://cheatsheetseries.owasp.org/cheatsheets/Email_Validation_and_Verification_Cheat_Sheet.html#email-ownership-verification) describes single-use, expiring ownership proofs. Laravel's [standard verification workflow](https://laravel.com/framework/docs/13.x/verification) normally starts with an existing user; this app instead keeps temporary registration verification state before creating that user.

Any future requirement for a second factor on login needs its own enrollment, challenge and recovery policy. It is outside this registration-only change.
