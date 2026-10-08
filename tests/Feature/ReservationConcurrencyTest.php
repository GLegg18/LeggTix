<?php

namespace Tests\Feature;

use App\Actions\ReserveEventAction;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    // RefreshDatabase wraps fixtures in an invisible-to-workers transaction.
    // These tests deliberately commit fixtures and use independent processes.
    use DatabaseMigrations;

    private array $workers = [];

    private array $barriers = [];

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

    #[DataProvider('competingBookings')]
    public function test_overlapping_booking_transactions_preserve_inventory(
        bool $sameActor,
        bool $staleSnapshot,
        bool $rollbackFirst,
        string $secondOutcome,
        ?string $reason,
    ): void {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $firstActor = User::factory()->create();
        $secondActor = $sameActor ? $firstActor : User::factory()->create();
        [$first, $firstBarrier] = $this->worker($event, $firstActor, 'hold');
        $firstReady = $this->awaitJson($firstBarrier.'/ready.json', $first);
        $this->assertSame(1, $firstReady['allocated_count'], 'First contender must hold an allocated place before insertion.');

        [$second, $secondBarrier] = $this->worker($event, $secondActor, $staleSnapshot ? 'snapshot' : 'normal');
        $secondReady = $this->awaitJson($secondBarrier.'/ready.json', $second);
        $this->assertSame(0, $secondReady['snapshot_count'], 'Second contender must initially observe apparent availability.');
        file_put_contents($secondBarrier.'/go', 'go');
        $this->assertWaitingOnEventLock($secondReady['connection_id'], $second);
        file_put_contents($firstBarrier.'/release', $rollbackFirst ? 'rollback' : 'commit');

        $firstResult = $this->awaitResult($first, $firstBarrier);
        $secondResult = $this->awaitResult($second, $secondBarrier);
        $this->assertSame($rollbackFirst ? 'error' : 'confirmed', $firstResult['outcome'], json_encode($firstResult));

        if ($rollbackFirst) {
            $this->assertSame('Injected rollback after increment', $firstResult['message']);
        }

        $this->assertSame($secondOutcome, $secondResult['outcome'], json_encode($secondResult));

        if ($reason !== null) {
            $this->assertSame($reason, $secondResult['reason']);
        }

        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame(1, DB::table('reservations')->where('event_id', $event->id)->where('status', 'confirmed')->count());
        $this->assertDatabaseCount('reservations', 1);
        $winner = $rollbackFirst ? $secondActor : $firstActor;
        $this->assertDatabaseHas('reservations', ['event_id' => $event->id, 'user_id' => $winner->id, 'status' => 'confirmed']);
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public static function competingBookings(): array
    {
        return [
            'last place commit' => [false, false, false, 'rejected', 'full'],
            'last place rollback' => [false, false, true, 'confirmed', null],
            'same actor commit' => [true, false, false, 'rejected', 'already_reserved'],
            'stale repeatable-read full' => [false, true, false, 'rejected', 'full'],
            'stale repeatable-read duplicate' => [true, true, false, 'rejected', 'already_reserved'],
            'stale repeatable-read rollback' => [false, true, true, 'confirmed', null],
        ];
    }

    public function test_event_starting_while_booking_waits_for_event_lock_is_rejected(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $actor = User::factory()->create();
        DB::table('events')->where('id', $event->id)->update([
            'starts_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 3 SECOND)'),
        ]);
        DB::beginTransaction();
        Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker($event, $actor, 'normal');
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        $this->assertGreaterThan($ready['server_now'], $ready['starts_at'], 'Event must be future when contender starts.');
        file_put_contents($barrier.'/go', 'go');
        $this->assertWaitingOnEventLock($ready['connection_id'], $worker);

        $deadline = microtime(true) + 5;

        while (DB::selectOne('SELECT starts_at <= UTC_TIMESTAMP(6) AS started FROM events WHERE id = ?', [$event->id])->started != 1) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Database clock did not reach event start within five seconds.');
            }

            usleep(20000);
        }

        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame(['outcome' => 'rejected', 'reason' => 'event_not_bookable'], $result);
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_real_lock_timeout_exhaustion_returns_busy_after_bounded_retries_without_allocation(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $actor = User::factory()->create();
        DB::beginTransaction();
        Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker($event, $actor, 'api_timeout');
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        file_put_contents($barrier.'/go', 'go');
        $this->assertWaitingOnEventLock($ready['connection_id'], $worker);

        // Keep the blocker open through all three actual one-second MySQL waits.
        $result = $this->awaitResult($worker, $barrier);
        DB::commit();
        $this->assertSame('http', $result['outcome']);
        $this->assertSame(503, $result['status']);
        $this->assertSame('reservation_busy', $result['code']);
        $this->assertSame('1', $result['retry_after']);
        $this->assertGreaterThanOrEqual(2.5, $result['elapsed'], 'Three one-second waits must demonstrate retries, not one immediate error.');
        $this->assertLessThan(8, $result['elapsed'], 'Retry budget must remain bounded.');
        $this->assertSame(0, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 0);

        app(ReserveEventAction::class)->execute($actor, $event->id);
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 1);
    }

    private function worker(Event $event, User $actor, string $mode): array
    {
        $barrier = sys_get_temp_dir().'/leggtix-reservation-'.bin2hex(random_bytes(12));
        File::makeDirectory($barrier, 0700);
        $this->barriers[] = $barrier;
        $worker = new Process([
            PHP_BINARY, base_path('tests/Support/reservation-worker.php'),
            (string) $event->id, (string) $actor->id, $mode, $barrier,
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
                throw new RuntimeException('Reservation worker failed or timed out: '.$worker->getOutput().' '.$worker->getErrorOutput());
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
                    // First contender still holds the same row, so this active
                    // locking statement is necessarily waiting for its release.
                    $this->assertTrue($worker->isRunning());

                    return;
                }
            }

            usleep(10000);
        }

        $this->fail('Competing process never reached the held event lock: '.$worker->getOutput().' '.$worker->getErrorOutput());
    }

    private function awaitResult(Process $worker, string $barrier): array
    {
        $result = $this->awaitJson($barrier.'/result.json', $worker);
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());

        return $result;
    }
}
