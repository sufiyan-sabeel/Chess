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
    public function handle(Request $request, array $meta, callable $next): Response
    {
        $incoming = $request->header('x-request-id');
        $id = ($incoming !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming) === 1)
            ? $incoming
            : bin2hex(random_bytes(16));

        $request->setRequestId($id);

        $response = $next($request);
        return $response->withHeader('X-Request-Id', $id);
    }
}
