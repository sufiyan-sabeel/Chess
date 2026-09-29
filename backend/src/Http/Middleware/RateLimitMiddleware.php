<?php

declare(strict_types=1);

namespace Checkmate\Http\Middleware;

use Checkmate\Database\Connection;
use Checkmate\Http\ApiException;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Services\RateLimiter;
use Checkmate\Support\Log;

/**
 * Per-IP + per-route fixed-window rate limiting (docs/API.md "Rate limits"):
 *
 *   login 10/min · register 5/min · verify 10/min · resend 3/min ·
 *   forgot 3/min · reset 5/min · everything else 120/min
 *
 * Backed by the `rate_limits` table (crash-safe across restarts, bounded by
 * opportunistic purges). A broken limiter must not take the API down: on any
 * storage error this middleware fails OPEN and logs the incident.
 *
 * Success responses get X-RateLimit-Limit/Remaining/Reset; exceeding one gets
 * 429 + RATE_LIMITED + Retry-After (seconds until the window resets).
 */
final class RateLimitMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        /** @var array{meta:array<string,mixed>}|null $route */
        $route = $request->attributes['route'] ?? null;
        $key = is_array($route) && isset($route['meta']['rate'])
            ? (string) $route['meta']['rate']
            : 'default';

        $limit = (int) config('rate_limits.' . $key, (int) config('rate_limits.default', 120));
        $window = (int) config('rate_limits.window', 60);
        $bucket = $request->ip() . '|' . $key;

        $result = $this->hit($bucket, $limit, $window);

        // Published via request attributes so the error funnel (which sits
        // OUTSIDE this middleware) can attach them to 4xx/5xx responses too.
        $headers = [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) (int) $result['remaining'],
            'X-RateLimit-Reset' => (string) (int) $result['reset'],
        ];
        $request->attributes['rate_headers'] = $headers;

        if (!$result['allowed']) {
            throw new ApiException(
                'RATE_LIMITED',
                'Too many requests. Please try again later.',
                429,
                [],
                $headers + ['Retry-After' => (string) max(1, (int) $result['retry_after'])],
            );
        }

        $response = $next($request);
        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }
        return $response;
    }

    /**
     * Instantiate Services\RateLimiter.
     *
     * The class is shared with a concurrently-developed stack whose author has
     * flip-flopped between `__construct()` and `__construct(PDO)`; reflect so
     * both shapes keep working (a mismatch must never disable rate limiting).
     */
    private static function makeLimiter(): RateLimiter
    {
        $reflection = new \ReflectionClass(RateLimiter::class);
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return $reflection->newInstance(Connection::pdo());
        }
        return $reflection->newInstance();
    }

    /** @return array{allowed:bool, limit:int, remaining:int, retry_after:int, reset:int} */
    private function hit(string $bucket, int $limit, int $window): array
    {
        $fallback = [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => $limit,
            'retry_after' => 0,
            'reset' => time() + $window,
        ];

        try {
            $limiter = self::makeLimiter();
            $result = $limiter->hit($bucket, $limit, $window);

            // Bounded storage: occasionally prune rows from old windows.
            if (random_int(1, 25) === 1) {
                $limiter->purge();
            }
            return $result;
        } catch (\Throwable $e) {
            Log::exception($e, ['component' => 'rate_limiter', 'route_key' => $bucket]);
            return $fallback; // fail open
        }
    }
}
