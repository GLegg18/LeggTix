<?php

// Run only inside the disposable MySQL acceptance environment.
use App\Models\Event;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $eventId, $token, $method, $path, $body, $barrier, $snapshot] = $argv;

try {
    $database = config('database.connections.mysql.database');
    if (! app()->environment('testing') || config('database.default') !== 'mysql'
        || ! is_string($database) || ! str_ends_with($database, '_test')
        || DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
        throw new RuntimeException('Management worker refuses non-test database configuration.');
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    if ($snapshot === 'snapshot') {
        DB::beginTransaction();
        Event::findOrFail((int) $eventId);
        DB::table('reservations')->where('event_id', $eventId)->get();
        DB::table('waitlist_entries')->where('event_id', $eventId)->get();
    }
    file_put_contents($barrier.'/ready.json', json_encode([
        'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
    ], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Management worker barrier timeout.');
        }
        usleep(10000);
    }
    $request = Request::create($path, $method, server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], content: $body);
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $result = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    if ($snapshot === 'snapshot') {
        DB::commit();
    }
} catch (Throwable $exception) {
    $result = ['error' => $exception::class, 'message' => $exception->getMessage()];
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
file_put_contents($barrier.'/result.json', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
