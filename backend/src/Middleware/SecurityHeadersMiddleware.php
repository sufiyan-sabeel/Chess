<?php

declare(strict_types=1);

namespace Checkmate\Middleware;

use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * Baseline browser security headers on every response.
 */
final class SecurityHeadersMiddleware implements Middleware
{
    public function handle(Request $request, array $meta, callable $next): Response
    {
        $response = $next($request);

        $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response->withHeader('X-Frame-Options', 'DENY');
        $response->withHeader('Referrer-Policy', 'no-referrer');
        $response->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->withHeader('Cross-Origin-Resource-Policy', 'same-site');
        $response->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->withHeader('Cache-Control', 'no-store');
        $response->withHeader('X-Api-Version', (string) config('app.version', '1.0.0'));

        return $response;
    }
}
