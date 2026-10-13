<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LoginEmailVerificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LoginMfaConcurrencyTest extends TestCase
{
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

    #[DataProvider('tokenTransactionOutcomes')]
    public function test_overlapping_code_consumption_mints_one_token_and_device_or_preserves_code_on_rollback(bool $rollbackFirst): void
    {
        [$user, $row, $code] = $this->challenge();
        $uri = '/api/login/email-verifications/'.$row->challenge_id.'/verify';
        $body = ['code' => $code, 'remember_device' => true];
        [$first, $firstBarrier] = $this->worker($uri, $body, $rollbackFirst ? 'hold_rollback' : 'hold');
        $this->awaitJson($firstBarrier.'/ready.json', $first);
        [$second, $secondBarrier] = $this->worker($uri, $body);
        $this->releaseToLock($second, $secondBarrier, 'users');
        file_put_contents($firstBarrier.'/release', 'release');
        $a = $this->awaitResult($first, $firstBarrier);
        $b = $this->awaitResult($second, $secondBarrier);
        $this->assertSame($rollbackFirst ? 500 : 200, $a['status'], json_encode($a));
        $this->assertSame($rollbackFirst ? 200 : 422, $b['status'], json_encode($b));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('trusted_devices', 1);
        $this->assertNotNull(DB::table('login_email_verifications')->value('consumed_at'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('tokenTransactionOutcomes')]
    public function test_overlapping_trusted_cookie_reuse_rotates_only_once_or_preserves_validator_on_rollback(bool $rollbackFirst): void
    {
        [$user, $row, $code] = $this->challenge();
        $login = app(LoginEmailVerificationService::class)->verify($row->challenge_id, $code, true);
        $cookie = $login->trustedDeviceCredential;
        $expiry = DB::table('trusted_devices')->value('expires_at');
        DB::table('login_email_verifications')->where('id', $row->id)->update(['issued_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 61 SECOND)')]);
        $body = ['email' => $user->email, 'password' => 'password'];
        [$first, $firstBarrier] = $this->worker('/api/login', $body, $rollbackFirst ? 'hold_rollback' : 'hold', $cookie);
        $this->awaitJson($firstBarrier.'/ready.json', $first);
        [$second, $secondBarrier] = $this->worker('/api/login', $body, cookie: $cookie);
        $this->releaseToLock($second, $secondBarrier, 'users');
        file_put_contents($firstBarrier.'/release', 'release');
        $a = $this->awaitResult($first, $firstBarrier);
        $b = $this->awaitResult($second, $secondBarrier);
        $this->assertSame($rollbackFirst ? 500 : 200, $a['status'], json_encode($a));
        $this->assertSame($rollbackFirst ? 200 : 202, $b['status'], json_encode($b));
        $this->assertDatabaseCount('personal_access_tokens', 2); // Original OTP token plus one trusted-login winner.
        $this->assertDatabaseCount('trusted_devices', 1);
        $this->assertSame($expiry, DB::table('trusted_devices')->value('expires_at'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function tokenTransactionOutcomes(): array
    {
        return [['rollbackFirst' => false], ['rollbackFirst' => true]];
    }

    #[DataProvider('deviceLockExpirySteps')]
    public function test_expiry_is_rechecked_after_standalone_device_row_lock_wait(string $step): void
    {
        [$user, $row, $code] = $this->challenge();
        $login = app(LoginEmailVerificationService::class)->verify($row->challenge_id, $code, true);
        $cookie = $login->trustedDeviceCredential;
        DB::table('login_email_verifications')->where('id', $row->id)->update(['issued_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 61 SECOND)')]);
        if ($step === 'otp') {
            app(LoginEmailVerificationService::class)->begin(['email' => $user->email, 'password' => 'password']);
            $row = DB::table('login_email_verifications')->where('user_id', $user->id)->sole();
            DB::table('login_email_verifications')->where('id', $row->id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 3 SECOND)')]);
            $uri = '/api/login/email-verifications/'.$row->challenge_id.'/verify';
            $body = ['code' => Crypt::decryptString($row->encrypted_code), 'remember_device' => true];
        } else {
            $uri = '/api/login';
            $body = ['email' => $user->email, 'password' => 'password'];
        }
        // Only the device is locked here: the contender can acquire user/challenge first.
        DB::beginTransaction();
        $device = DB::table('trusted_devices')->lockForUpdate()->sole();
        [$worker, $barrier] = $this->worker($uri, $body, cookie: $cookie);
        $this->releaseToLock($worker, $barrier, 'trusted_devices');
        $table = $step === 'otp' ? 'login_email_verifications' : 'trusted_devices';
        $id = $step === 'otp' ? $row->id : $device->id;
        // For OTP the child holds its challenge lock. Changing it here would deadlock;
        // the fixture expiry was therefore set before starting the contender below.
        if ($step === 'device') {
            DB::table($table)->where('id', $id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        }
        $this->assertSame(1, (int) DB::scalar('SELECT expires_at > UTC_TIMESTAMP(6) FROM '.$table.' WHERE id = ?', [$id]), 'Expiry must still be future when the contender reaches its lock wait.');
        $this->waitForExpiry($table, $id);
        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame($step === 'otp' ? 422 : 202, $result['status'], json_encode($result));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('trusted_devices', 1);
    }

    public static function deviceLockExpirySteps(): array
    {
        return [['device'], ['otp']];
    }

    public function test_password_changed_after_initial_check_cannot_issue_challenge_or_token_after_user_lock_wait(): void
    {
        $user = User::factory()->create();
        DB::beginTransaction();
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker('/api/login', ['email' => $user->email, 'password' => 'password']);
        $this->releaseToLock($worker, $barrier, 'users');
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('changed-password')])->save();
        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame(422, $result['status'], json_encode($result));
        $this->assertContains('email', $result['errors']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('login_email_verifications', 0);
    }

    private function challenge(): array
    {
        $user = User::factory()->create();
        app(LoginEmailVerificationService::class)->begin(['email' => $user->email, 'password' => 'password']);
        $row = DB::table('login_email_verifications')->where('user_id', $user->id)->sole();

        return [$user, $row, Crypt::decryptString($row->encrypted_code)];
    }

    private function worker(string $uri, array $body, string $mode = 'normal', ?string $cookie = null): array
    {
        $barrier = sys_get_temp_dir().'/leggtix-login-'.bin2hex(random_bytes(12));
        File::makeDirectory($barrier, 0700);
        $this->barriers[] = $barrier;
        file_put_contents($barrier.'/input.json', json_encode(['uri' => $uri, 'body' => $body,
            'ip' => '192.0.2.'.count($this->barriers), 'cookies' => $cookie === null ? [] : ['__Host-leggtix_trusted_device' => $cookie]], JSON_THROW_ON_ERROR));
        chmod($barrier.'/input.json', 0600);
        $worker = new Process([PHP_BINARY, base_path('tests/Support/registration-worker.php'), $mode, $barrier], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'), 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null',
        ], timeout: 20);
        $worker->start();
        $this->workers[] = $worker;

        return [$worker, $barrier];
    }

    private function releaseToLock(Process $worker, string $barrier, string $table): void
    {
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        file_put_contents($barrier.'/go', 'go');
        $deadline = microtime(true) + 5;
        while ($worker->isRunning() && microtime(true) < $deadline) {
            foreach (DB::select('SHOW FULL PROCESSLIST') as $session) {
                if ((int) $session->Id === $ready['connection_id'] && is_string($session->Info)
                    && str_contains(strtolower($session->Info), 'for update') && str_contains(strtolower($session->Info), '`'.$table.'`')) {
                    $this->assertTrue($worker->isRunning());

                    return;
                }
            }
            usleep(10000);
        }
        $this->fail('Contender never reached held '.$table.' row: '.$worker->getOutput().' '.$worker->getErrorOutput());
    }

    private function waitForExpiry(string $table, int $id): void
    {
        $deadline = microtime(true) + 5;
        while (! DB::scalar('SELECT expires_at <= UTC_TIMESTAMP(6) FROM '.$table.' WHERE id = ?', [$id])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('MySQL clock did not reach '.$table.' expiry.');
            }
            usleep(20000);
        }
    }

    private function awaitJson(string $path, Process $worker): array
    {
        $deadline = microtime(true) + 12;
        while (true) {
            if (is_file($path) && is_array($json = json_decode(file_get_contents($path), true))) {
                return $json;
            }
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Login worker failed or timed out: '.$worker->getOutput().' '.$worker->getErrorOutput());
            }
            usleep(10000);
        }
    }

    private function awaitResult(Process $worker, string $barrier): array
    {
        $result = $this->awaitJson($barrier.'/result.json', $worker);
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());

        return $result;
    }
}
