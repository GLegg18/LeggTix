<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Tests\TestCase;
use Throwable;

class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('missingApiPaths')]
    public function test_api_not_found_errors_are_clean_json_even_with_debug_and_html_accept(string $path, ?string $accept): void
    {
        config(['app.debug' => true]);
        $headers = $accept === null ? [] : ['Accept' => $accept];

        $this->get($path, $headers)->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public static function missingApiPaths(): array
    {
        return [
            'API root without Accept' => ['/api', null],
            'unknown API route' => ['/api/no-such-route', null],
            'missing event without Accept' => ['/api/events/999999', null],
            'invalid event ID' => ['/api/events/not-an-event', 'text/html'],
            'API root with HTML Accept' => ['/api', 'text/html'],
        ];
    }

    public function test_hidden_event_statuses_and_missing_events_have_identical_public_errors_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $missing = $this->get('/api/events/999999')->assertNotFound()->json();
        foreach (['draft', 'cancelled', 'completed'] as $status) {
            $event = Event::factory()->create(['status' => $status]);
            $this->get('/api/events/'.$event->id)->assertNotFound()->assertExactJson($missing);
        }
        $this->assertSame(['message' => 'Resource not found.'], $missing);
    }

    #[DataProvider('expectedExceptions')]
    public function test_expected_exceptions_are_sanitized_after_framework_normalization(
        Throwable $exception, int $status, string $message, array $headers = [],
    ): void {
        config(['app.debug' => true]);
        Route::get('/api/error-probe', static fn () => throw $exception);

        $response = $this->get('/api/error-probe')->assertStatus($status)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => $message]);
        foreach ($headers as $header => $value) {
            $response->assertHeader($header, $value);
        }
    }

    public static function expectedExceptions(): array
    {
        return [
            'authentication reason' => [new AuthenticationException('private credential reason'), 401, 'Unauthenticated.'],
            'authorization reason' => [new AuthorizationException('private owner and resource details'), 403, 'You are not allowed to perform this action.'],
            'model identity' => [(new ModelNotFoundException)->setModel(Event::class, [42]), 404, 'Resource not found.'],
            'CSRF internals' => [new TokenMismatchException('private session details'), 419, 'Your session has expired. Refresh the page and try again.'],
            'bad request internals' => [new BadRequestHttpException('private parser details'), 400, 'The request could not be understood.'],
            'unsupported media internals' => [new UnsupportedMediaTypeHttpException('private parser details'), 415, 'Use a supported request content type.'],
            'throttle budget internals' => [new TooManyRequestsHttpException(17, 'private throttle keys'), 429, 'Too many requests. Please try again later.', ['Retry-After' => '17']],
            'temporary transport internals' => [new ServiceUnavailableHttpException(7, 'private transport details'), 503, 'The service is temporarily unavailable. Please try again shortly.', ['Retry-After' => '7']],
            'explicit internal server error' => [new HttpException(500, 'private transport details'), 500, 'An unexpected error occurred. Please try again later.'],
        ];
    }

    public function test_method_not_allowed_retains_allowed_methods(): void
    {
        config(['app.debug' => true]);
        $this->post('/api/events/42')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')
            ->assertExactJson(['message' => 'This HTTP method is not allowed.']);
    }

    public function test_cors_headers_survive_authentication_error_rendering(): void
    {
        config(['app.debug' => true, 'cors.allowed_origins' => ['http://localhost:3000']]);
        $this->get('/api/user', ['Origin' => 'http://localhost:3000'])
            ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_validation_retains_field_errors_without_exception_details(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/error-validation', static fn () => throw ValidationException::withMessages([
            'name' => ['Give the event a name.'],
        ]));

        $response = $this->get('/api/error-validation')->assertUnprocessable()
            ->assertJsonPath('errors.name', ['Give the event a name.']);
        $this->assertEqualsCanonicalizing(['message', 'errors'], array_keys($response->json()));
    }

    #[DataProvider('explicitResponseTypes')]
    public function test_explicit_conflict_and_busy_responses_retain_machine_codes_and_headers(bool $throwResponse, int $status): void
    {
        $body = $status === 409
            ? ['message' => 'You already hold a place.', 'code' => 'already_reserved']
            : ['message' => 'Event management is busy. Please retry shortly.', 'code' => 'event_busy'];
        Route::get('/api/error-explicit', static function () use ($body, $status, $throwResponse) {
            $response = response()->json($body, $status)->header('Retry-After', '1')->header('Cache-Control', 'no-store, private');

            return $throwResponse ? throw new HttpResponseException($response) : $response;
        });

        $this->get('/api/error-explicit')->assertStatus($status)->assertExactJson($body)
            ->assertHeader('Retry-After', '1')->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function explicitResponseTypes(): array
    {
        return [[false, 409], [false, 503], [true, 409], [true, 503]];
    }

    #[DataProvider('unexpectedErrorEnvironments')]
    public function test_unexpected_json_error_details_require_both_development_environment_and_debug(
        string $environment, bool $debug, bool $details,
    ): void {
        $this->app['env'] = $environment;
        config(['app.debug' => $debug]);
        Route::get('/api/error-runtime', static fn () => throw new RuntimeException('Unexpected failure sentinel.'));

        $response = $this->get('/api/error-runtime')->assertStatus(500)->assertHeader('Cache-Control', 'no-store, private');
        if ($details) {
            $response->assertJsonPath('message', 'Unexpected failure sentinel.')
                ->assertJsonPath('exception', RuntimeException::class)->assertJsonStructure(['file', 'line', 'trace']);
        } else {
            $response->assertExactJson(['message' => 'An unexpected error occurred. Please try again later.']);
        }
    }

    public static function unexpectedErrorEnvironments(): array
    {
        return [
            ['local', true, true], ['testing', true, true],
            ['local', false, false], ['testing', false, false],
            ['production', true, false], ['production', false, false],
            ['staging', true, false], ['development', true, false],
        ];
    }

    public function test_unexpected_errors_still_reach_the_normal_framework_logger(): void
    {
        $failure = new RuntimeException('Unexpected failure sentinel.');
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->withArgs(static fn (string $message, array $context): bool =>
            $message === $failure->getMessage() && ($context['exception'] ?? null) === $failure);
        $this->app->instance(LoggerInterface::class, $logger);
        Route::get('/api/error-logged', static fn () => throw $failure);

        $this->get('/api/error-logged')->assertStatus(500);
    }

    public function test_ordinary_html_errors_use_framework_rendering_but_json_accept_uses_the_api_policy(): void
    {
        config(['app.debug' => false]);
        $this->get('/no-such-web-route')->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->getJson('/no-such-web-route')->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_api_reference_documents_safe_public_not_found_and_preserved_busy_codes(): void
    {
        $spec = $this->get('/docs/api.json')->assertOk()->json();
        $error = $spec['paths']['/events/{event}']['get']['responses']['404']['content']['application/json'];
        $this->assertSame(['message' => 'Resource not found.'], $error['example']);
        $this->assertFalse($error['schema']['additionalProperties']);
        $this->assertSame(1, $error['schema']['properties']['message']['minLength']);
        $busy = $spec['paths']['/manage/events/{event}']['patch']['responses']['503']['content']['application/json']['schema'];
        $this->assertSame('event_busy', $busy['properties']['code']['const']);
        $this->assertContains('code', $busy['required']);
    }
}
