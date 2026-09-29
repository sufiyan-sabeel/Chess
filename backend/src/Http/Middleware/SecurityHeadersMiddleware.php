<?php

declare(strict_types=1);

namespace Checkmate\Http\Middleware;

use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * Security headers on every response (docs/IMPLEMENTATION_CHECKLIST.md line 61).
 *
 * The API returns JSON only, so a restrictive CSP of `default-src 'none'` is
 * correct: it neutralises any reflected content and blocks framing of error
 * pages. `no-store` keeps tokens/PII out of every cache (the spec requires it
 * at minimum for auth responses; for a pure JSON API it is safe everywhere).
 */
final class SecurityHeadersMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        self::apply($response);
        return $response;
    }

    public static function apply(Response $response): void
    {
        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
