<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

class ApiDocumentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The dev-only package must never add alternate documentation routes.
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }
    }
}
