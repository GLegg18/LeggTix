# Email verification, login challenges and trusted devices

The registration policy was agreed on 12 October 2026 and extended on 13 October 2026: new accounts require email ownership before signup, and every new password login requires an emailed code unless that browser presents a valid seven-day trusted-device credential. The login policy applies to all accounts, including historical and demo users.

## Registration and login

Registration remains email → emailed code → one-use registration proof → name/password signup. Proof consumption, account creation and Sanctum token issuance share one transaction. Welcome emails are informational and are separate from verification codes.

Login checks the password first. Without a valid trusted device it returns a short-lived challenge, with no Sanctum token. A correct emailed code completes login and can optionally remember that browser. Incorrect credentials, failed challenges and email enqueue failures cannot produce an authenticated API token.

Codes expire, have a bounded number of guesses and share mailbox/account limits across IP addresses and replacement challenges. Credential material is excluded from ordinary resources, logs and queued job payloads. Jobs render templates from current temporary state; no generated email body is stored in an application table.

The hidden `email_verified_at` timestamp records the first ownership proof. Signup sets it; a successful login code fills it for a legacy account only when it is null. Subsequent login codes preserve it. A future email-change workflow must reset and reverify ownership; the login challenge/device fingerprint independently invalidates older credentials when email or password changes.

The [authentication API guide](authentication.md) describes the current contract. The [registration guide](registration-email-verification.md) explains pre-account verification. The [email milestone report](issue-4-email-validation.md) and [login validation report](issue-4-login-validation.md) distinguish historical checks from the current flow.

## Seven-day trusted browsers

Remembering a device is optional and can only be enabled by successfully completing a login code. The browser receives a host-only `__Host-` cookie with `Secure`, `HttpOnly`, `SameSite=Strict` and `Path=/`. It is never returned as a JSON credential or stored in browser local storage. HTTPS is required outside local development; modern browsers make a Secure-cookie exception for localhost, which needs real browser acceptance rather than manually supplying a Cookie header in a test. See [Set-Cookie security attributes](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie#secure).

The cookie contains a random device selector and an independent random validator. The server stores only a hash of the validator. Password verification is still required: the cookie only bypasses the email-code step and cannot authenticate protected API requests.

Successful trusted login rotates the validator without extending the original seven-day expiry. A stable device selector allows authenticated logout to revoke that device even if another request has just rotated its cookie. The selector alone cannot authenticate. Users can also revoke all their trusted devices; that operation invalidates pending login challenges to prevent an older code from recreating trust.

Challenges and devices bind to the current account credentials. Changing the password or email invalidates older state. State transitions lock the user first, use database time after locking, and commit code consumption, token issuance and optional device creation atomically. SMTP and queue calls run after commit.

## Rollout and limits

The policy gates all new logins. Already-issued Sanctum tokens retain their existing 24-hour lifetime and remain usable until expiry or explicit revocation; this change does not forcibly log out active API clients. Demo credentials remain local-only and now need their code from Mailpit when logging in.

Email codes depend on mailbox security and are not phishing-resistant. [NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b.html#out-of-band-authenticators) excludes email from its out-of-band authenticator requirements; this implementation makes no NIST assurance-level claim. Stronger future authentication would require a separate authenticator/passkey and recovery policy.

Delivery may fail, duplicate or arrive late. Local Mailpit acceptance does not verify a production provider. Expiry is enforced during requests; scheduled cleanup needs a running Laravel scheduler or manual invocation locally. The validation reports identify checks actually run and remaining limits.
