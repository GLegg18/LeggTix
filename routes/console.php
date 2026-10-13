<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

Artisan::command('about:project', function () {
    $this->comment('LeggTix API — Laravel event reservation and waitlist service.');
})->purpose('Show a short description of the LeggTix project');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Artisan::command('registration-email:prune', function (): void {
    $deleted = DB::table('registration_email_verifications')->where(function ($query): void {
        $query->whereNotNull('consumed_at')
            ->orWhere('token_expires_at', '<=', DB::raw('UTC_TIMESTAMP(6)'))
            ->orWhere(function ($expiredCode): void {
                $expiredCode->whereNull('token_hash')->where('expires_at', '<=', DB::raw('UTC_TIMESTAMP(6)'));
            });
    })->delete();

    $this->info('Pruned '.$deleted.' registration email verifications.');
})->purpose('Remove consumed or fully expired registration email verification records');

Schedule::command('registration-email:prune')->hourly();
