<?php

// Real local SMTP/Redis acceptance; never run against a remote mail service.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$smtp = config('mail.mailers.smtp');
if (! $app->environment('local') || config('mail.default') !== 'smtp'
    || ($smtp['transport'] ?? null) !== 'smtp' || ($smtp['host'] ?? null) !== 'mailpit'
    || (int) ($smtp['port'] ?? 0) !== 1025 || ! empty($smtp['url'])
    || ! empty($smtp['username']) || ! empty($smtp['password'])
    || config('queue.default') !== 'redis' || config('queue.connections.redis.driver') !== 'redis'
    || config('queue.connections.redis.connection') !== 'default'
    || config('database.redis.default.host') !== 'redis'
    || (int) config('database.redis.default.port') !== 6379 || ! empty(config('database.redis.default.url'))) {
    fwrite(STDERR, "REFUSED: requires APP_ENV=local, SMTP mailpit:1025 without URL/credentials, and the default local Redis connection without a URL. No mail or jobs created.\n");
    exit(1);
}

$keepMessages = ($argv[1] ?? '') === '--keep-messages';
$tag = 'issue4-'.bin2hex(random_bytes(12));
$queueName = 'mailpit-smoke-'.$tag;
$recipient = $tag.'@example.test';
$subjects = [$tag.' direct', $tag.' queued'];
$queue = null;
$worker = null;
$passed = false;
$exitCode = 0;
$api = static fn () => Http::baseUrl('http://mailpit:8025/api/v1')->connectTimeout(3)->timeout(10);
$messages = static fn () => $api()->get('search', ['query' => $tag])->throw()->json('messages');
$ownedMessages = static fn () => array_values(array_filter(
    $messages(), static fn (array $message): bool => in_array($message['Subject'], $subjects, true),
));

echo 'Probe marker: '.$tag.PHP_EOL;
echo 'Dedicated queue: '.$queueName.PHP_EOL;

try {
    $api()->get('info')->throw();
    $queue = Queue::connection('redis');
    $assert($queue->size($queueName) === 0, 'Dedicated queue must start empty.');

    Mail::raw($tag.' direct SMTP body', static function (Message $message) use ($recipient, $subjects): void {
        $message->to($recipient)->subject($subjects[0]);
    });
    $assert(count($ownedMessages()) === 1, 'Direct SMTP mail was not captured exactly once.');
    echo "PASS configured Laravel mailer sends through mailpit:1025.\n";

    $mailable = (new Mailable)->to($recipient)->subject($subjects[1])->html('<p>'.$tag.' queued SMTP body</p>');
    $queue->pushOn($queueName, new SendQueuedMailable($mailable));
    $assert($queue->size($queueName) === 1, 'Queued mailable must be pending in Redis.');
    $assert(count($ownedMessages()) === 1, 'Queued mail arrived before the worker ran.');
    echo "PASS Redis holds one job; queued mail is absent before worker execution.\n";

    $worker = new Process([
        PHP_BINARY, base_path('artisan'), 'queue:work', 'redis', '--queue='.$queueName,
        '--once', '--tries=1', '--timeout=10', '--no-interaction',
    ], base_path());
    $worker->setTimeout(20)->mustRun();
    $assert($queue->size($queueName) === 0, 'Worker left a pending/reserved/delayed job.');
    $assert(app('queue.failer')->ids($queueName) === [], 'The smoke job failed; inspect queue:failed.');
    $captured = $ownedMessages();
    $assert(count($captured) === 2, 'Separate worker did not capture exactly two total messages.');
    $capturedSubjects = array_column($captured, 'Subject');
    sort($capturedSubjects);
    $assert($capturedSubjects === $subjects, 'Expected one direct message and one queued message.');

    foreach ($captured as $summary) {
        $message = $api()->get('message/'.$summary['ID'])->throw()->json();
        $assert(count($message['To']) === 1 && $message['To'][0]['Address'] === $recipient, 'Captured recipient differs from the .test probe address.');
        $assert(str_contains(($message['Text'] ?? '').($message['HTML'] ?? ''), $tag), 'Captured message body is missing its marker.');
    }
    echo "PASS independent queue:work process delivered matching recipients, subjects and bodies.\n";

    $worker = new Process([
        PHP_BINARY, base_path('artisan'), 'queue:work', 'redis', '--queue='.$queueName,
        '--stop-when-empty', '--max-time=5', '--tries=1', '--timeout=10', '--no-interaction',
    ], base_path());
    $worker->setTimeout(20)->mustRun();
    $assert(count($ownedMessages()) === 2, 'Restarting an empty worker duplicated a message.');
    echo "PASS restarting the empty dedicated worker produces no duplicate delivery.\n";
    $passed = true;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if ($worker?->isRunning()) {
        $worker->stop();
    }

    try {
        if ($queue !== null) {
            $queue->clear($queueName);
            foreach (app('queue.failer')->ids($queueName) as $failedId) {
                app('queue.failer')->forget($failedId);
            }
        }

        if ($passed && $keepMessages) {
            echo 'Kept only probe messages for browser review; search for '.$tag." and delete those two messages afterwards.\n";
        } else {
            $ids = array_column($ownedMessages(), 'ID');
            // An empty ID array deletes the entire Mailpit inbox; never send it.
            if ($ids !== []) {
                $api()->delete('messages', ['IDs' => $ids])->throw();
            }
            $assert($ownedMessages() === [], 'Probe messages remain after cleanup.');
            echo "PASS cleanup removed only this probe's messages and dedicated queue.\n";
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'CLEANUP FAILED for '.$tag.' / '.$queueName.': '.$exception->getMessage().PHP_EOL);
        $exitCode = 1;
    }
}

exit($exitCode);
