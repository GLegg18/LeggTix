<?php

// Real local HTTP/Mailpit acceptance. Never seeds roles or bypasses email verification.
use App\Models\Event;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$smtp = config('mail.mailers.smtp');
if (! $app->environment('local') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== 'mysql' || ! empty(config('database.connections.mysql.url'))
    || config('mail.default') !== 'smtp' || ($smtp['host'] ?? null) !== 'mailpit' || (int) ($smtp['port'] ?? 0) !== 1025
    || ! empty($smtp['url']) || ! empty($smtp['username']) || ! empty($smtp['password'])
    || config('queue.default') !== 'redis' || config('database.redis.default.host') !== 'redis'
    || ! empty(config('database.redis.default.url'))) {
    fwrite(STDERR, "REFUSED: requires the local Docker MySQL, Redis and credential-free Mailpit setup. No requests created.\n");
    exit(1);
}

$tag = 'issue8-api-'.bin2hex(random_bytes(10));
$evidence = [
    'tag' => $tag, 'started_at' => gmdate(DATE_ATOM), 'passed' => false,
    'checks' => [], 'events' => [], 'reservation_ids' => [], 'token_ids' => [],
    'mailpit' => ['login_message_ids' => [], 'confirmation_message_ids' => []],
    'cleanup' => ['events_cancelled' => [], 'tokens_revoked' => [], 'errors' => []],
];
$evidencePath = storage_path('app/private/'.$tag.'.json');
$tokens = [];
$events = [];
$step = 'initialization';
$failed = false;
$saveEvidence = static function () use (&$evidence, $evidencePath): void {
    if (! is_dir(dirname($evidencePath))) mkdir(dirname($evidencePath), 0700, true);
    file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
};
$assert = static function (bool $condition, string $label): void {
    if (! $condition) throw new RuntimeException($label);
};
$assertNoDebug = static function (array $body) use ($assert): void {
    foreach (['exception', 'file', 'line', 'trace'] as $field) {
        $assert(! array_key_exists($field, $body), 'API response exposed debug fields.');
    }
};
$api = static fn () => Http::baseUrl('http://127.0.0.1:8000')->acceptJson()->connectTimeout(3)->timeout(15)
    ->withOptions(['allow_redirects' => false]);
$mailpit = static fn () => Http::baseUrl('http://mailpit:8025/api/v1')->connectTimeout(3)->timeout(10)
    ->withOptions(['allow_redirects' => false]);

