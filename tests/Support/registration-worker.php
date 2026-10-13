<?php

// Independent MySQL/HTTP process; invoked only by isolated PHPUnit concurrency tests.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $mode, $barrier] = $argv;
$wait = static function (string $path): string {
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Registration worker barrier timeout.');
        }
        usleep(10000);
    }

    return trim(file_get_contents($path));
};
try {
    $database = config('database.connections.mysql.database');
    if (! app()->environment('testing') || config('database.default') !== 'mysql'
        || ! is_string($database) || ! str_ends_with($database, '_test')
        || DB::scalar('SELECT DATABASE()') !== $database) {
        throw new RuntimeException('Registration worker refuses non-test configuration.');
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    $ready = ['connection_id' => (int) DB::scalar('SELECT CONNECTION_ID()')];
    if (str_starts_with($mode, 'hold')) {
        PersonalAccessToken::creating(static function () use ($barrier, $ready, $wait, $mode): void {
            file_put_contents($barrier.'/ready.json', json_encode($ready, JSON_THROW_ON_ERROR));
            $wait($barrier.'/release');
            if ($mode === 'hold_rollback') {
                throw new RuntimeException('Injected token rollback');
            }
        });
    } else {
        file_put_contents($barrier.'/ready.json', json_encode($ready, JSON_THROW_ON_ERROR));
        $wait($barrier.'/go');
    }
    $input = json_decode(file_get_contents($barrier.'/input.json'), true, flags: JSON_THROW_ON_ERROR);
    $request = Request::create($input['uri'], 'POST', server: [
        'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $input['ip'],
    ], content: json_encode($input['body'], JSON_THROW_ON_ERROR));
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    // Do not expose codes, proofs, access tokens, SQL or exception messages in process output.
    $result = ['status' => $response->getStatusCode(), 'errors' => array_keys($body['errors'] ?? []),
        'user_id' => $body['user']['id'] ?? null, 'has_proof' => isset($body['registration_token'])];
} catch (Throwable $exception) {
    $result = ['error_class' => $exception::class];
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
file_put_contents($barrier.'/result.json', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
