<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

class EnsureSpaOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('origin');
        if ($origin === null && ($referer = $request->headers->get('referer')) !== null) {
            $parts = parse_url($referer);
            $origin = isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
        }

        abort_unless(
            is_string($origin) && in_array($origin, config('cors.allowed_origins'), true)
                && EnsureFrontendRequestsAreStateful::fromFrontend($request),
            403, 'This origin is not configured for browser authentication.',
        );

        return $next($request);
    }
}
