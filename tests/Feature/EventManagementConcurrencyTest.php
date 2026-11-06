<?php

namespace Tests\Feature;

use App\Actions\ManageEventAction;
use App\Actions\ReserveEventAction;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EventManagementConcurrencyTest extends TestCase
{
    // Workers must see committed fixtures and have independent InnoDB sessions.
    use DatabaseMigrations;

    private array $workers = [];

    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->barriers as $barrier) {
            File::deleteDirectory($barrier);
        }
        parent::tearDown();
    }

    public function test_capacity_reduction_rechecks_booking_committed_while_editor_waits(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 2]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $contender = User::factory()->create();
        $token = $event->owner->createToken('race-editor')->plainTextToken;
        DB::beginTransaction();
        Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker($event, $token, 'PATCH', '/api/manage/events/'.$event->id, ['capacity' => 1], true);
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        file_put_contents($barrier.'/go', 'go');
        $this->assertWaitingOnEventLock($ready['connection_id'], $worker);
        app(ReserveEventAction::class)->execute($contender, $event->id);
        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame(422, $result['status'], json_encode($result));
        $this->assertArrayHasKey('capacity', $result['body']['errors']);
        $this->assertSame(2, $event->fresh()->capacity);
        $this->assertInventory($event, 2);
    }

    public function test_schedule_edit_sees_history_created_after_its_old_repeatable_read_snapshot(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 2]);
        $contender = User::factory()->create();
        $token = $event->owner->createToken('race-editor')->plainTextToken;
        DB::beginTransaction();
        Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker($event, $token, 'PATCH', '/api/manage/events/'.$event->id, [
            'starts_at' => $event->starts_at->addDay()->toISOString(),
        ], true);
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        file_put_contents($barrier.'/go', 'go');
        $this->assertWaitingOnEventLock($ready['connection_id'], $worker);
        app(ReserveEventAction::class)->execute($contender, $event->id);
        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame(422, $result['status'], json_encode($result));
        $this->assertArrayHasKey('starts_at', $result['body']['errors']);
        $this->assertTrue($event->starts_at->equalTo($event->fresh()->starts_at));
        $this->assertInventory($event, 1);
    }

    public function test_pending_booking_rechecks_cancelled_event_after_owner_commits_cancellation(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 2]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $customer = User::factory()->create();
        $token = $customer->createToken('race-booking')->plainTextToken;
        DB::beginTransaction();
        Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker($event, $token, 'POST', '/api/events/'.$event->id.'/reservations');
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        file_put_contents($barrier.'/go', 'go');
        $this->assertWaitingOnEventLock($ready['connection_id'], $worker);
        app(ManageEventAction::class)->cancel($event->owner, $event->id);
        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame(409, $result['status'], json_encode($result));
        $this->assertSame('event_not_bookable', $result['body']['code']);
        $this->assertInventory($event, 0);
        $this->assertSame('cancelled', $event->fresh()->status->value);
        $this->assertSame(0, DB::table('reservations')->where('event_id', $event->id)->where('user_id', $customer->id)->count());
    }

    public function test_capacity_increase_gives_existing_fifo_waiter_the_place_before_waiting_newcomer(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        app(ReserveEventAction::class)->execute(User::factory()->create(), $event->id);
        $waiter = User::factory()->create();
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $waitlistId = DB::table('waitlist_entries')->insertGetId([
            'event_id' => $event->id, 'user_id' => $waiter->id, 'status' => 'waiting',
            'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $newcomer = User::factory()->create();
        $token = $newcomer->createToken('race-booking')->plainTextToken;
        DB::beginTransaction();
        Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker($event, $token, 'POST', '/api/events/'.$event->id.'/reservations', snapshot: true);
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        file_put_contents($barrier.'/go', 'go');
        $this->assertWaitingOnEventLock($ready['connection_id'], $worker);
        app(ManageEventAction::class)->update($event->owner, $event->id, ['capacity' => 2]);
        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame(409, $result['status'], json_encode($result));
        $this->assertSame('full', $result['body']['code']);
        $this->assertInventory($event, 2);
        $this->assertDatabaseHas('reservations', ['event_id' => $event->id, 'user_id' => $waiter->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $waitlistId, 'status' => 'promoted']);
        $this->assertDatabaseMissing('reservations', ['event_id' => $event->id, 'user_id' => $newcomer->id]);
    }

    private function assertInventory(Event $event, int $expected): void
    {
        $current = $event->fresh();
        $actual = DB::table('reservations')->where('event_id', $event->id)->where('status', 'confirmed')->count();
        $this->assertSame($expected, $actual);
        $this->assertSame($expected, $current->confirmed_count);
        $this->assertLessThanOrEqual($current->capacity, $actual);
    }

    private function worker(Event $event, string $token, string $method, string $path, array $body = [], bool $snapshot = false): array
    {
        $barrier = sys_get_temp_dir().'/leggtix-management-'.bin2hex(random_bytes(12));
        File::makeDirectory($barrier, 0700);
        $this->barriers[] = $barrier;
        $worker = new Process([
            PHP_BINARY, base_path('tests/Support/event-management-worker.php'),
            (string) $event->id, $token, $method, $path, json_encode((object) $body, JSON_THROW_ON_ERROR),
            $barrier, $snapshot ? 'snapshot' : 'normal',
        ], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'),
        ], timeout: 20);
        $worker->start();
        $this->workers[] = $worker;

        return [$worker, $barrier];
    }

    private function awaitJson(string $path, Process $worker): array
    {
        $deadline = microtime(true) + 10;
        while (true) {
            if (is_file($path)) {
                $value = json_decode(file_get_contents($path), true);
                if (is_array($value)) {
                    return $value;
                }
            }
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Management worker failed or timed out: '.$worker->getOutput().' '.$worker->getErrorOutput());
            }
            usleep(10000);
        }
    }

    private function assertWaitingOnEventLock(int $connectionId, Process $worker): void
    {
        $deadline = microtime(true) + 5;
        while ($worker->isRunning() && microtime(true) < $deadline) {
            foreach (DB::select('SHOW FULL PROCESSLIST') as $session) {
                if ((int) $session->Id === $connectionId && is_string($session->Info)
                    && str_contains(strtolower($session->Info), 'for update')
                    && str_contains(strtolower($session->Info), '`events`')) {
                    $this->assertTrue($worker->isRunning());

                    return;
                }
            }
            usleep(10000);
        }
        $this->fail('Management contender never waited for event lock: '.$worker->getOutput().' '.$worker->getErrorOutput());
    }

    private function awaitResult(Process $worker, string $barrier): array
    {
        $result = $this->awaitJson($barrier.'/result.json', $worker);
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());

        return $result;
    }
}
