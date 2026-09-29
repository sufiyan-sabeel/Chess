<?php

declare(strict_types=1);

namespace Checkmate\Http\Middleware;

use Checkmate\Http\ApiException;
use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Support\Log;

/**
 * Single funnel for every failure (docs/API.md envelope):
 *
 *   ApiException      -> documented error code + HTTP status
 *   any other Throwable -> 500 INTERNAL_ERROR with a GENERIC message.
 *
 * Stack traces / exception messages are only ever attached when APP_DEBUG=true
 * *and* APP_ENV is not production — production responses never leak internals.
 *
 * Sits INSIDE the security-header and CORS middleware so error responses also
 * carry those headers.
 */
final class ErrorHandlingMiddleware
{
    public function __construct(private readonly bool $debug)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ApiException $e) {
            return self::withRateHeaders($request, JsonResponse::fromException($e));
        } catch (\Throwable $e) {
            Log::exception($e, [
                'request_id' => $request->requestId(),
                'method' => $request->method(),
                'path' => $request->path(),
            ]);

            $extra = [];
            if ($this->debug) {
                $extra['debug'] = [
                    'type' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ];
            }
            return self::withRateHeaders($request, JsonResponse::error(
                'INTERNAL_ERROR',
                'An unexpected error occurred.',
                500,
                [],
                $extra,
            ));
        }
    }

    /**
     * Rate limiting runs INSIDE this middleware, so any response it would have
     * decorated never reaches it once an exception unwinds the pipeline. It
     * publishes the X-RateLimit-* values on the request instead; mirror them
     * here so 4xx/5xx carry the same headers as successful responses.
     */
    private static function withRateHeaders(Request $request, Response $response): Response
    {
        $headers = $request->attributes['rate_headers'] ?? null;
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                $response->withHeader((string) $name, (string) $value);
            }
        }
        return $response;
    }
}
