<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about:project', function () {
    $this->comment('LeggTix API — Laravel event reservation and waitlist service.');
})->purpose('Show a short description of the LeggTix project');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
