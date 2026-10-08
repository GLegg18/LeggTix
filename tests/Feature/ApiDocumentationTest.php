<?php

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('documentationEnvironments')]
    public function test_documentation_is_available_only_in_local_and_testing_even_with_compiled_routes(string $environment, int $status): void
    {
        // Compile already-registered routes, then change environment. The guard
        // must still run for cached route representations, not only registration.
        $router = $this->app['router'];
        $router->setCompiledRoutes($router->getRoutes()->compile());
        $this->app['env'] = $environment;

        foreach (['/docs/api', '/docs/api.json', '/docs/api/assets/api-documentation.js'] as $uri) {
            $this->get($uri)->assertStatus($status);
        }

        foreach (['/docs/api/assets/swagger-ui.css', '/docs/api/assets/swagger-ui-bundle.js'] as $uri) {
            $this->get($uri)->assertStatus($status);
        }
    }

    public static function documentationEnvironments(): array
    {
        return ['local' => ['local', 200], 'testing' => ['testing', 200], 'production' => ['production', 404], 'staging' => ['staging', 404]];
    }

    public function test_local_ui_uses_guarded_local_assets_and_restrictive_headers(): void
    {
        $response = $this->get('/docs/api')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $html = $response->getContent();
        $this->assertStringContainsString('Try it out sends real requests.', $html);
        $this->assertStringContainsString('Tokens stay in this page', $html);
        preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $target) {
            $this->assertStringStartsWith('/docs/api', $target);
        }

        $csp = $response->headers->get('Content-Security-Policy');
        foreach (["default-src 'none'", "script-src 'self'", "connect-src 'self'", "frame-ancestors 'none'", "base-uri 'none'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }

        $initializerResponse = $this->get('/docs/api/assets/api-documentation.js')->assertOk();
        $initializer = file_get_contents($initializerResponse->baseResponse->getFile()->getPathname());
        foreach (['url: "/docs/api.json"', 'persistAuthorization: false', 'queryConfigEnabled: false', 'validatorUrl: null', 'request.redirect = "error"', 'request.credentials = "omit"'] as $setting) {
            $this->assertStringContainsString($setting, $initializer);
        }
        $this->assertStringNotContainsString('localStorage', $initializer);
        $this->assertStringNotContainsString('sessionStorage', $initializer);
    }

    public function test_vendored_assets_match_recorded_hashes_and_asset_route_rejects_unknown_paths(): void
    {
        $hashes = [
            'swagger-ui-bundle.js' => '050bc415ee7048dcd881682678f720264e7da5e373f7461d7c58c755305255f7',
            'swagger-ui.css' => '1ac324f7dcd27e4b9386b4bd6421271ec147e922a22c05ba24b11515e9aa6321',
            'LICENSE' => 'cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30',
        ];

        foreach ($hashes as $file => $sha256) {
            $this->assertSame($sha256, hash_file('sha256', resource_path('vendor/swagger-ui/'.$file)), $file);
        }

        $this->assertStringContainsString('5.33.1', file_get_contents(resource_path('vendor/swagger-ui/README.md')));
        foreach (['LICENSE', 'README.md', '.env', '../swagger-ui.css', '%2e%2e%2f.env'] as $asset) {
            $this->get('/docs/api/assets/'.$asset)->assertNotFound();
        }
        foreach (['/docs/api-docs.json', '/docs/api-assets/swagger-ui-bundle.js', '/api-documentation/openapi.json'] as $alternate) {
            $this->get($alternate)->assertNotFound();
        }
        $this->assertFileDoesNotExist(public_path('docs/api.json'));
        $this->assertFileDoesNotExist(public_path('swagger-ui-bundle.js'));
    }

    public function test_schema_covers_all_api_routes_with_correct_public_and_bearer_security(): void
    {
        $spec = $this->get('/docs/api.json')->assertOk()->json();
        $this->assertStringStartsWith('3.1.', $spec['openapi']);
        $this->assertSame('/api', $spec['servers'][0]['url']);
        $operations = [];

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $operations[] = strtoupper($method).' /api'.$path;
                }
            }
        }

        $routes = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $routes[] = $method.' /'.$route->uri();
                }
            }
        }
        $this->assertCount(6, $operations);
        $this->assertEqualsCanonicalizing($routes, $operations);
        $bearerNames = [];

        foreach ($spec['components']['securitySchemes'] as $name => $scheme) {
            if ($scheme['type'] === 'http' && strtolower($scheme['scheme']) === 'bearer') {
                $bearerNames[] = $name;
            }
        }
        $this->assertNotEmpty($bearerNames);
        foreach ([['/health', 'get'], ['/register', 'post'], ['/login', 'post']] as [$path, $method]) {
            $this->assertSame([], $spec['paths'][$path][$method]['security'] ?? $spec['security'] ?? [], $path.' must be public.');
        }
        foreach ([['/user', 'get'], ['/logout', 'post'], ['/events/{event}/reservations', 'post']] as [$path, $method]) {
            $security = $spec['paths'][$path][$method]['security'] ?? $spec['security'] ?? [];
            $declared = array_merge(...array_map('array_keys', $security));
            $this->assertNotEmpty(array_intersect($bearerNames, $declared), $path.' must require bearer authentication.');
        }
    }

    public function test_authentication_contract_documents_actual_inputs_and_safe_response_shapes(): void
    {
        $spec = $this->get('/docs/api.json')->assertOk()->json();
        $user = $this->resolve($spec['components']['schemas']['UserResource'], $spec);
        $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'role'], array_keys($user['properties']));
        $this->assertFalse($user['additionalProperties']);
        $this->assertEqualsCanonicalizing(['regular_user', 'organiser', 'admin'], $user['properties']['role']['enum']);

        foreach ([['/register', '201'], ['/login', '200']] as [$path, $status]) {
            $operation = $spec['paths'][$path]['post'];
            $schema = $this->resolve($operation['responses'][$status]['content']['application/json']['schema'], $spec);
            $this->assertEqualsCanonicalizing(['user', 'access_token', 'token_type', 'expires_at'], array_keys($schema['properties']));
            $this->assertEqualsCanonicalizing(['user', 'access_token', 'token_type', 'expires_at'], $schema['required']);
            $this->assertSame('date-time', $schema['properties']['expires_at']['format']);
            $this->assertArrayHasKey('422', $operation['responses']);
            $this->assertArrayHasKey('429', $operation['responses']);
        }
        $registration = $this->resolve($spec['paths']['/register']['post']['requestBody']['content']['application/json']['schema'], $spec);
        $this->assertContains('password_confirmation', $registration['required']);
        $this->assertTrue($registration['properties']['password']['writeOnly']);
        $this->assertTrue($registration['properties']['password_confirmation']['writeOnly']);
        $this->assertArrayNotHasKey('role', $registration['properties']);
        $this->assertStringContainsString('72 UTF-8 bytes', $registration['properties']['password']['description']);
        $account = $this->resolve($spec['paths']['/user']['get']['responses']['200']['content']['application/json']['schema'], $spec);
        $this->assertSame(['data'], array_keys($account['properties']));
        $this->assertArrayNotHasKey('content', $spec['paths']['/logout']['post']['responses']['204']);
    }

    public function test_booking_schema_matches_empty_input_safe_resource_conflicts_and_retry_headers(): void
    {
        $spec = $this->get('/docs/api.json')->assertOk()->json();
        $booking = $spec['paths']['/events/{event}/reservations']['post'];
        $this->assertFalse($booking['requestBody']['required']);
        $body = $booking['requestBody']['content']['application/json']['schema'];
        $this->assertSame('object', $body['type']);
        $this->assertFalse($body['additionalProperties']);
        $this->assertSame(0, $body['maxProperties']);
        $this->assertEmpty($body['properties'] ?? []);
        $resource = $this->resolve($spec['components']['schemas']['ReservationResource'], $spec);
        $this->assertEqualsCanonicalizing(['id', 'event_id', 'user_id', 'status', 'cancelled_at', 'created_at', 'updated_at'], array_keys($resource['properties']));
        $this->assertFalse($resource['additionalProperties']);
        $this->assertSame(['string', 'null'], $resource['properties']['cancelled_at']['type']);
        $exampleBody = $booking['responses']['201']['content']['application/json']['example'];
        $this->assertSame(['data'], array_keys($exampleBody));
        $example = $exampleBody['data'];
        $this->assertEqualsCanonicalizing(array_keys($resource['properties']), array_keys($example));
        foreach (['id', 'event_id', 'user_id'] as $field) {
            $this->assertIsInt($example[$field]);
            $this->assertGreaterThan(0, $example[$field]);
        }
        $this->assertSame('confirmed', $example['status']);
        $this->assertNull($example['cancelled_at'], 'A newly confirmed booking example must not already be cancelled.');
        foreach (['created_at', 'updated_at'] as $field) {
            $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $example[$field], new DateTimeZone('UTC'));
            $this->assertInstanceOf(DateTimeImmutable::class, $instant);
            $this->assertSame($example[$field], $instant->format('Y-m-d\TH:i:s.u\Z'), 'Example timestamps must be valid UTC instants.');
        }
        $this->assertSame($example['created_at'], $example['updated_at']);
        foreach (['400', '401', '404', '409', '415', '422', '429', '503'] as $status) {
            $this->assertArrayHasKey($status, $booking['responses']);
        }
        $conflict = $this->resolve($booking['responses']['409']['content']['application/json']['schema'], $spec);
        $this->assertEqualsCanonicalizing(['event_not_bookable', 'already_reserved', 'full', 'already_waitlisted', 'queue_has_priority'], $conflict['properties']['code']['enum']);
        $this->assertFalse($conflict['additionalProperties']);
        $busy = $this->resolve($booking['responses']['503']['content']['application/json']['schema'], $spec);
        $this->assertSame('reservation_busy', $busy['properties']['code']['const'] ?? $busy['properties']['code']['enum'][0] ?? null);
        $this->assertSame(1, $booking['responses']['503']['headers']['Retry-After']['schema']['const']);
        foreach (['201', '409', '503'] as $status) {
            $this->assertArrayHasKey('Cache-Control', $booking['responses'][$status]['headers']);
        }
    }

    public function test_schema_generation_performs_no_database_writes_or_fixture_creation(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->get('/docs/api.json')->assertOk();

        $this->assertSame([], $writes);
        foreach (['users', 'events', 'event_types', 'reservations', 'waitlist_entries', 'personal_access_tokens'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function resolve(array $schema, array $spec): array
    {
        if (! isset($schema['$ref'])) {
            return $schema;
        }
        $this->assertStringStartsWith('#/', $schema['$ref']);
        $target = $spec;
        foreach (explode('/', substr($schema['$ref'], 2)) as $part) {
            $target = $target[$part];
        }

        return $this->resolve($target, $spec);
    }
}
