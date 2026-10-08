<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('registration', fn (Request $request): Limit =>
            Limit::perMinute(10)->by('registration:'.$request->ip())
        );

        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $identity = is_string($email) ? Str::lower(trim($email)) : '';
            $validIdentity = Validator::make(['email' => $identity], [
                'email' => ['bail', 'required', 'string', 'max:255', 'email'],
            ])->passes();
            $userId = $validIdentity ? User::query()->where('email', $identity)->value('id') : null;

            // MySQL may consider distinct spellings equal; throttle the account it resolves.
            $identityKey = $userId === null ? 'email:'.hash('sha256', $identity) : 'user:'.$userId;

            return [
                Limit::perMinute(30)->by('login:ip:'.$request->ip()),
                Limit::perMinute(5)->by('login:identity:'.hash('sha256', $identityKey.'|'.$request->ip())),
            ];
        });

        RateLimiter::for('reservations', fn (Request $request): array => [
            Limit::perMinute(30)->by('reservations:user:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(120)->by('reservations:ip:'.$request->ip()),
        ]);
    }
}
