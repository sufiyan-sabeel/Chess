<?php

declare(strict_types=1);

namespace Checkmate\Middleware;

use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * Middleware contract: run, optionally short-circuit, or delegate to $next.
 * Signature: fn(Request $r, array $routeMeta, callable $next): Response
 */
interface Middleware
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, array $meta, callable $next): Response;
}
