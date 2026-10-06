<?php

use Illuminate\Support\Str;

return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
        'redis' => ['driver' => 'redis', 'connection' => env('REDIS_CACHE_CONNECTION', 'cache')],
    ],
    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'leggtix')).'-cache-'),
];
