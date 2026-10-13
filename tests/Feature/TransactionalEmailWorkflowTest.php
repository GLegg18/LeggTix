<?php

namespace Tests\Feature;

use App\Actions\ReserveEventAction;
use App\Jobs\SendReservationConfirmationEmail;
use App\Jobs\SendWelcomeEmail;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Support\CreatesRegistrationProofs;

class TransactionalEmailWorkflowTest extends TestCase
{
    // Real MySQL commits and the production transaction manager are essential:
    // RefreshDatabase treats its wrapping transaction as already committed.
    use DatabaseMigrations;
    use CreatesRegistrationProofs;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('email_observer');
        parent::tearDown();
    }

    public function test_signup_enqueues_one_scalar_user_id_without_credentials_or_token(): void
    {
        $response = $this->api('/api/register', $this->registration(['email' => '  New.Customer@Example.TEST  ']))
            ->assertCreated();
        $user = User::sole();

        Queue::assertPushed(SendWelcomeEmail::class, function (SendWelcomeEmail $job) use ($user, $response): bool {
            $this->assertSame($user->id, $job->userId);
            $this->assertNull($job->connection, 'Jobs must honor the configured queue connection.');
            $this->assertSame('emails', $job->queue);
            $payload = serialize($job);
            foreach ([$user->name, $user->email, $user->password, 'mail-test-password', $response->json('access_token')] as $secret) {
                $this->assertStringNotContainsString($secret, $payload);
            }
            $this->assertStringNotContainsString('ModelIdentifier', $payload);

            return true;
        });
        Queue::assertPushed(SendWelcomeEmail::class, 1);
        Queue::assertCount(1);
        $this->assertSame('new.customer@example.test', $user->email);
        $this->assertSame($user->id, PersonalAccessToken::sole()->tokenable_id);
    }

    public function test_booking_http_workflow_enqueues_only_the_committed_reservation_id(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $response = $this->api('/api/events/'.$event->id.'/reservations', [], $this->token($actor))->assertCreated();
        $reservation = Reservation::sole();

        Queue::assertPushed(SendReservationConfirmationEmail::class, function (SendReservationConfirmationEmail $job) use ($reservation): bool {
            $this->assertSame($reservation->id, $job->reservationId);
            $this->assertNull($job->connection, 'Jobs must honor the configured queue connection.');
            $this->assertSame('emails', $job->queue);
            $this->assertStringNotContainsString('ModelIdentifier', serialize($job));

            return true;
        });
        Queue::assertCount(1);
        $this->assertSame($reservation->id, $response->json('data.id'));
        $this->assertSame($actor->id, $reservation->user_id);
        $this->assertNotSame($event->owner_id, $reservation->user_id);
        $this->assertSame(1, $event->fresh()->confirmed_count);
    }

    #[DataProvider('workflows')]
    public function test_email_is_enqueued_only_after_the_real_outermost_mysql_commit(string $workflow, string $jobClass, string $table): void
    {
        $trigger = $this->workflow($workflow);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(DatabaseTransactionsManager::class, get_class(app('db.transactions')));
        config(['database.connections.email_observer' => config('database.connections.mysql')]);
        $observer = DB::connection('email_observer');
        $before = $observer->table($table)->count();

        DB::beginTransaction();
        DB::beginTransaction();
        $trigger()->assertCreated();
        Queue::assertNothingPushed();
        $this->assertSame($before, $observer->table($table)->count(), 'An independent MySQL session must not see the uncommitted row.');
        DB::commit();
        Queue::assertNothingPushed();
        $this->assertSame($before, $observer->table($table)->count());
        DB::commit();

        Queue::assertPushed($jobClass, 1);
        Queue::assertCount(1);
        $this->assertSame($before + 1, $observer->table($table)->count());
    }

    #[DataProvider('workflows')]
    public function test_rolling_back_the_outer_transaction_discards_the_email_and_business_row(string $workflow, string $jobClass, string $table): void
    {
        $trigger = $this->workflow($workflow);
        $before = DB::table($table)->count();
        DB::beginTransaction();
        $trigger()->assertCreated();
        Queue::assertNothingPushed();
        DB::rollBack();

        Queue::assertNothingPushed();
        $this->assertSame($before, DB::table($table)->count());
        if ($workflow === 'booking') {
            $this->assertSame(0, Event::sole()->confirmed_count);
        } else {
            $this->assertDatabaseCount('personal_access_tokens', 0);
        }
    }

    #[DataProvider('workflows')]
    public function test_rolling_back_a_savepoint_then_committing_the_parent_discards_its_email(string $workflow, string $jobClass, string $table): void
    {
        $trigger = $this->workflow($workflow);
        $before = DB::table($table)->count();
        DB::beginTransaction();
        DB::beginTransaction();
        $trigger()->assertCreated();
        DB::rollBack();
        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertNothingPushed();
        $this->assertSame($before, DB::table($table)->count());
    }

    #[DataProvider('workflows')]
    public function test_enqueue_failure_preserves_the_success_response_and_committed_business_state(string $workflow, string $jobClass, string $table): void
    {
        $trigger = $this->workflow($workflow);
        $before = DB::table($table)->count();
        $sensitiveMessage = 'redis://private-user:private-password@private-host secret-token';
        Queue::shouldReceive('connection')->once()->withNoArgs()->andThrow(new RuntimeException($sensitiveMessage));
        Log::spy();

        $response = $trigger()->assertCreated();

        $this->assertSame($before + 1, DB::table($table)->count());
        $this->assertStringNotContainsString($sensitiveMessage, $response->getContent());
        if ($workflow === 'booking') {
            $this->assertSame(1, Event::sole()->confirmed_count);
            $this->assertSame('confirmed', $response->json('data.status'));
        } else {
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->assertNotEmpty($response->json('access_token'));
        }
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($sensitiveMessage): bool {
            $this->assertSame(['email_type', 'reference_id', 'exception_type'], array_keys($context));
            $this->assertIsInt($context['reference_id']);
            $this->assertSame(RuntimeException::class, $context['exception_type']);
            $this->assertStringNotContainsString($sensitiveMessage, $message.json_encode($context));
            $this->assertStringNotContainsString('private-password', $message.json_encode($context));
            $this->assertStringNotContainsString('secret-token', $message.json_encode($context));

            return true;
        });
    }

    #[DataProvider('workflows')]
    public function test_broken_queue_and_logger_cannot_fail_an_already_committed_workflow(string $workflow, string $jobClass, string $table): void
    {
        $trigger = $this->workflow($workflow);
        $before = DB::table($table)->count();
        Queue::shouldReceive('connection')->once()->withNoArgs()->andThrow(new RuntimeException('Injected queue outage'));
        Log::shouldReceive('error')->once()->andThrow(new RuntimeException('Injected logger outage'));

        $trigger()->assertCreated();

        $this->assertSame($before + 1, DB::table($table)->count());
        if ($workflow === 'booking') {
            $this->assertSame(1, Event::sole()->confirmed_count);
        } else {
            $this->assertDatabaseCount('personal_access_tokens', 1);
        }
    }

    public static function workflows(): array
    {
        return [
            'welcome' => ['welcome', SendWelcomeEmail::class, 'users'],
            'booking' => ['booking', SendReservationConfirmationEmail::class, 'reservations'],
        ];
    }

    #[DataProvider('workflows')]
    public function test_configured_sync_queue_executes_real_job_and_mime_render_only_after_commit(string $workflow, string $jobClass, string $table): void
    {
        // Restore the real QueueManager after the default enqueue-only fake.
        Queue::clearResolvedInstance('queue');
        $this->app->forgetInstance('queue');
        $this->assertSame('sync', config('queue.default'));
        $transport = Mail::mailer()->getSymfonyTransport();
        $trigger = $this->workflow($workflow);
        DB::beginTransaction();

        $trigger()->assertCreated();
        $this->assertCount(0, $transport->messages());
        DB::commit();

        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->sole()->getOriginalMessage();
        $this->assertNotEmpty($message->getHtmlBody());
        $this->assertNotEmpty($message->getTextBody());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_invalid_duplicate_signup_and_login_send_no_extra_welcome_email(): void
    {
        $this->api('/api/register', $this->registration(['email' => 'invalid']))->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->api('/api/register', $this->registration())->assertCreated();
        Queue::assertPushed(SendWelcomeEmail::class, 1);
        Queue::fake();

        $this->api('/api/register', $this->registration())->assertUnprocessable();
        $this->api('/api/login', ['email' => 'customer@example.test', 'password' => 'mail-test-password'])->assertOk();
        $this->api('/api/login', ['email' => 'customer@example.test', 'password' => 'wrong-password'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_failed_token_storage_rolls_back_signup_without_welcome(): void
    {
        Events::listen('eloquent.creating: '.PersonalAccessToken::class, static function (): never {
            throw new RuntimeException('Injected token write failure');
        });

        $this->api('/api/register', $this->registration())->assertStatus(500);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('rejectedBookings')]
    public function test_rejected_booking_does_not_queue_confirmation(string $scenario, int $status): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $token = $this->token($actor);
        $body = [];
        $eventId = $event->id;
        if ($scenario === 'guest') {
            $token = null;
        } elseif ($scenario === 'holder override') {
            $body = ['user_id' => $event->owner_id];
        } elseif ($scenario === 'missing') {
            $eventId = 999999999;
        } elseif ($scenario === 'draft') {
            $event->forceFill(['status' => 'draft'])->save();
        } elseif (in_array($scenario, ['full', 'duplicate'], true)) {
            app(ReserveEventAction::class)->execute($scenario === 'duplicate' ? $actor : User::factory()->create(), $event->id);
        }
        $before = DB::table('reservations')->count();
        $occupancy = $event->fresh()->confirmed_count;

        $this->api('/api/events/'.$eventId.'/reservations', $body, $token)->assertStatus($status);

        Queue::assertNothingPushed();
        $this->assertSame($before, DB::table('reservations')->count());
        $this->assertSame($occupancy, $event->fresh()->confirmed_count);
    }

    public static function rejectedBookings(): array
    {
        return [
            'guest' => ['guest', 401], 'holder override' => ['holder override', 422],
            'not found' => ['missing', 404], 'draft' => ['draft', 409],
            'full' => ['full', 409], 'duplicate' => ['duplicate', 409],
        ];
    }

    public function test_failed_reservation_write_rolls_back_inventory_without_email(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create(['capacity' => 1]);
        Reservation::creating(static function (): never {
            throw new RuntimeException('Injected reservation write failure');
        });

        $this->api('/api/events/'.$event->id.'/reservations', [], $this->token($actor))->assertStatus(500);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertSame(0, $event->fresh()->confirmed_count);
    }

    public function test_seeders_factories_and_bare_allocation_do_not_send_customer_email(): void
    {
        $this->artisan('db:seed', ['--no-interaction' => true])->assertExitCode(0);
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create();
        app(ReserveEventAction::class)->execute($actor, $event->id);

        $this->assertSame(0, DB::transactionLevel());
        Queue::assertNothingPushed();
        $this->assertSame(1, $event->fresh()->confirmed_count);
    }

    private function workflow(string $workflow): \Closure
    {
        if ($workflow === 'welcome') {
            return fn (): TestResponse => $this->api('/api/register', $this->registration());
        }
        $actor = User::factory()->create();
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $token = $this->token($actor);

        return fn (): TestResponse => $this->api('/api/events/'.$event->id.'/reservations', [], $token);
    }

    private function registration(array $changes = []): array
    {
        $attributes = array_replace([
            'name' => 'Mail Test Customer', 'email' => 'customer@example.test',
            'password' => 'mail-test-password', 'password_confirmation' => 'mail-test-password',
        ], $changes);

        return ['registration_token' => $this->registrationProof($attributes['email'])] + $attributes;
    }

    private function token(User $actor): string
    {
        return $actor->createToken('email-acceptance')->plainTextToken;
    }

    private function api(string $uri, array $data, ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('POST', $uri, [], [], [], $server, json_encode((object) $data, JSON_THROW_ON_ERROR));
    }
}