$request = static function (string $label, string $method, string $path, int $expected,
    #[\SensitiveParameter] ?string $token = null, array|string|null $body = null, ?string $code = null,
) use ($api, $assert, $assertNoDebug, &$evidence, &$step, $saveEvidence): Response {
    $step = $label;
    $assert(str_starts_with($path, '/api/'), 'Only local API paths are accepted.');
    $client = $api();
    if ($token !== null) $client = $client->withToken($token);
    if ($body !== null) $client = $client->withBody(is_string($body) ? $body : json_encode((object) $body, JSON_THROW_ON_ERROR), 'application/json');
    $response = $client->send($method, $path);
    $decoded = $response->status() === 204 ? [] : $response->json();
    $evidence['checks'][] = ['label' => $label, 'status' => $response->status(), 'expected' => $expected];
    $saveEvidence();
    $assert($response->status() === $expected, $label.' returned an unexpected status.');
    $assert(is_array($decoded), $label.' did not return JSON.');
    if ($expected >= 400) $assertNoDebug($decoded);
    if ($code !== null) $assert(($decoded['code'] ?? null) === $code, $label.' returned an unexpected machine code.');
    echo 'PASS '.$label.' HTTP'.$response->status().PHP_EOL;

    return $response;
};
$login = static function (string $role, string $email) use ($mailpit, $request, $assert, &$tokens, &$evidence, $saveEvidence): array {
    $challenge = $request('login-password-'.$role, 'POST', '/api/login', 202, body: [
        'email' => $email, 'password' => 'demo-password',
    ])->json('challenge_id');
    $assert(is_string($challenge) && preg_match('/^[a-f0-9]{64}$/D', $challenge) === 1, 'Expected an email-factor challenge.');
    $assert(! isset($tokens[$role]), 'Each review role must log in only once.');
    $deadline = microtime(true) + 45;
    $code = null;
    $messageId = null;
    while (microtime(true) < $deadline && $code === null) {
        $search = $mailpit()->get('search', ['query' => 'to:'.$email.' subject:"Your LeggTix login code"']);
        $assert($search->ok(), 'Mailpit search failed.');
        foreach ($search->json('messages') ?? [] as $summary) {
            $message = $mailpit()->get('message/'.$summary['ID']);
            $assert($message->ok(), 'Mailpit message read failed.');
            $text = $message->json('Text') ?? '';
            if (str_contains($text, 'Request reference: '.$challenge) && preg_match('/(?:^|\n)\s*(\d{6})\s*(?:\r?\n|$)/', $text, $match)) {
                $code = $match[1];
                $messageId = $summary['ID'];
                break;
            }
        }
        if ($code === null) usleep(400000);
    }
    $assert($code !== null, 'The local worker did not deliver the requested login email in time.');
    $result = $request('login-email-factor-'.$role, 'POST', '/api/login/email-verifications/'.$challenge.'/verify', 200,
        body: ['code' => $code, 'remember_device' => false])->json();
    $code = null;
    $challenge = null;
    $assert(is_string($result['access_token'] ?? null), 'Successful verification did not return a token.');
    // Retain every issued token before any subsequent assertion so finally can revoke it.
    $tokens[$role] = $result['access_token'];
    $evidence['token_ids'][$role] = (int) strstr($tokens[$role], '|', true);
    $evidence['mailpit']['login_message_ids'][$role] = $messageId;
    $saveEvidence();
    $assert(($result['user']['role'] ?? null) === $role, 'The seeded review account has an unexpected role.');

    return $result['user'];
};
$create = static function (string $label, string $role, int $typeId, int $capacity) use ($request, &$tokens, &$events, &$evidence, $tag, $saveEvidence, $assert): array {
    $name = $tag.' '.$label;
    $event = $request('create-'.$label, 'POST', '/api/manage/events', 201, $tokens[$role], [
        'event_type_id' => $typeId, 'name' => $name, 'description' => 'Dedicated local manual acceptance fixture '.$tag,
        'venue' => 'Review room', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400),
        'ends_at' => null, 'timezone' => 'Europe/London', 'capacity' => $capacity,
    ])->json('data');
    $assert(is_array($event) && is_int($event['id'] ?? null), 'Created event did not return its identity.');
    $events[$event['id']] = ['role' => $role, 'original_name' => $name];
    $evidence['events'][] = ['id' => $event['id'], 'owner_id' => $event['owner_id'], 'name' => $name];
    $saveEvidence();

    return $event;
};
$assertPrivate = static function (Response $response) use ($assert): void {
    $cache = strtolower($response->header('Cache-Control'));
    $assert(str_contains($cache, 'no-store') && str_contains($cache, 'private'), 'A private response must forbid storage.');
};
$assertPrivateAttempts = static function (array $entries) use ($assert): void {
    foreach ($entries as $entry) {
        $fields = array_keys($entry['user'] ?? []);
        sort($fields);
        $assert($fields === ['id', 'name'], 'Private attendee user fields exceed the deliberate contract.');
        foreach (['email', 'password', 'remember_token', 'active_user_id', 'waiting_user_id'] as $field) {
            $assert(! array_key_exists($field, $entry), 'A private attempt exposed an internal field.');
        }
    }
};

