<?php

declare(strict_types=1);

namespace Checkmate\Http\Middleware;

use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * Assigns/echoes X-Request-Id (docs/API.md "Headers"):
 * a well-formed incoming id is echoed so client and server logs correlate;
 * anything else (or nothing) gets a fresh 32-hex id. Applied to every
 * response, including error responses.
 */
final class RequestIdMiddleware
{
    private const PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    public function handle(Request $request, callable $next): Response
    {
        $incoming = $request->header('x-request-id');
        $id = ($incoming !== null && preg_match(self::PATTERN, $incoming) === 1)
            ? $incoming
            : bin2hex(random_bytes(16));

        $request->setRequestId($id);
        $response = $next($request);
        $response->withHeader('X-Request-Id', $id);
        return $response;
    }
}
