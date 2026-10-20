<?php

return [
    // Configure explicit host:port pairs; bearer clients remain stateless.
    'stateful' => array_values(array_filter(array_map('trim', explode(',', env('SANCTUM_STATEFUL_DOMAINS', ''))))),
    'guard' => ['web'],
    'routes' => true,
    'expiration' => 1440,
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
];
