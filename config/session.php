<?php

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => env('SESSION_ENCRYPT', false),
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', 'leggtix_session'),
    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE', true),
    'http_only' => true,
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => false,
    // Serialize the full read/action/save cycle, including CSRF and stateful API
    // requests, so a delayed request cannot restore a session after logout.
    'block' => true,
    'block_store' => env('SESSION_BLOCK_STORE'),
    'block_lock_seconds' => 120,
    'block_wait_seconds' => 10,
];
