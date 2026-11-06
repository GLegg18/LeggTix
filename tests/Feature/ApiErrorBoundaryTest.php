<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiErrorBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('errorEnvironments')]
    public function test_real_management_and_booking_errors_keep_privacy_status_and_business_contracts_without_accept(
        string $environment, bool $debug,
    ): void {
        $this->app['env'] = $environment;
        config(['app.debug' => $debug]);
        Queue::fake();
        $event = Event::factory()->create(['capacity' => 1]);
        $ownerToken = $event->owner->createToken('error-owner')->plainTextToken;
        $customer = User::factory()->create();
        $customerToken = $customer->createToken('error-customer')->plainTextToken;

        // Real resource binding and policies, using the same no-Accept requests
        // a minimal HTTP client sends. Owner credentials must not reveal drafts.
        foreach ([null, $ownerToken, $customerToken] as $token) {
            $hidden = $this->api('GET', '/api/events/'.$event->id, $token)->assertNotFound()
                ->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')
                ->assertExactJson(['message' => 'Resource not found.']);
            $this->api('GET', '/api/events/999999999999', $token)->assertNotFound()->assertExactJson($hidden->json());
        }
        $this->api('GET', '/api/manage/events/'.$event->id)->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        foreach (['', '/reservations', '/waitlist'] as $suffix) {
            $this->api('GET', '/api/manage/events/'.$event->id.$suffix, $customerToken)->assertForbidden()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertExactJson(['message' => 'You are not allowed to perform this action.']);
        }

        $validation = $this->api('PATCH', '/api/manage/events/'.$event->id, $ownerToken, '{"capacity":"1"}')
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->assertEqualsCanonicalizing(['message', 'errors'], array_keys($validation->json()));
        $this->api('PATCH', '/api/manage/events/'.$event->id, $ownerToken, '{"name":')->assertStatus(400)
            ->assertExactJson(['message' => 'The request could not be understood.']);
        $this->assertSame($event->name, $event->fresh()->name);
        $this->assertSame(0, $event->fresh()->confirmed_count);

        $this->api('PATCH', '/api/manage/events/'.$event->id, $ownerToken, '{"status":"published"}')->assertOk();
        $this->api('POST', '/api/events/'.$event->id.'/reservations', $customerToken, '{}')->assertCreated();
        foreach ([[$customerToken, 'already_reserved'], [$ownerToken, 'full']] as [$token, $code]) {
            $conflict = $this->api('POST', '/api/events/'.$event->id.'/reservations', $token, '{}')
                ->assertConflict()->assertJsonPath('code', $code)->assertHeader('Cache-Control', 'no-store, private');
            $this->assertEqualsCanonicalizing(['message', 'code'], array_keys($conflict->json()));
        }
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame(1, $event->reservations()->where('status', 'confirmed')->count());
    }

    public static function errorEnvironments(): array
    {
        return ['local debug' => ['local', true], 'production debug' => ['production', true], 'production normal' => ['production', false]];
    }

    private function api(string $method, string $path, ?string $token = null, ?string $body = null): TestResponse
    {
        Auth::forgetGuards();
        $headers = $token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if ($body !== null) $headers['CONTENT_TYPE'] = 'application/json';

        return $this->call($method, $path, [], [], [], $headers, $body);
    }
}
