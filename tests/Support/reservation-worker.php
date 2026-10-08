<?php

// Independent PHP/MySQL session for the deterministic reservation race tests.
// This file is invoked only by PHPUnit inside the isolated test environment.
use App\Actions\ReserveEventAction;
use App\Exceptions\ReservationRejectedException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $eventId, $actorId, $mode, $barrier] = $argv;
$result = [];

$waitFor = static function (string $path): string {
    $deadline = microtime(true) + 15;

    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timeout: '.$path);
        }

        usleep(10000);
    }

    return trim(file_get_contents($path));
};

try {
    $database = config('database.connections.mysql.database');

    if (! app()->environment('testing') || config('database.default') !== 'mysql'
        || ! is_string($database) || ! str_ends_with($database, '_test')
        || DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
        throw new RuntimeException('Reservation worker refuses non-test database configuration.');
    }

    DB::statement('SET SESSION innodb_lock_wait_timeout = '.($mode === 'api_timeout' ? '1' : '10'));
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $actor = User::findOrFail((int) $actorId);

    if ($mode === 'hold') {
        Reservation::creating(static function () use ($eventId, $barrier, $connectionId, $waitFor): void {
            file_put_contents($barrier.'/ready.json', json_encode([
                'connection_id' => $connectionId,
                'allocated_count' => Event::findOrFail((int) $eventId)->confirmed_count,
            ], JSON_THROW_ON_ERROR));

            if ($waitFor($barrier.'/release') === 'rollback') {
                throw new RuntimeException('Injected rollback after increment');
            }
        });
    } else {
        if ($mode === 'snapshot') {
            DB::beginTransaction();
        }

        $snapshot = Event::findOrFail((int) $eventId);
        file_put_contents($barrier.'/ready.json', json_encode([
            'connection_id' => $connectionId, 'snapshot_count' => $snapshot->confirmed_count,
            'starts_at' => $snapshot->starts_at->format('Y-m-d H:i:s.u'),
            'server_now' => DB::selectOne('SELECT UTC_TIMESTAMP(6) AS instant')->instant,
        ], JSON_THROW_ON_ERROR));
        $waitFor($barrier.'/go');
    }

    if ($mode === 'api_timeout') {
        $request = Request::create('/api/events/'.$eventId.'/reservations', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$actor->createToken('timeout-test')->plainTextToken,
        ], content: '{}');
        $kernel = app(HttpKernel::class);
        $started = microtime(true);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $decoded = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $result = [
            'outcome' => 'http', 'status' => $response->getStatusCode(), 'code' => $decoded['code'] ?? null,
            'retry_after' => $response->headers->get('Retry-After'),
            'elapsed' => microtime(true) - $started,
        ];
    } else {
        $reservation = app(ReserveEventAction::class)->execute($actor, (int) $eventId);
        $result = ['outcome' => 'confirmed', 'id' => $reservation->id];
    }

    if ($mode === 'snapshot') {
        DB::commit();
    }

} catch (ReservationRejectedException $exception) {
    $result = ['outcome' => 'rejected', 'reason' => $exception->reason->value];
} catch (Throwable $exception) {
    $result = ['outcome' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

file_put_contents($barrier.'/result.json', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
