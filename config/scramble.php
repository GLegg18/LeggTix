<?php

use App\Http\Middleware\EnsureLocalApiDocumentation;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => 'api',
    'info' => [
        'version' => '1.0.0',
        'description' => 'The implemented LeggTix API. Try it out sends real requests to this installation.',
    ],
    'servers' => ['This installation' => '/api'],
    'middleware' => [EnsureLocalApiDocumentation::class],
    'dev_tools' => ['enabled' => false],
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
