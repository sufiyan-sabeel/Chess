<?php

declare(strict_types=1);

namespace Checkmate\Middleware;

use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * Assigns/echoes X-Request-Id on every response.
 * A caller-supplied id is echoed when well-formed ([A-Za-z0-9._-]{1,64}),
 * otherwise a fresh 128-bit id is generated.
 */
final class RequestIdMiddleware implements Middleware
{
    /**
     * Resolve (or generate) the request id and store it on the request.
     * Called by App::handle() *before* routing, so responses that never reach
     * the pipeline — 404s, oversized-body rejections — still carry one.
     */
    public static function assign(Request $request): string
    {
        $existing = $request->requestId();
        if ($existing !== null) {
            return $existing;
        }

        $incoming = $request->header('x-request-id');
        $id = ($incoming !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming) === 1)
            ? $incoming
            : bin2hex(random_bytes(16));

        $request->setRequestId($id);
        return $id;
    }

    public function handle(Request $request, array $meta, callable $next): Response
    {
        $id = self::assign($request);

        $response = $next($request);
        return $response->withHeader('X-Request-Id', $id);
    }
}
