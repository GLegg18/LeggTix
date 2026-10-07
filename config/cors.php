<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    // Add explicit trusted browser origins when the frontend is introduced.
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
