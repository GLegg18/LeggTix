# API error responses

Laravel renders `/api` and `/api/*` errors as JSON even when the request omits `Accept` or asks for HTML. Other routes use this JSON policy when the request expects JSON. Ordinary HTML requests keep Laravel's normal error rendering.

`app/Exceptions/ApiErrorResponse.php` supplies the response policy through `bootstrap/app.php`, using Laravel's [exception rendering hooks](https://github.com/laravel/docs/blob/13.x/errors.md). It changes the client response without disabling normal exception reporting.

## Expected errors

Expected authentication and HTTP errors return a status-appropriate, nonempty `message`, without exception class, model identity, file path, line or trace. This applies with `APP_DEBUG=true`, including in the local environment. A draft, cancelled or completed event and a missing event have the same public `404` body:

```json
{"message":"Resource not found."}
```

The same policy covers missing routes and invalid route IDs. Authentication does not make a hidden event visible through the public endpoint; authorised management reads use their separate routes.

| Status | Generic message |
| --- | --- |
| `400` | The request could not be understood. |
| `401` | Unauthenticated. |
| `403` | You are not allowed to perform this action. |
| `404` | Resource not found. |
| `405` | This HTTP method is not allowed. |
| `413` | The request body is too large. |
| `415` | Use a supported request content type. |
| `419` | Your session has expired. Refresh the page and try again. |
| `429` | Too many requests. Please try again later. |
| `500` | An unexpected error occurred. Please try again later. |
| `503` | The service is temporarily unavailable. Please try again shortly. |

Other HTTP exceptions use the standard status description, with a generic fallback if no description exists. Explicit HTTP `500`/`503` exceptions also use their generic messages in local debug mode; their exception text is not returned.

## Validation, business outcomes and headers

Validation keeps Laravel's `422` response with `message` and field-level `errors`. Intentionally constructed workflow responses keep their documented bodies and machine codes, including `409` booking conflicts such as `already_reserved` or `full`, and `503` busy responses such as `event_busy`. Clients should use those documented codes and validation fields for recovery.

HTTP exception headers are preserved, including `Allow` on `405` and `Retry-After` on throttling or temporary unavailability. Existing CORS middleware continues to apply its configured origin rules. Exception-rendered JSON errors carry `Cache-Control: no-store, private`; explicit workflow responses retain their existing cache/retry headers. Sanitising an error does not grant access, retry a mutation or change inventory.

## Unexpected failures and environment configuration

Unexpected exceptions return status `500`. Detailed JSON diagnostics are available only when **both** conditions hold:

- The Laravel environment is exactly `local` or `testing`.
- `APP_DEBUG` is enabled.

In that case Laravel's normal diagnostic JSON can include the exception message/class, file, line and trace. With debug disabled, or in any other environment including `staging`, `production` and an environment named `development`, the response is only:

```json
{"message":"An unexpected error occurred. Please try again later."}
```

The environment check protects unexpected JSON responses even if debug is accidentally enabled outside local/testing. Ordinary HTML error pages still follow Laravel's debug configuration, so production must use `APP_DEBUG=false`. This policy does not change `.env` files or make local debug diagnostics suitable for a publicly reachable service.

Normal Laravel reporting and configured logging remain in place. Unexpected exceptions still reach the framework logger with their diagnostic context; the client message is not the operational error record. Expected exceptions retain Laravel's usual reporting behaviour.

## Verification scope

`ApiErrorResponseTest` covers expected exception sanitisation with debug enabled, hidden/missing event equivalence, API JSON selection, validation/business-response preservation, headers, the environment/debug matrix, framework logging and ordinary HTML behaviour. `ApiErrorBoundaryTest` exercises real event-management policies, strict input and booking conflicts without an `Accept` header in local-debug, production-debug and production-normal configurations.

Use the disposable MySQL runner in the [testing guide](testing.md) to repeat these checks. The [issue #8 pre-PR validation report](issue-8-pre-pr-validation.md) records checks actually executed; the cases described here are coverage descriptions, not a separate test run.
