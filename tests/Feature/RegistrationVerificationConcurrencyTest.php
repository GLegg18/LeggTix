<?php

namespace Tests\Feature;

use App\Models\RegistrationEmailVerification;
use App\Services\RegistrationEmailVerificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CreatesRegistrationProofs;
use Tests\TestCase;

class RegistrationVerificationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use CreatesRegistrationProofs;

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

    #[DataProvider('firstTransactionOutcomes')]
    public function test_competing_signups_can_consume_proof_only_once_and_rollback_preserves_it(bool $rollbackFirst): void
    {
        $proof = $this->registrationProof();
        $body = ['name' => 'Race Customer', 'email' => 'customer@example.test', 'password' => 'correct-password',
            'password_confirmation' => 'correct-password', 'registration_token' => $proof];
        [$first, $firstBarrier] = $this->worker('/api/register', $body, $rollbackFirst ? 'hold_rollback' : 'hold');
        $this->awaitJson($firstBarrier.'/ready.json', $first);
        [$second, $secondBarrier] = $this->worker('/api/register', $body);
        $ready = $this->awaitJson($secondBarrier.'/ready.json', $second);
        file_put_contents($secondBarrier.'/go', 'go');
        $this->assertWaitingOnVerificationLock($ready['connection_id'], $second);
        file_put_contents($firstBarrier.'/release', 'release');
        $a = $this->awaitResult($first, $firstBarrier);
        $b = $this->awaitResult($second, $secondBarrier);
        $this->assertSame($rollbackFirst ? 500 : 201, $a['status'], json_encode($a));
        $this->assertSame($rollbackFirst ? 201 : 422, $b['status'], json_encode($b));
        if (! $rollbackFirst) {
            $this->assertContains('registration_token', $b['errors']);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $row = RegistrationEmailVerification::sole();
        $this->assertNotNull($row->consumed_at);
        $this->assertNull($row->token_hash);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function firstTransactionOutcomes(): array
    {
        return [['rollbackFirst' => false], ['rollbackFirst' => true]];
    }

    public function test_competing_code_verification_requests_mint_only_one_proof(): void
    {
        $challenge = app(RegistrationEmailVerificationService::class)->request('customer@example.test');
        $row = RegistrationEmailVerification::sole();
        $uri = '/api/registration/email-verifications/'.$challenge['challenge_id'].'/verify';
        $body = ['code' => Crypt::decryptString($row->encrypted_code)];
        DB::beginTransaction();
        RegistrationEmailVerification::whereKey($row->id)->lockForUpdate()->firstOrFail();
        $contenders = [];
        for ($contender = 0; $contender < 2; $contender++) {
            [$worker, $barrier] = $this->worker($uri, $body);
            $ready = $this->awaitJson($barrier.'/ready.json', $worker);
            file_put_contents($barrier.'/go', 'go');
            $this->assertWaitingOnVerificationLock($ready['connection_id'], $worker);
            $contenders[] = [$worker, $barrier];
        }
        DB::commit();
        $results = array_map(fn (array $contender): array => $this->awaitResult(...$contender), $contenders);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'));
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['has_proof'])));
        $row->refresh();
        $this->assertNotNull($row->verified_at);
        $this->assertNotNull($row->token_hash);
        $this->assertNull($row->code_hash);
        $this->assertNull($row->encrypted_code);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('expiringSteps')]
    public function test_expiry_is_rechecked_after_actual_mysql_row_lock_wait(string $step): void
    {
        if ($step === 'code') {
            $challenge = app(RegistrationEmailVerificationService::class)->request('customer@example.test');
            $row = RegistrationEmailVerification::sole();
            $uri = '/api/registration/email-verifications/'.$challenge['challenge_id'].'/verify';
            $body = ['code' => Crypt::decryptString($row->encrypted_code)];
            $field = 'expires_at';
            $error = 'code';
        } else {
            $proof = $this->registrationProof();
            $row = RegistrationEmailVerification::sole();
            $uri = '/api/register';
            $body = ['name' => 'Expired Customer', 'email' => 'customer@example.test', 'password' => 'correct-password',
                'password_confirmation' => 'correct-password', 'registration_token' => $proof];
            $field = 'token_expires_at';
            $error = 'registration_token';
        }
        $this->assertGreaterThan(DB::scalar('SELECT UTC_TIMESTAMP(6)'), $row->{$field}->format('Y-m-d H:i:s.u'));
        DB::beginTransaction();
        RegistrationEmailVerification::whereKey($row->id)->lockForUpdate()->firstOrFail();
        [$worker, $barrier] = $this->worker($uri, $body);
        $ready = $this->awaitJson($barrier.'/ready.json', $worker);
        file_put_contents($barrier.'/go', 'go');
        $this->assertWaitingOnVerificationLock($ready['connection_id'], $worker);
        DB::table('registration_email_verifications')->where('id', $row->id)
            ->update([$field => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        $deadline = microtime(true) + 5;
        while (! DB::scalar('SELECT '.$field.' <= UTC_TIMESTAMP(6) FROM registration_email_verifications WHERE id = ?', [$row->id])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('MySQL clock did not reach expiry.');
            }
            usleep(20000);
        }
        DB::commit();
        $result = $this->awaitResult($worker, $barrier);
        $this->assertSame(422, $result['status'], json_encode($result));
        $this->assertContains($error, $result['errors']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($row->fresh()->consumed_at);
    }

    public static function expiringSteps(): array
    {
        return [['code'], ['proof']];
    }

    private function worker(string $uri, array $body, string $mode = 'normal'): array
    {
        $barrier = sys_get_temp_dir().'/leggtix-registration-'.bin2hex(random_bytes(12));
        File::makeDirectory($barrier, 0700);
        $this->barriers[] = $barrier;
        file_put_contents($barrier.'/input.json', json_encode(['uri' => $uri, 'body' => $body, 'ip' => '192.0.2.'.count($this->barriers)], JSON_THROW_ON_ERROR));
        chmod($barrier.'/input.json', 0600);
        $process = new Process([PHP_BINARY, base_path('tests/Support/registration-worker.php'), $mode, $barrier], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'), 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null',
        ], timeout: 20);
        $process->start();
        $this->workers[] = $process;

        return [$process, $barrier];
    }

    private function awaitJson(string $path, Process $process): array
    {
        $deadline = microtime(true) + 12;
        while (true) {
            if (is_file($path) && is_array($json = json_decode(file_get_contents($path), true))) {
                return $json;
            }
            if (! $process->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Registration worker failed or timed out: '.$process->getOutput().' '.$process->getErrorOutput());
            }
            usleep(10000);
        }
    }

    private function assertWaitingOnVerificationLock(int $connectionId, Process $process): void
    {
        $deadline = microtime(true) + 5;
        while ($process->isRunning() && microtime(true) < $deadline) {
            foreach (DB::select('SHOW FULL PROCESSLIST') as $session) {
                if ((int) $session->Id === $connectionId && is_string($session->Info)
                    && str_contains(strtolower($session->Info), 'for update')
                    && str_contains(strtolower($session->Info), '`registration_email_verifications`')) {
                    $this->assertTrue($process->isRunning());

                    return;
                }
            }
            usleep(10000);
        }
        $this->fail('Contender did not reach the held verification row: '.$process->getOutput().' '.$process->getErrorOutput());
    }

    private function awaitResult(Process $process, string $barrier): array
    {
        $result = $this->awaitJson($barrier.'/result.json', $process);
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $result;
    }
}
