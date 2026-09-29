<?php

declare(strict_types=1);

namespace Checkmate\Middleware;

use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * CORS allowlist (docs/API.md headers section).
 *
 * - No Origin header (native Android client) => pass through untouched.
 * - Origin in the configured allowlist => echo it, allow credentials.
 *   `*` is never emitted (credentialed requests are used).
 * - Origin NOT in the allowlist => 403 FORBIDDEN with no CORS headers
 *   (preflights get the same rejection).
 * - Preflight (OPTIONS) short-circuits with 204.
 */
final class CorsMiddleware implements Middleware
{
    public function handle(Request $request, array $meta, callable $next): Response
    {
        $origin = $request->header('origin');
        if ($origin === null || $origin === '') {
            return $next($request);
        }

        /** @var string[] $allowed */
        $allowed = config('cors.allowed_origins', []);
        if (!in_array(rtrim($origin, '/'), $allowed, true)) {
            return JsonResponse::error('FORBIDDEN', 'Origin not allowed.', 403);
        }

        // Remembered so App::finalize can add headers even to exception responses.
        $request->attributes['cors_origin'] = $origin;

        if ($request->method() === 'OPTIONS') {
            $request->attributes['cors_preflight'] = true;
            return new Response(204, '');
        }

        $response = $next($request);
        return $this->decorate($response, $origin, isPreflight: false);
    }

    public static function decorate(Response $response, string $origin, bool $isPreflight): Response
    {
        $response->withHeader('Access-Control-Allow-Origin', $origin);
        $response->withHeader('Access-Control-Allow-Credentials', 'true');
        $response->withHeader('Vary', 'Origin');

        if ($isPreflight) {
            $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
            $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Request-Id');
            $response->withHeader('Access-Control-Max-Age', '600');
        }

        return $response;
    }
}