try {
    $saveEvidence();
    $request('guest-management-list', 'GET', '/api/manage/events', 401);
    $owner = $login('organiser', 'demo.owner@example.test');
    $customer = $login('regular_user', 'demo.user@example.test');
    $admin = $login('admin', 'demo.admin@example.test');
    $types = $request('active-types', 'GET', '/api/manage/event-types?per_page=1', 200, $tokens['organiser']);
    $assertPrivate($types);
    $type = $types->json('data.0');
    $fields = array_keys($type ?? []);
    sort($fields);
    $assert($fields === ['id', 'name', 'slug'], 'Active type fields exceed the deliberate contract.');
    $typeId = $type['id'];
    $primary = $create('owner-event', 'organiser', $typeId, 2);
    $foreign = $create('admin-event', 'admin', $typeId, 1);
    $eventId = $primary['id'];
    $path = '/api/manage/events/'.$eventId;
    $assert($primary['owner_id'] === $owner['id'] && $foreign['owner_id'] === $admin['id'], 'Creation must assign the actor as owner.');
    $hidden = $request('draft-public-hidden', 'GET', '/api/events/'.$eventId, 404)->json();
    $assert($hidden === ['message' => 'Resource not found.'], 'Hidden public events need a generic not-found body.');
    $detail = $request('owner-private-detail', 'GET', $path, 200, $tokens['organiser']);
    $assertPrivate($detail);
    $assert($detail->json('data.status') === 'draft' && $detail->json('data.confirmed_count') === 0, 'Draft inventory must begin empty.');
    $request('admin-inspects-owner-event', 'GET', $path, 200, $tokens['admin']);
    foreach (['', '/reservations', '/waitlist'] as $suffix) {
        $request('foreign-owner-private-denied-'.($suffix === '' ? 'detail' : trim($suffix, '/')), 'GET', '/api/manage/events/'.$foreign['id'].$suffix, 403, $tokens['organiser']);
    }
    $request('foreign-owner-edit-denied', 'PATCH', '/api/manage/events/'.$foreign['id'], 403, $tokens['organiser'], ['name' => 'Forbidden edit']);
    $request('foreign-owner-cancel-denied', 'DELETE', '/api/manage/events/'.$foreign['id'], 403, $tokens['organiser'], []);
    $request('regular-create-denied', 'POST', '/api/manage/events', 403, $tokens['regular_user'], []);
    $request('regular-private-denied', 'GET', $path.'/reservations', 403, $tokens['regular_user']);
    $request('regular-edit-denied', 'PATCH', $path, 403, $tokens['regular_user'], ['name' => 'Forbidden edit']);
    $request('guest-detail-denied', 'GET', $path, 401);
    $request('owner-override-rejected', 'PATCH', $path, 422, $tokens['organiser'], ['owner_id' => $admin['id']]);
    $request('native-integer-required', 'PATCH', $path, 422, $tokens['organiser'], ['capacity' => '2']);
    $request('malformed-json-rejected', 'PATCH', $path, 400, $tokens['organiser'], '{"name":');
    $request('unsupported-query-rejected', 'GET', $path.'/reservations?user_id=1', 422, $tokens['organiser']);
    $request('publish', 'PATCH', $path, 200, $tokens['organiser'], ['status' => 'published']);
    $request('published-public-detail', 'GET', '/api/events/'.$eventId, 200);
    $request('unpublish-rejected', 'PATCH', $path, 422, $tokens['organiser'], ['status' => 'draft']);
    foreach (['regular_user', 'admin'] as $role) {
        $booking = $request('book-'.$role, 'POST', '/api/events/'.$eventId.'/reservations', 201, $tokens[$role], []);
        $evidence['reservation_ids'][] = $booking->json('data.id');
    }
    $request('duplicate-booking-rejected', 'POST', '/api/events/'.$eventId.'/reservations', 409, $tokens['regular_user'], [], 'already_reserved');
    $request('capacity-below-occupancy-rejected', 'PATCH', $path, 422, $tokens['organiser'], ['capacity' => 1]);
    foreach (['starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 31 * 86400), 'timezone' => 'America/New_York'] as $field => $value) {
        $request('history-freezes-'.$field, 'PATCH', $path, 422, $tokens['organiser'], [$field => $value]);
    }
    $updated = $request('metadata-after-booking', 'PATCH', $path, 200, $tokens['organiser'], ['venue' => 'Review room B']);
    $assert($updated->json('data.starts_at') === $primary['starts_at'] && $updated->json('data.owner_id') === $owner['id']
        && $updated->json('data.name') === $primary['name'] && $updated->json('data.confirmed_count') === 2, 'Rejected edits must preserve schedule/owner/name and committed occupancy.');
    $first = $request('attendees-first-page', 'GET', $path.'/reservations?per_page=1', 200, $tokens['organiser']);
    $assertPrivate($first);
    $assertPrivateAttempts($first->json('data'));
    $next = $first->json('links.next');
    $assert(is_string($next), 'Two reservations must produce a second one-record page.');
    $url = parse_url($next);
    $assert(($url['host'] ?? null) === '127.0.0.1' && ($url['port'] ?? null) === 8000
        && ($url['path'] ?? null) === $path.'/reservations', 'Private pagination must stay on the expected local resource.');
    $second = $request('attendees-second-page', 'GET', $url['path'].'?'.$url['query'], 200, $tokens['organiser']);
    $assertPrivateAttempts($second->json('data'));
    $assert($first->json('data.0.id') < $second->json('data.0.id') && $second->json('meta.next_cursor') === null, 'Attendee pages must be ordered and distinct.');
    $waitlist = $request('empty-waitlist', 'GET', $path.'/waitlist?per_page=1', 200, $tokens['organiser']);
    $assertPrivate($waitlist);
    $assert($waitlist->json('data') === [], 'The review must not synthesize waiters.');
    $confirmed = DB::table('reservations')->where('event_id', $eventId)->where('status', 'confirmed')->count();
    $stored = Event::findOrFail($eventId);
    $assert($stored->confirmed_count === $confirmed && $confirmed === 2 && $confirmed <= $stored->capacity, 'Committed inventory must agree before cancellation.');
    $step = 'confirmation-delivery-before-cancellation';
    $deadline = microtime(true) + 45;
    $confirmations = [];
    while (microtime(true) < $deadline && count($confirmations) < 2) {
        $search = $mailpit()->get('search', ['query' => $tag]);
        $assert($search->ok(), 'Mailpit confirmation search failed.');
        foreach ($search->json('messages') ?? [] as $summary) {
            if ($summary['Subject'] !== 'Your LeggTix reservation is confirmed') continue;
            $message = $mailpit()->get('message/'.$summary['ID']);
            $assert($message->ok(), 'Confirmation message could not be read.');
            $text = $message->json('Text') ?? '';
            if (str_contains($text, $primary['name']) && preg_match('/Reference: LT-(\d+)/', $text, $match)
                && in_array((int) $match[1], $evidence['reservation_ids'], true)) {
                $confirmations[(int) $match[1]] = $summary['ID'];
            }
        }
        if (count($confirmations) < 2) usleep(400000);
    }
    $assert(count($confirmations) === 2, 'Both exact booking confirmations must arrive before cancellation.');
    $evidence['mailpit']['confirmation_message_ids'] = $confirmations;
    $evidence['checks'][] = ['label' => $step, 'passed' => true, 'count' => 2];
    $saveEvidence();
    echo "PASS two real booking confirmations captured before cancellation.\n";
    $request('cancel-event', 'DELETE', $path, 200, $tokens['organiser'], []);
    $cancelled = $request('cancelled-history', 'GET', $path.'/reservations', 200, $tokens['organiser'])->json('data');
    $timestamps = array_column($cancelled, 'cancelled_at', 'id');
    $assert(count($cancelled) === 2 && array_column($cancelled, 'status') === ['cancelled', 'cancelled']
        && ! in_array(null, $timestamps, true), 'Cancellation must retain both terminal attempts.');
    $request('cancel-replay', 'DELETE', $path, 200, $tokens['organiser'], []);
    $replayed = $request('replay-history', 'GET', $path.'/reservations', 200, $tokens['organiser'])->json('data');
    $assert(array_column($replayed, 'cancelled_at', 'id') === $timestamps, 'Replay must retain the exact first cancellation timestamps.');
    $assert($request('cancelled-public-hidden', 'GET', '/api/events/'.$eventId, 404)->json() === $hidden, 'Draft and cancelled public errors must match.');
    $request('reopen-rejected', 'PATCH', $path, 422, $tokens['organiser'], ['status' => 'published']);
    $request('cancelled-booking-rejected', 'POST', '/api/events/'.$eventId.'/reservations', 409, $tokens['regular_user'], [], 'event_not_bookable');
    $step = 'cancelled-promotion-recovery-command';
    $assert(Artisan::call('waitlist:promote', ['event' => (string) $eventId]) === 0, 'The recovery command must succeed.');
    $assert(str_contains(Artisan::output(), 'Promoted 0 waiters.'), 'Cancelled-event recovery must allocate no places.');
    $stored->refresh();
    $assert($stored->confirmed_count === 0 && $stored->status->value === 'cancelled'
        && DB::table('reservations')->where('event_id', $eventId)->where('status', 'confirmed')->count() === 0
        && DB::table('waitlist_entries')->where('event_id', $eventId)->count() === 0, 'Cancelled inventory/history invariant failed.');
    $evidence['checks'][] = ['label' => $step, 'passed' => true, 'promoted' => 0];
    $evidence['passed'] = true;
} catch (Throwable $exception) {
    $failed = true;
    $evidence['failed_check'] = $step;
    $evidence['exception_type'] = $exception::class;
    // Never print HTTP response bodies, credentials, OTPs or stack traces.
    fwrite(STDERR, 'FAIL '.$step.' ('.$exception::class.'). Response bodies and credentials omitted.'.PHP_EOL);
} finally {
    foreach ($events as $id => $identity) {
        try {
            $record = Event::findOrFail($id);
            $assert($record->name === $identity['original_name'] && str_starts_with($record->name, $tag.' '), 'Cleanup refuses an event outside this exact review run.');
            $cleanup = $request('cleanup-event-'.$id, 'DELETE', '/api/manage/events/'.$id, 200, $tokens[$identity['role']], []);
            $assert($cleanup->json('data.status') === 'cancelled' && $cleanup->json('data.confirmed_count') === 0, 'Review event cleanup did not close inventory.');
            $evidence['cleanup']['events_cancelled'][] = $id;
        } catch (Throwable $exception) {
            $failed = true;
            $evidence['cleanup']['errors'][] = ['event_id' => $id, 'exception_type' => $exception::class];
        }
    }
    foreach ($tokens as $role => $token) {
        try {
            $request('logout-'.$role, 'POST', '/api/logout', 204, $token, []);
            $request('revoked-token-'.$role, 'GET', '/api/user', 401, $token);
            $evidence['cleanup']['tokens_revoked'][] = $evidence['token_ids'][$role];
        } catch (Throwable $exception) {
            $failed = true;
            $evidence['cleanup']['errors'][] = ['role' => $role, 'exception_type' => $exception::class];
        }
    }
    $tokens = [];
    $evidence['passed'] = $evidence['passed'] && ! $failed;
    $evidence['finished_at'] = gmdate(DATE_ATOM);
    $saveEvidence();
    echo 'Token-free evidence: '.$evidencePath.PHP_EOL;
    if ($evidence['cleanup']['errors'] !== []) {
        fwrite(STDERR, "Cleanup needs attention; exact review event/token identities are in the evidence file. Do not reset shared data.\n");
    }
}

exit($failed ? 1 : 0);
