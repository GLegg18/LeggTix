<?php

namespace App\Providers;

use App\Models\User;
use App\Models\RegistrationEmailVerification;
use App\Models\LoginEmailVerification;
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
        RateLimiter::for('registration', fn (Request $request): array => [
            Limit::perMinute(10)->by('registration:ip:'.$request->ip()),
            Limit::perMinute(5)->by('registration:email:'.$this->emailRateKey($request)),
            Limit::perHour(20)->by('registration:email-hour:'.$this->emailRateKey($request)),
        ]);

        RateLimiter::for('registration-email-request', fn (Request $request): array => [
            Limit::perMinute(10)->by('registration-email:request:ip:'.$request->ip()),
            Limit::perHour(30)->by('registration-email:request:ip-hour:'.$request->ip()),
            Limit::perMinute(1)->by('registration-email:request:email:'.$this->emailRateKey($request)),
            Limit::perHour(5)->by('registration-email:request:email-hour:'.$this->emailRateKey($request)),
        ]);

        RateLimiter::for('registration-email-verify', function (Request $request): array {
            $challenge = (string) $request->route('challenge');
            $email = RegistrationEmailVerification::query()->where('challenge_id', $challenge)->value('email');
            $target = hash('sha256', $email ?? 'unknown:'.$challenge);

            return [
                Limit::perMinute(20)->by('registration-email:verify:ip:'.$request->ip()),
                Limit::perHour(100)->by('registration-email:verify:ip-hour:'.$request->ip()),
                Limit::perMinute(5)->by('registration-email:verify:challenge:'.$challenge),
                Limit::perHour(20)->by('registration-email:verify:email-hour:'.$target),
            ];
        });

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
                Limit::perMinute(5)->by('login:account:'.hash('sha256', $identityKey)),
                Limit::perHour(20)->by('login:account-hour:'.hash('sha256', $identityKey)),
            ];
        });

        RateLimiter::for('login-email-verify', function (Request $request): array {
            $challenge = (string) $request->route('challenge');
            $userId = LoginEmailVerification::query()->where('challenge_id', $challenge)->value('user_id');
            $target = $userId === null ? 'unknown:'.hash('sha256', $challenge) : 'user:'.$userId;

            return [
                Limit::perMinute(20)->by('login-email:verify:ip:'.$request->ip()),
                Limit::perHour(100)->by('login-email:verify:ip-hour:'.$request->ip()),
                Limit::perMinute(5)->by('login-email:verify:challenge:'.$challenge),
                Limit::perHour(20)->by('login-email:verify:account-hour:'.$target),
            ];
        });

        RateLimiter::for('reservations', fn (Request $request): array => [
            Limit::perMinute(30)->by('reservations:user:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(120)->by('reservations:ip:'.$request->ip()),
        ]);

        RateLimiter::for('event-management', fn (Request $request): array => [
            Limit::perMinute(60)->by('event-management:user:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(120)->by('event-management:ip:'.$request->ip()),
        ]);
    }

    private function emailRateKey(Request $request): string
    {
        $email = $request->input('email');

        return hash('sha256', is_string($email) ? Str::lower(trim($email)) : 'invalid');
    }
}
