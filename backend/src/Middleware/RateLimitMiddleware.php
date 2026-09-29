<?php

declare(strict_types=1);

namespace Checkmate\Middleware;

use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Services\RateLimiter;

/**
 * Rate limits per IP + route (docs/API.md "Rate limits"):
 *   login 10/min, register 5/min, verify 10/min, resend 3/min, forgot 3/min,
 *   reset 5/min, everything else 120/min.
 * Exceeding => 429 RATE_LIMITED + Retry-After (+ X-RateLimit-* headers).
 *
 * Route meta: 'rate' => 'login'|'register'|...|false (disabled).
 * Default (no meta): counted per method+path at the default limit.
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, array $meta, callable $next): Response
    {
        $rateKey = $meta['rate'] ?? 'default';
        if ($rateKey === false) {
            return $next($request);
        }

        $named = is_string($rateKey) && $rateKey !== 'default';
        $limitKey = $named ? $rateKey : 'default';
        $limit = (int) config('rate_limits.' . $limitKey, 120);
        $window = (int) config('rate_limits.window', 60);

        $routeLabel = $named
            ? $rateKey
            : $request->method() . ' ' . $request->path();
        $bucket = $routeLabel . '|' . substr(hash('sha256', $request->ip()), 0, 24);

        $state = $this->limiter->hit($bucket, $limit, $window);

        if (!$state['allowed']) {
            return JsonResponse::error(
                'RATE_LIMITED',
                'Too many requests. Please try again later.',
                429,
                [
                    'Retry-After' => (string) $state['retry_after'],
                    'X-RateLimit-Limit' => (string) $state['limit'],
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) $state['reset'],
                ]
            );
        }

        $response = $next($request);
        $response->withHeader('X-RateLimit-Limit', (string) $state['limit']);
        $response->withHeader('X-RateLimit-Remaining', (string) $state['remaining']);
        $response->withHeader('X-RateLimit-Reset', (string) $state['reset']);
        return $response;
    }
}
