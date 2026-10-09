<?php

// Local acceptance fixtures only; invoked by verify-reservations.ps1.
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== 'mysql') {
    throw new RuntimeException('Live acceptance fixtures require the local MySQL environment.');
}

$mode = $argv[1] ?? '';

if ($mode === 'create') {
    if (config('cache.default') !== 'redis') {
        throw new RuntimeException('Live throttle verification requires CACHE_STORE=redis.');
    }

    $result = DB::transaction(function (): array {
        $tag = bin2hex(random_bytes(12));
        $owner = User::factory()->organiser()->create(['email' => 'issue9.owner.'.$tag.'@example.test']);
        $actor = User::factory()->create(['email' => 'issue9.actor.'.$tag.'@example.test']);
        $other = User::factory()->create(['email' => 'issue9.other.'.$tag.'@example.test']);
        $throttled = User::factory()->create(['email' => 'issue9.throttle.'.$tag.'@example.test']);
        $type = EventType::factory()->create(['slug' => 'issue9-'.$tag]);
        $base = Event::factory()->for($owner, 'owner')->for($type, 'eventType');
        $events = [
            'available' => $base->published()->create(['name' => 'Issue9 available '.$tag, 'capacity' => 1]),
            'bodyless' => $base->published()->create(['name' => 'Issue9 bodyless '.$tag, 'capacity' => 1]),
            'draft' => $base->draft()->create(['name' => 'Issue9 draft '.$tag, 'capacity' => 1]),
            'past' => $base->past()->create(['name' => 'Issue9 past '.$tag, 'capacity' => 1]),
            'waiting' => $base->published()->create(['name' => 'Issue9 waiting '.$tag, 'capacity' => 1]),
        ];
        DB::table('waitlist_entries')->insert([
            'event_id' => $events['waiting']->id, 'user_id' => $other->id, 'status' => 'waiting',
            'joined_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return [
            'tag' => $tag,
            'events' => array_map(fn (Event $event): int => $event->id, $events),
            'users' => ['owner' => $owner->id, 'actor' => $actor->id, 'other' => $other->id, 'throttled' => $throttled->id],
            'type_id' => $type->id,
            'token' => $actor->createToken('issue9-live', ['*'], now()->addHour())->plainTextToken,
            'other_token' => $other->createToken('issue9-live', ['*'], now()->addHour())->plainTextToken,
            'throttle_token' => $throttled->createToken('issue9-live', ['*'], now()->addHour())->plainTextToken,
            'expired_token' => $actor->createToken('issue9-expired', ['*'], now()->subMinute())->plainTextToken,
            'versions' => ['php' => PHP_VERSION, 'mysql' => DB::scalar('SELECT VERSION()'), 'cache' => config('cache.default')],
        ];
    });
} elseif (in_array($mode, ['inspect', 'cleanup'], true)) {
    $fixture = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $tag = $fixture['tag'];
    $eventIds = array_values($fixture['events']);
    $userIds = array_values($fixture['users']);

    $result = DB::transaction(function () use ($mode, $fixture, $tag, $eventIds, $userIds): array {
        $events = Event::whereIn('id', $eventIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($events as $event) {
            if (! str_ends_with($event->name, ' '.$tag) || $event->owner_id !== $fixture['users']['owner']
                || $event->event_type_id !== $fixture['type_id']) {
                throw new RuntimeException('Fixture identity mismatch; no cleanup performed.');
            }
        }

        foreach (User::whereIn('id', $userIds)->get() as $user) {
            if (! str_ends_with($user->email, '.'.$tag.'@example.test')) {
                throw new RuntimeException('Fixture user identity mismatch; no cleanup performed.');
            }
        }

        if (EventType::whereKey($fixture['type_id'])->exists()
            && EventType::findOrFail($fixture['type_id'])->slug !== 'issue9-'.$tag) {
            throw new RuntimeException('Fixture type identity mismatch; no cleanup performed.');
        }

        if ($mode === 'inspect') {
            return $events->map(fn (Event $event): array => [
                'id' => $event->id, 'capacity' => $event->capacity, 'confirmed_count' => $event->confirmed_count,
                'confirmed_rows' => $event->reservations()->where('status', 'confirmed')->count(),
                'holder_ids' => $event->reservations()->pluck('user_id')->all(),
                'waiting_count' => DB::table('waitlist_entries')->where('event_id', $event->id)->where('status', 'waiting')->count(),
            ])->all();
        }

        DB::table('reservations')->whereIn('event_id', $eventIds)->delete();
        DB::table('waitlist_entries')->whereIn('event_id', $eventIds)->delete();
        Event::whereIn('id', $eventIds)->delete();

        foreach (User::whereIn('id', $userIds)->get() as $user) {
            $user->tokens()->delete();
        }

        User::whereIn('id', $userIds)->delete();
        EventType::whereKey($fixture['type_id'])->delete();

        return [
            'events' => Event::whereIn('id', $eventIds)->count(),
            'users' => User::whereIn('id', $userIds)->count(),
            'type' => EventType::whereKey($fixture['type_id'])->count(),
            'reservations' => DB::table('reservations')->whereIn('event_id', $eventIds)->count(),
            'waitlist_entries' => DB::table('waitlist_entries')->whereIn('event_id', $eventIds)->count(),
            'tokens' => DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->count(),
        ];
    });
} else {
    throw new RuntimeException('Expected create, inspect or cleanup mode.');
}

echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
