<?php

namespace App\Documentation;

use App\Enums\ReservationRejection;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use Dedoc\Scramble\Generator;

class ApiSpecification
{
    public function generate(): array
    {
        $specification = app(Generator::class)();

        // A relative server keeps Try it out on the browser's current installation.
        $specification['servers'] = [['url' => '/api', 'description' => 'This installation']];
        $schemas = &$specification['components']['schemas'];
        $schemas['UserResource']['additionalProperties'] = false;
        $schemas['UserResource']['properties']['role']['enum'] = array_column(UserRole::cases(), 'value');
        $schemas['ReservationResource']['additionalProperties'] = false;
        $schemas['ReservationResource']['properties']['status']['enum'] = array_column(ReservationStatus::cases(), 'value');
        $schemas['ReservationResource']['properties']['cancelled_at'] = ['type' => ['string', 'null'], 'format' => 'date-time'];

        foreach (['created_at', 'updated_at'] as $field) {
            $schemas['ReservationResource']['properties'][$field] = ['type' => 'string', 'format' => 'date-time'];
        }

        $schemas['EventResource'] = $this->publicEventSchema();

        // Static inference cannot express custom UTF-8 byte checks or prohibited-role behavior.
        unset($schemas['RegisterRequest']['properties']['role']);
        $schemas['RegisterRequest']['properties']['password'] = $this->passwordSchema() + ['minLength' => 8];
        $schemas['RegisterRequest']['properties']['password_confirmation'] = [
            'type' => 'string', 'format' => 'password', 'writeOnly' => true,
            'description' => 'Must match password.',
        ];
        $schemas['LoginRequest']['properties']['password'] = $this->passwordSchema();
        $schemas['LoginRequest']['additionalProperties'] = false;
        $schemas['VerifyLoginEmailRequest']['additionalProperties'] = false;
        $schemas['VerifyLoginEmailRequest']['properties']['code'] = [
            'type' => 'string', 'pattern' => '^[0-9]{6}$', 'minLength' => 6, 'maxLength' => 6,
            'writeOnly' => true, 'description' => 'The emailed login code as a string, including any leading zero.',
        ];
        $schemas['VerifyLoginEmailRequest']['properties']['remember_device'] = [
            'type' => 'boolean', 'default' => false,
            'description' => 'After successful code verification, remember this browser for seven days using a Secure HttpOnly cookie. Password remains required.',
        ];
        $schemas['RegisterRequest']['properties']['registration_token'] = [
            'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'minLength' => 64, 'maxLength' => 64,
            'writeOnly' => true,
            'description' => 'One-use registration proof returned after verifying the code sent to this exact normalized email. Expires after 10 minutes.',
        ];
        $schemas['StartRegistrationEmailVerificationRequest']['additionalProperties'] = false;
        $schemas['VerifyRegistrationEmailRequest']['additionalProperties'] = false;
        $schemas['VerifyRegistrationEmailRequest']['properties']['code'] = [
            'type' => 'string', 'pattern' => '^[0-9]{6}$', 'minLength' => 6, 'maxLength' => 6,
            'writeOnly' => true, 'description' => 'The emailed code as a string, including any leading zero.',
        ];

        $startVerification = &$specification['paths']['/registration/email-verifications']['post'];
        $startVerification['summary'] = 'Request an email code before registration';
        $startVerification['description'] = 'Send only email as a JSON object. Email is trimmed and lowercased. No account or access token is created. A six-digit code is queued to the address and expires in 10 minutes. Request again to resend: the new challenge replaces all previous codes and registration proofs for that exact email. Existing registered addresses return 422. Limits: 10/minute and 30/hour per IP, 1/minute and 5/hour per exact normalized email across all IPs. A 503 means the email could not be queued; request a new code after the applicable throttle delay.';
        $startVerification['tags'] = ['Registration'];
        $startVerification['requestBody']['content']['application/json']['example'] = ['email' => 'customer@example.test'];
        $startVerification['responses']['202'] = $this->jsonResponse('A verification email was queued. Registration has not completed.', [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'challenge_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'required' => ['challenge_id', 'expires_at'],
        ]);
        $startVerification['responses']['503'] = $this->messageResponse('Verification email could not be queued. Request a new code after the applicable throttle delay.');
        $startVerification['responses']['503']['headers']['Retry-After'] = ['schema' => ['type' => 'integer', 'const' => 1]];

        $verifyEmail = &$specification['paths']['/registration/email-verifications/{challenge}/verify']['post'];
        $verifyEmail['summary'] = 'Verify the email code and obtain registration proof';
        $verifyEmail['description'] = 'Send only the six-digit code as a JSON string field, preserving leading zeros. Up to five failed guesses are allowed for a challenge. An expired, superseded, exhausted, previously verified or unknown challenge returns the same 422 code error. Successful verification returns a high-entropy registration_token once, valid for 10 minutes. Complete /register with the same exact normalized email and this token. If the response is lost, request a new code. Limits: 20/minute and 100/hour per IP, 5/minute per challenge, and 20/hour per target email across all IPs.';
        $verifyEmail['tags'] = ['Registration'];
        $verifyEmail['parameters'] = [[
            'name' => 'challenge', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'description' => 'challenge_id returned by the request-code operation.',
        ]];
        $verifyEmail['requestBody']['content']['application/json']['example'] = ['code' => '123456'];
        $verifyEmail['responses']['200'] = $this->jsonResponse('Email verified. The returned proof can complete registration once.', [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'registration_token' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'required' => ['registration_token', 'expires_at'],
        ]);

        foreach ([&$startVerification, &$verifyEmail] as &$verificationOperation) {
            $verificationOperation['responses']['400'] = $this->messageResponse('The body contains malformed JSON.');
            $verificationOperation['responses']['415'] = $this->messageResponse('A JSON content type is required.');
            $verificationOperation['responses']['422'] = ['$ref' => '#/components/responses/ValidationException'];
            $verificationOperation['responses']['429'] = $this->rateLimitResponse();

            foreach ($verificationOperation['responses'] as &$verificationResponse) {
                if (! isset($verificationResponse['$ref'])) {
                    $verificationResponse['headers']['Cache-Control'] = $this->noStoreHeader();
                }
            }
            unset($verificationResponse);
        }
        unset($verificationOperation);

        $health = &$specification['paths']['/health']['get'];
        $health['summary'] = 'Check API health';
        $health['tags'] = ['Health'];
        $health['operationId'] = 'health';
        $health['responses']['200']['description'] = 'The API is running.';

        $events = &$specification['paths']['/events']['get'];
        $events['summary'] = 'Browse upcoming published events';
        $events['description'] = 'Public, including without a bearer token. Only published events with starts_at strictly after the current UTC time are listed, ordered by starts_at then id ascending. Full events and published events with retired types remain visible. Follow links.next/links.prev or copy the opaque meta cursor unchanged; cursors preserve schedule microseconds. Pagination is live, so edits and events reaching their start can change later pages. Only per_page and cursor query parameters are accepted; request bodies do not supply pagination. Availability is advisory until a reservation succeeds.';
        $events['tags'] = ['Events'];
        $events['security'] = [];
        $events['parameters'] = [
            ['name' => 'per_page', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
            ['name' => 'cursor', 'in' => 'query', 'required' => false,
                'description' => 'Opaque cursor copied unchanged from this endpoint. Invalid cursors return 422.',
                'schema' => ['type' => 'string', 'maxLength' => 512]],
        ];
        unset($events['requestBody']);
        $nullableString = ['type' => ['string', 'null']];
        $events['responses']['200'] = $this->jsonResponse('A bounded cursor page. Empty results return an empty data array.', [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/EventResource']],
                'links' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['first' => ['type' => 'null'], 'last' => ['type' => 'null'],
                        'prev' => $nullableString, 'next' => $nullableString],
                    'required' => ['first', 'last', 'prev', 'next']],
                'meta' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['path' => ['type' => 'string'],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                        'next_cursor' => $nullableString, 'prev_cursor' => $nullableString],
                    'required' => ['path', 'per_page', 'next_cursor', 'prev_cursor']],
            ],
            'required' => ['data', 'links', 'meta'],
        ]);
        $events['responses']['200']['headers']['Cache-Control'] = $this->publicEventNoStoreHeader();
        $events['responses']['422'] = ['$ref' => '#/components/responses/ValidationException'];

        $event = &$specification['paths']['/events/{event}']['get'];
        $event['summary'] = 'View a published event';
        $event['description'] = 'Public published-event detail, including past or full published events and retired event types. Draft, cancelled, completed and missing events all return the same generic 404. UTC schedule instants include the IANA display timezone separately. remaining_capacity reads the MySQL occupancy counter and is advisory; a past event can have remaining capacity but cannot be reserved.';
        $event['tags'] = ['Events'];
        $event['security'] = [];
        $event['parameters'] = [[
            'name' => 'event', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ]];
        $event['responses']['200'] = $this->jsonResponse('Deliberate public fields for one published event.', [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['data' => ['$ref' => '#/components/schemas/EventResource']], 'required' => ['data'],
        ]);
        $event['responses']['200']['headers']['Cache-Control'] = $this->publicEventNoStoreHeader();
        $event['responses']['404'] = $this->messageResponse('The event is missing or is not published.');

        $register = &$specification['paths']['/register']['post'];
        $register['summary'] = 'Complete registration with verified email proof';
        $register['description'] = 'First request and verify an email code using the registration operations. Submit that one-use registration_token with this exact normalized email, name and password to create a regular_user account and obtain a bearer token. The proof expires after 10 minutes and is consumed atomically with account/token creation; a failed write preserves it. Email is trimmed and lowercased. A nonempty role override is rejected. Other unrecognized fields are ignored. Limits: 10/minute per IP, 5/minute and 20/hour per target email across all IPs. Later logins require the password plus email code or an unexpired trusted-browser cookie.';
        $register['requestBody']['content']['application/json']['example'] = [
            'name' => 'API Customer', 'email' => 'customer@example.test',
            'password' => 'example-password', 'password_confirmation' => 'example-password',
            'registration_token' => str_repeat('0', 64),
        ];
        $this->completeTokenResponse($register, '201', 'Account created and authenticated.');
        $register['responses']['429'] = $this->rateLimitResponse();

        $login = &$specification['paths']['/login']['post'];
        $login['summary'] = 'Start a password and email-code login';
        $login['description'] = 'All accounts, including demo and legacy accounts, require the correct password plus an emailed code. Normally returns 202 with a challenge and no access token; complete the login-code operation next. A valid trusted-browser cookie permits 200 after checking the password, rotates its validator, and retains the original seven-day expiry. Invalid credentials return the existing generic 422. To resend, repeat this password request after the one-minute per-account issuance cooldown; the latest challenge replaces the old code. Limits: 30/minute per IP, 5/minute per account/IP and account across all IPs, and 20/hour per account. Use a JSON object with only email/password.';
        $login['requestBody']['content']['application/json']['example'] = [
            'email' => 'demo.user@example.test', 'password' => 'demo-password',
        ];
        $login['responses']['200'] = $register['responses']['201'];
        $login['responses']['200']['description'] = 'Password and a valid trusted browser authenticated the user. The cookie validator is rotated without extending its absolute expiry.';
        $login['responses']['202'] = $startVerification['responses']['202'];
        $login['responses']['202']['description'] = 'Password accepted and login code queued. No account authentication token has been issued.';
        $login['responses']['400'] = $this->messageResponse('The body contains malformed JSON.');
        $login['responses']['415'] = $this->messageResponse('A JSON content type is required.');
        $login['responses']['422'] = ['$ref' => '#/components/responses/ValidationException'];
        $login['responses']['503'] = $this->messageResponse('The login code could not be queued. Retry the password request after the applicable cooldown/throttle delay.');
        $login['responses']['503']['headers']['Retry-After'] = ['schema' => ['type' => 'integer', 'const' => 1]];
        $login['responses']['429'] = $this->rateLimitResponse();

        $verifyLogin = &$specification['paths']['/login/email-verifications/{challenge}/verify']['post'];
        $verifyLogin['summary'] = 'Verify the login code and obtain a bearer token';
        $verifyLogin['description'] = 'Send the six-digit code as a string and optional remember_device boolean, default false. Codes expire in ten minutes, allow five failed guesses and are consumed once atomically with the Sanctum token and optional trusted-browser credential. Wrong, expired, superseded, exhausted, consumed or credential-changed challenges return generic 422 errors.code. remember_device=true sets a host-only Secure HttpOnly SameSite=Strict __Host-leggtix_trusted_device cookie with Path=/ and a seven-day absolute expiry. Its credential is never returned in JSON; HTTPS or a browser supporting Secure cookies on localhost is required. Limits: 20/minute and 100/hour per IP, 5/minute per challenge, and 20/hour per account across all challenges/IPs.';
        $verifyLogin['tags'] = ['Authentication'];
        $verifyLogin['parameters'] = [[
            'name' => 'challenge', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'description' => 'challenge_id returned by the password login operation.',
        ]];
        $verifyLogin['requestBody']['content']['application/json']['example'] = ['code' => '123456', 'remember_device' => false];
        $verifyLogin['responses']['200'] = $register['responses']['201'];
        $verifyLogin['responses']['200']['description'] = 'Both login factors accepted. Copy access_token into Authorize for protected API calls.';
        $verifyLogin['responses']['400'] = $this->messageResponse('The body contains malformed JSON.');
        $verifyLogin['responses']['415'] = $this->messageResponse('A JSON content type is required.');
        $verifyLogin['responses']['422'] = ['$ref' => '#/components/responses/ValidationException'];
        $verifyLogin['responses']['429'] = $this->rateLimitResponse();

        $user = &$specification['paths']['/user']['get'];
        $user['summary'] = 'View your account';
        $user['responses']['200']['description'] = 'The authenticated account.';
        $user['responses']['200']['headers']['Cache-Control'] = $this->noStoreHeader();

        $logout = &$specification['paths']['/logout']['post'];
        $logout['summary'] = 'Revoke the current token';
        $logout['description'] = 'Revokes the bearer token used for this request and the trusted-browser family identified by the presented cookie, then securely expires that cookie. Other tokens/devices remain valid.';
        $logout['responses']['204'] = ['description' => 'Token revoked. No response body.'];

        $revokeDevices = &$specification['paths']['/trusted-devices']['delete'];
        $revokeDevices['summary'] = 'Forget all your trusted browsers';
        $revokeDevices['description'] = 'Requires the bearer token. Revokes only the authenticated user\'s trusted browsers, invalidates their pending login challenge and clears the browser cookie. Existing API tokens remain valid; this operation controls future password logins.';
        $revokeDevices['responses']['204'] = ['description' => 'Trusted browsers revoked. No response body.'];

        $booking = &$specification['paths']['/events/{event}/reservations']['post'];
        $booking['summary'] = 'Reserve one place';
        $booking['description'] = 'Send no body or the empty JSON object {}. Any declared non-JSON content type is rejected, including on a bodyless request. Body and query fields are rejected. The authenticated user is the holder; all roles may book. Only published future events accept reservations. Existing waiters have priority; booking does not join or promote the waitlist. Repeat requests return a conflict without allocating another place. Limits: 30 requests per minute per user and 120 per IP.';
        $booking['requestBody'] = [
            'required' => false,
            'description' => 'Optional empty JSON object. Arrays, scalars and null are invalid.',
            'content' => ['application/json' => [
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'maxProperties' => 0],
                'example' => (object) [],
            ]],
        ];
        $booking['responses']['201']['description'] = 'A confirmed reservation was created.';
        $booking['responses']['201']['content']['application/json']['example'] = [
            'data' => [
                'id' => 1,
                'event_id' => 42,
                'user_id' => 7,
                'status' => ReservationStatus::Confirmed->value,
                'cancelled_at' => null,
                'created_at' => '2026-10-08T12:00:00.123456Z',
                'updated_at' => '2026-10-08T12:00:00.123456Z',
            ],
        ];
        $booking['responses']['201']['headers']['Cache-Control'] = $this->noStoreHeader();
        $booking['responses']['409'] = $this->jsonResponse('Booking rejected without allocating a place.', [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'code' => ['type' => 'string', 'enum' => array_column(ReservationRejection::cases(), 'value')],
            ],
            'required' => ['message', 'code'],
            'additionalProperties' => false,
        ]);
        $booking['responses']['409']['headers']['Cache-Control'] = $this->noStoreHeader();
        $booking['responses']['400'] = $this->messageResponse('The request body contains malformed JSON.');
        $booking['responses']['415'] = $this->messageResponse('Declared content type is unsupported, or a nonempty body lacks a JSON content type.');
        $booking['responses']['422'] = ['$ref' => '#/components/responses/ValidationException'];
        $booking['responses']['429'] = $this->rateLimitResponse();
        $booking['responses']['503']['description'] = 'Database concurrency retries were exhausted. Retry after the indicated delay; this does not mean the event is full.';
        $booking['responses']['503']['headers'] = [
            'Retry-After' => ['description' => 'Retry after one second.', 'schema' => ['type' => 'integer', 'const' => 1]],
            'Cache-Control' => $this->noStoreHeader(),
        ];

        $specification['components']['securitySchemes']['spaSession'] = [
            'type' => 'apiKey', 'in' => 'cookie', 'name' => config('session.cookie'),
            'description' => 'First-party browser session established after signup or both login factors. Initialize /sanctum/csrf-cookie and send credentials with X-XSRF-TOKEN on writes.',
        ];

        foreach ([
            '/registration/email-verifications' => 'post',
            '/registration/email-verifications/{challenge}/verify' => 'post',
            '/register' => 'post', '/login' => 'post', '/login/email-verifications/{challenge}/verify' => 'post',
            '/user' => 'get', '/logout' => 'post', '/trusted-devices' => 'delete',
        ] as $path => $method) {
            $operation = $specification['paths'][$path][$method];
            $operation['operationId'] = 'spa_'.($operation['operationId'] ?? $method.str_replace('/', '_', $path));
            $operation['tags'] = ['Browser authentication'];
            $operation['summary'] = 'Browser session: '.$operation['summary'];
            $operation['description'] = 'Use only from the configured first-party frontend origin with credentials included. Initialize GET /sanctum/csrf-cookie before writing and send its URL-decoded XSRF-TOKEN cookie in X-XSRF-TOKEN. Signup/login completion returns user only and establishes an HttpOnly session; it never creates an API token. The email ownership/factor rules and shared rate limits match the corresponding bearer operation.';
            $operation['security'] = in_array($path, ['/user', '/logout', '/trusted-devices'], true) ? [['spaSession' => []]] : [];
            $operation['responses']['403'] = $this->messageResponse('The origin is not configured for browser authentication.');

            if ($method !== 'get') {
                $operation['responses']['419'] = $this->messageResponse('The session/CSRF token expired. Initialize the CSRF cookie again before retrying.');
            }

            if (in_array($path, ['/register', '/login', '/login/email-verifications/{challenge}/verify'], true)) {
                $status = $path === '/register' ? '201' : '200';
                $operation['responses'][$status] = $this->jsonResponse('Authentication completed and the browser session was regenerated.', [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => ['user' => ['$ref' => '#/components/schemas/UserResource']], 'required' => ['user'],
                ]);
                $operation['responses'][$status]['headers']['Cache-Control'] = $this->noStoreHeader();
            }

            $specification['paths']['/spa'.$path][$method] = $operation;
        }

        return $specification;
    }

    private function passwordSchema(): array
    {
        return [
            'type' => 'string', 'format' => 'password', 'writeOnly' => true,
            'description' => 'Plaintext password; at most 72 UTF-8 bytes and no NUL bytes. The byte limit is not a character limit.',
        ];
    }

    private function publicEventSchema(): array
    {
        $properties = [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'venue' => ['type' => 'string'],
            'starts_at' => ['type' => 'string', 'format' => 'date-time'],
            'ends_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'timezone' => ['type' => 'string', 'description' => 'IANA display timezone; schedule instants are UTC.'],
            'capacity' => ['type' => 'integer', 'minimum' => 1],
            'remaining_capacity' => ['type' => 'integer', 'minimum' => 0,
                'description' => 'Advisory capacity minus MySQL confirmed occupancy; does not guarantee booking eligibility or allocation.'],
            'status' => ['type' => 'string', 'const' => 'published'],
            'event_type' => ['type' => 'object', 'additionalProperties' => false,
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1],
                    'slug' => ['type' => 'string'], 'name' => ['type' => 'string']],
                'required' => ['id', 'slug', 'name']],
        ];

        return ['type' => 'object', 'additionalProperties' => false,
            'properties' => $properties, 'required' => array_keys($properties)];
    }

    private function publicEventNoStoreHeader(): array
    {
        return ['description' => 'Availability and upcoming membership can change; do not cache this response.',
            'schema' => ['type' => 'string', 'example' => 'no-store, private']];
    }

    private function completeTokenResponse(array &$operation, string $status, string $description): void
    {
        $response = &$operation['responses'][$status];
        $response['description'] = $description;
        $response['headers']['Cache-Control'] = $this->noStoreHeader();
        $response['content']['application/json']['schema']['additionalProperties'] = false;
        $response['content']['application/json']['schema']['properties']['expires_at']['format'] = 'date-time';
    }

    private function noStoreHeader(): array
    {
        return ['description' => 'Do not cache this private response.', 'schema' => ['type' => 'string', 'example' => 'no-store, private']];
    }

    private function rateLimitResponse(): array
    {
        return $this->messageResponse('Too many requests. Wait for the Retry-After delay before retrying.') + [
            'headers' => ['Retry-After' => ['schema' => ['type' => 'integer', 'minimum' => 1]]],
        ];
    }

    private function messageResponse(string $description): array
    {
        return $this->jsonResponse($description, [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string']],
            'required' => ['message'],
        ]);
    }

    private function jsonResponse(string $description, array $schema): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }
}
