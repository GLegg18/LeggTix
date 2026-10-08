<?php

namespace App\Http\Middleware;

use Closure;
use Dedoc\Scramble\Scramble;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalApiDocumentation
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check every request, including when routes were cached in a local environment.
        abort_unless(app()->environment(['local', 'testing']) && class_exists(Scramble::class), 404);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

        return $response;
    }
}
