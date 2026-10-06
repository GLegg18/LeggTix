<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:project', function () {
    $this->comment('LeggTix API — Laravel event reservation and waitlist service.');
})->purpose('Show a short description of the LeggTix project');
