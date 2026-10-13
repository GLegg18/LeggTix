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

        // Static inference cannot express custom UTF-8 byte checks or prohibited-role behavior.
        unset($schemas['RegisterRequest']['properties']['role']);
        $schemas['RegisterRequest']['properties']['password'] = $this->passwordSchema() + ['minLength' => 8];
        $schemas['RegisterRequest']['properties']['password_confirmation'] = [
            'type' => 'string', 'format' => 'password', 'writeOnly' => true,
            'description' => 'Must match password.',
        ];
        $schemas['LoginRequest']['properties']['password'] = $this->passwordSchema();
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

        $register = &$specification['paths']['/register']['post'];
        $register['summary'] = 'Complete registration with verified email proof';
        $register['description'] = 'First request and verify an email code using the registration operations. Submit that one-use registration_token with this exact normalized email, name and password to create a regular_user account and obtain a bearer token. The proof expires after 10 minutes and is consumed atomically with account/token creation; a failed write preserves it. Email is trimmed and lowercased. A nonempty role override is rejected. Other unrecognized fields are ignored. Limits: 10/minute per IP, 5/minute and 20/hour per target email across all IPs. Existing/demo account login is unchanged.';
        $register['requestBody']['content']['application/json']['example'] = [
            'name' => 'API Customer', 'email' => 'customer@example.test',
            'password' => 'example-password', 'password_confirmation' => 'example-password',
            'registration_token' => str_repeat('0', 64),
        ];
        $this->completeTokenResponse($register, '201', 'Account created and authenticated.');
        $register['responses']['429'] = $this->rateLimitResponse();

        $login = &$specification['paths']['/login']['post'];
        $login['summary'] = 'Login and obtain a bearer token';
        $login['description'] = 'Copy access_token from the response into Authorize. Email is trimmed and lowercased. Invalid credentials return 422. Limits: 30 requests per minute per IP and 5 per account/IP pair. Equivalent email spellings resolving to the same account share that budget; unknown addresses use a normalized email/IP budget.';
        $login['requestBody']['content']['application/json']['example'] = [
            'email' => 'demo.user@example.test', 'password' => 'demo-password',
        ];
        $this->completeTokenResponse($login, '200', 'Authenticated.');
        $login['responses']['429'] = $this->rateLimitResponse();

        $user = &$specification['paths']['/user']['get'];
        $user['summary'] = 'View your account';
        $user['responses']['200']['description'] = 'The authenticated account.';
        $user['responses']['200']['headers']['Cache-Control'] = $this->noStoreHeader();

        $logout = &$specification['paths']['/logout']['post'];
        $logout['summary'] = 'Revoke the current token';
        $logout['description'] = 'Revokes only the bearer token used for this request. Other tokens remain valid.';
        $logout['responses']['204'] = ['description' => 'Token revoked. No response body.'];

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

        return $specification;
    }

    private function passwordSchema(): array
    {
        return [
            'type' => 'string', 'format' => 'password', 'writeOnly' => true,
            'description' => 'Plaintext password; at most 72 UTF-8 bytes and no NUL bytes. The byte limit is not a character limit.',
        ];
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
