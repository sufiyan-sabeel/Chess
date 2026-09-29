<?php

declare(strict_types=1);

namespace Checkmate\Http\Middleware;

use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * CORS with an explicit allowlist (never `*`, docs/API.md + checklist line 61).
 *
 * Always allowed (development reality of this app):
 *   - http://localhost:<any port> and http://127.0.0.1:<any port>  (dev servers)
 *   - https://appassets.androidplatform.net                       (Android WebView)
 *   - whatever CORS_ALLOWED_ORIGINS adds (e.g. http://10.0.2.2:8080 emulator host)
 *
 * An unknown Origin gets NO Access-Control-* headers: the browser then blocks
 * the response. Requests without an Origin (curl, native clients) are not
 * affected — CORS is a browser-only contract.
 *
 * Authentication uses `Authorization: Bearer`, never cookies, so
 * Access-Control-Allow-Credentials is deliberately omitted.
 */
final class CorsMiddleware
{
    private const DEV_ORIGIN = '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#';
    private const WEBVIEW_ORIGIN = 'https://appassets.androidplatform.net';
    private const ALLOWED_METHODS = 'GET, POST, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Content-Type, Authorization, X-Request-Id';
    private const EXPOSED_HEADERS = 'X-Request-Id, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset';
    private const MAX_AGE = '600';

    public function handle(Request $request, callable $next): Response
    {
        if ($request->method() === 'OPTIONS') {
            return $this->preflight($request);
        }
        $response = $next($request);
        return $this->decorate($request, $response);
    }

    private function preflight(Request $request): Response
    {
        // Always answer preflights (never 404): probing route existence via
        // OPTIONS must not leak the route table.
        $response = new Response(204, '');
        return $this->decorate($request, $response);
    }

    private function decorate(Request $request, Response $response): Response
    {
        $origin = $request->header('origin');
        if ($origin === null || $origin === '') {
            return $response;
        }

        // Any response that varied on Origin must be cache-safe.
        $response->withHeader('Vary', 'Origin');

        if (!$this->isAllowed($origin)) {
            return $response;
        }

        $normalized = rtrim($origin, '/');
        $response
            ->withHeader('Access-Control-Allow-Origin', $normalized)
            ->withHeader('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);

        if ($request->method() === 'OPTIONS') {
            $response
                ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
                ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
                ->withHeader('Access-Control-Max-Age', self::MAX_AGE);
        }
        return $response;
    }

    public function isAllowed(string $origin): bool
    {
        $origin = rtrim($origin, '/');

        /** @var list<string> $configured */
        $configured = (array) config('cors.allowed_origins', []);
        if (in_array($origin, $configured, true)) {
            return true;
        }
        if (preg_match(self::DEV_ORIGIN, $origin) === 1) {
            return true;
        }
        return $origin === self::WEBVIEW_ORIGIN;
    }
}
