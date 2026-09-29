<?php

declare(strict_types=1);

namespace Checkmate\Middleware;

use Checkmate\Http\ApiException;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Services\TokenService;

/**
 * Bearer access-token authentication (docs/API.md §1).
 *
 * meta['auth']:
 *   true        => required (401 UNAUTHORIZED without a valid token)
 *   'optional'  => token validated when present, anonymous otherwise
 *   'idempotent'=> Authorization header required, but an unknown / expired /
 *                  already-revoked access token is tolerated (logout must
 *                  answer 200 for already-revoked tokens, docs/API.md §1)
 *   unset/false => public route
 *
 * On success sets request attributes: user_id, session_id, family_id.
 */
final class AuthMiddleware implements Middleware
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function handle(Request $request, array $meta, callable $next): Response
    {
        $mode = $meta['auth'] ?? false;
        if ($mode === false) {
            return $next($request);
        }

        $token = $request->bearerToken();

        if ($token === null) {
            if ($mode === 'optional') {
                return $next($request);
            }
            throw new ApiException('UNAUTHORIZED', 'Authentication required.', 401);
        }

        $session = $this->tokens->validateAccess($token);
        if ($session === null) {
            if ($mode === 'idempotent') {
                // Retry of an already-completed logout: the access token died
                // with the family. Continue without session context so the
                // handler can answer idempotently (it revokes by refresh token).
                return $next($request);
            }
            throw new ApiException('UNAUTHORIZED', 'The access token is invalid or has expired.', 401);
        }

        $request->attributes['user_id'] = $session['user_id'];
        $request->attributes['session_id'] = $session['session_id'];
        $request->attributes['family_id'] = $session['family_id'];

        return $next($request);
    }
}
