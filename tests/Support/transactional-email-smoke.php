<?php

// Real local app HTTP + existing Compose emails worker. Never clears/consumes a shared queue.
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$smtp = config('mail.mailers.smtp');
if (! $app->environment('local') || $app->configurationIsCached()
    || config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== 'mysql'
    || ! empty(config('database.connections.mysql.url')) || config('mail.default') !== 'smtp'
    || ($smtp['host'] ?? null) !== 'mailpit' || (int) ($smtp['port'] ?? 0) !== 1025
    || ! empty($smtp['url']) || ! empty($smtp['username']) || ! empty($smtp['password'])
    || config('queue.default') !== 'redis' || config('queue.connections.redis.connection') !== 'default'
    || config('database.redis.default.host') !== 'redis' || (int) config('database.redis.default.port') !== 6379
    || ! empty(config('database.redis.default.url'))) {
    fwrite(STDERR, "REFUSED: requires uncached local Compose MySQL/Redis/Mailpit configuration. No fixtures created.\n");
    exit(1);
}
$cleanupOnly = ($argv[1] ?? '') === 'cleanup';
$tag = $cleanupOnly ? ($argv[2] ?? '') : bin2hex(random_bytes(12));
if (! preg_match('/\A[a-f0-9]{24}\z/', $tag)) {
    throw new RuntimeException('Invalid acceptance recovery tag.');
}
$ownerEmail = 'issue4.owner.'.$tag.'@example.test';
$customerEmail = 'issue4.customer.'.$tag.'@example.test';
$eventName = 'Email acceptance '.$tag.' <b>Live & "Loud"</b>';
$mailpit = static fn () => Http::baseUrl('http://mailpit:8025/api/v1')->connectTimeout(3)->timeout(10);
$ownMessages = static fn () => array_values(array_filter(
    $mailpit()->get('search', ['query' => $customerEmail])->throw()->json('messages') ?? [],
    static fn (array $mail): bool => in_array($customerEmail, array_column($mail['To'], 'Address'), true),
));
$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$request = static function (string $uri, array $body, int $expected, ?string $token = null) use ($assert): array {
    $client = Http::baseUrl('http://127.0.0.1:8000')->connectTimeout(3)->timeout(15)->acceptJson();
    if ($token !== null) {
        $client = $client->withToken($token);
    }
    $response = $client->withBody(json_encode((object) $body, JSON_THROW_ON_ERROR), 'application/json')->post($uri);
    // Responses can contain secrets; log only the status and endpoint on failure.
    $assert($response->status() === $expected, 'HTTP '.$uri.' expected '.$expected.', received '.$response->status());

    return $response->json() ?? [];
};
$waitForMail = static function (string $subject) use ($ownMessages, $mailpit): array {
    $deadline = microtime(true) + 30;
    do {
        foreach ($ownMessages() as $mail) {
            if ($mail['Subject'] === $subject) {
                return $mailpit()->get('message/'.$mail['ID'])->throw()->json();
            }
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out waiting for owned message: '.$subject.'. Inspect docker compose logs worker.');
};
$passed = false;
$keepMessages = in_array('--keep-messages', $argv, true);
$exitCode = 0;
echo 'Acceptance tag: '.$tag.PHP_EOL;
echo 'Owned inbox recipient: '.$customerEmail.PHP_EOL;
try {
    if (! $cleanupOnly) {
        $health = Http::timeout(5)->get('http://127.0.0.1:8000/api/health');
        $assert($health->ok(), 'Normal Compose app is not healthy.');
        [$owner, $event] = DB::transaction(function () use ($ownerEmail, $tag, $eventName): array {
            $owner = User::factory()->organiser()->create(['email' => $ownerEmail]);
            $type = EventType::factory()->create(['slug' => 'issue4-email-'.$tag]);
            $event = Event::factory()->published()->for($owner, 'owner')->for($type, 'eventType')->create([
                'name' => $eventName, 'venue' => 'Test Hall & "Garden"', 'starts_at' => '2099-07-14 18:30:00',
                'ends_at' => '2099-07-14 20:00:00', 'timezone' => 'Europe/London', 'capacity' => 1,
            ]);

            return [$owner, $event];
        });
        $registration = ['name' => '<b>Customer & "Friends"</b>', 'email' => $customerEmail,
            'password' => 'local-mail-acceptance-password', 'password_confirmation' => 'local-mail-acceptance-password'];
        $request('/api/register', $registration, 422);
        $request('/api/registration/email-verifications', ['email' => 'malformed'], 422);
        $challenge = $request('/api/registration/email-verifications', ['email' => '  '.strtoupper($customerEmail).'  '], 202);
        $verifyMail = $waitForMail('Verify your email for LeggTix');
        $assert(str_contains($verifyMail['Text'], $challenge['challenge_id']), 'Verification mail challenge reference mismatch.');
        $assert(preg_match('/\b[0-9]{6}\b/', $verifyMail['Text'], $matched) === 1, 'Verification mail has no six-digit code.');
        $code = $matched[0];
        $wrong = $code === '999999' ? '999998' : '999999';
        $path = '/api/registration/email-verifications/'.$challenge['challenge_id'].'/verify';
        $request($path, ['code' => $wrong], 422);
        $proof = $request($path, ['code' => $code], 200)['registration_token'];
        $request($path, ['code' => $code], 422);
        $request('/api/register', [...$registration, 'email' => 'other.'.$tag.'@example.test', 'registration_token' => $proof], 422);
        $signup = $request('/api/register', [...$registration, 'registration_token' => $proof], 201);
        $request('/api/register', [...$registration, 'registration_token' => $proof], 422);
        $login = $request('/api/login', ['email' => $customerEmail, 'password' => $registration['password']], 200);
        $welcome = $waitForMail('Welcome to LeggTix');
        $request('/api/events/'.$event->id.'/reservations', [], 401);
        $request('/api/events/'.$event->id.'/reservations', ['user_id' => $owner->id], 422, $signup['access_token']);
        $booking = $request('/api/events/'.$event->id.'/reservations', [], 201, $signup['access_token']);
        $request('/api/events/'.$event->id.'/reservations', [], 409, $signup['access_token']);
        $ownerToken = $owner->createToken('owned-smoke-full-check')->plainTextToken;
        $request('/api/events/'.$event->id.'/reservations', [], 409, $ownerToken);
        $confirmation = $waitForMail('Your LeggTix reservation is confirmed');
        foreach ([$verifyMail, $welcome, $confirmation] as $mail) {
            $assert(count($mail['To']) === 1 && $mail['To'][0]['Address'] === $customerEmail, 'Captured mail recipient mismatch.');
        }
        foreach ([$welcome, $confirmation] as $mail) {
            $assert(str_contains($mail['HTML'], e($registration['name'])) && str_contains($mail['Text'], $registration['name']), 'HTML/text customer rendering mismatch.');
            $assert(! str_contains($mail['HTML'], '<b>Customer'), 'Customer HTML was not escaped.');
            foreach ([$registration['password'], $signup['access_token'], $login['access_token'], $ownerToken, $code, $proof] as $secret) {
                $assert(! str_contains($mail['HTML'].$mail['Text'], $secret), 'Workflow email exposed a credential, code or token.');
            }
        }
        foreach (['LT-'.$booking['data']['id'], '19:30 BST (+01:00)', '21:00 BST (+01:00)', 'Europe/London'] as $value) {
            $assert(str_contains($confirmation['Text'], $value), 'Confirmation missing reference or event-local time.');
        }
        $assert(str_contains($confirmation['HTML'], e($eventName)) && str_contains($confirmation['Text'], $eventName), 'Event HTML/text rendering mismatch.');
        $assert($event->fresh()->confirmed_count === 1 && $event->reservations()->where('status', 'confirmed')->count() === 1, 'Worker changed ticket inventory.');
        $assert(DB::table('registration_email_verifications')->where('email', $customerEmail)->whereNotNull('consumed_at')->exists(), 'Proof was not consumed.');
        usleep(1500000);
        $assert(count($ownMessages()) === 3, 'Unexpected extra owned workflow message.');
        echo "PASS real HTTP verification, signup, login and booking; wrong code/proof, missing proof, replays, guest, ownership override and full capacity rejected.\n";
        echo "PASS existing Compose Redis worker delivered all three generated multipart messages to Mailpit; exact holder/reference/local time, escaped HTML, literal text, no workflow secrets; inventory unchanged.\n";
        $passed = true;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    try {
        DB::transaction(function () use ($tag, $ownerEmail, $customerEmail, $eventName): void {
            $owner = User::where('email', $ownerEmail)->first();
            if ($owner !== null) {
                foreach (Event::where('owner_id', $owner->id)->where('name', $eventName)->lockForUpdate()->get() as $event) {
                    DB::table('reservations')->where('event_id', $event->id)->delete();
                    DB::table('waitlist_entries')->where('event_id', $event->id)->delete();
                    $event->delete();
                }
            }
            foreach (User::whereIn('email', [$ownerEmail, $customerEmail])->get() as $user) {
                $user->tokens()->delete();
                $user->delete();
            }
            DB::table('registration_email_verifications')->where('email', $customerEmail)->delete();
            EventType::where('slug', 'issue4-email-'.$tag)->delete();
        });
        if ($passed && $keepMessages) {
            echo 'Kept three owned messages for browser review. Cleanup: docker compose exec -T app php tests/Support/transactional-email-smoke.php cleanup '.$tag.PHP_EOL;
        } else {
            // Missing rows suppress jobs that have not started. Allow an in-flight SMTP send to settle.
            usleep(1500000);
            $ids = array_column($ownMessages(), 'ID');
            if ($ids !== []) {
                $mailpit()->delete('messages', ['IDs' => $ids])->throw();
            }
            $assert($ownMessages() === [], 'Owned captured messages remain after cleanup.');
        }
        $assert(User::whereIn('email', [$ownerEmail, $customerEmail])->count() === 0
            && ! DB::table('registration_email_verifications')->where('email', $customerEmail)->exists(), 'Owned fixture cleanup incomplete.');
        echo "PASS cleanup removed only tagged fixture rows/tokens; shared queues and unrelated inbox messages were untouched.\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, 'CLEANUP FAILED: '.$exception->getMessage().PHP_EOL);
        fwrite(STDERR, 'Recovery: docker compose exec -T app php tests/Support/transactional-email-smoke.php cleanup '.$tag.PHP_EOL);
        $exitCode = 1;
    }
}
exit($exitCode);
