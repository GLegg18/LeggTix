<?php

return [
    // This milestone authenticates API clients exclusively with bearer tokens.
    'stateful' => [],
    'guard' => [],
    'routes' => false,
    'expiration' => 1440,
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
];
