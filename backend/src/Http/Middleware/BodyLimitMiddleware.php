<?php

declare(strict_types=1);

namespace Checkmate\Http\Middleware;

use Checkmate\Http\ApiException;
use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * Hard request-body cap (BODY_MAX_BYTES, default 64 KB) applied before any
 * controller reads the body — a huge payload never reaches validation or the
 * database.
 */
final class BodyLimitMiddleware
{
    private const METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly int $maxBytes)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::METHODS_WITH_BODY, true)
            && $request->bodySize() > $this->maxBytes) {
            throw new ApiException(
                'VALIDATION_ERROR',
                sprintf('Request body exceeds the %d KB limit.', (int) ($this->maxBytes / 1024)),
                413,
            );
        }
        return $next($request);
    }
}
