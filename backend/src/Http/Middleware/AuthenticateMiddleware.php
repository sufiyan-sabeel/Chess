<?php

declare(strict_types=1);

namespace Checkmate\Http\Middleware;

use Checkmate\Auth\TokenService;
use Checkmate\Database\Connection;
use Checkmate\Http\ApiException;
use Checkmate\Http\Request;
use Checkmate\Http\Response;

/**
 * Bearer access-token authentication for routes marked **[auth]** in
 * docs/API.md. Route meta:
 *
 *   'auth' => true      require a valid, unexpired access token
 *   'auth' => 'optional' authenticate when Authorization is present, otherwise
 *                       proceed anonymously (and reject a present-but-bad token)
 *   'auth' => 'lenient' authenticate when the token still resolves; a missing,
 *                       revoked or expired token proceeds anonymously — used by
 *                       POST /auth/logout, which docs/API.md §1 defines as
 *                       idempotent ("already-revoked tokens return success")
 *
 * The authenticated user row is placed in $request->attributes['user'].
 */
final class AuthenticateMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        /** @var array{meta:array<string,mixed>}|null $route */
        $route = $request->attributes['route'] ?? null;
        $mode = is_array($route) ? ($route['meta']['auth'] ?? false) : false;

        if ($mode === 'lenient') {
            $token = $request->bearerToken();
            $user = $token === null ? null : $this->resolveOrNull($token);
            if ($user !== null) {
                $this->attach($request, $user);
            }
            return $next($request);
        }

        if ($mode !== false) {
            $token = $request->bearerToken();

            if ($token === null) {
                if ($mode === true) {
                    throw new ApiException('UNAUTHORIZED', 'Authentication required.', 401);
                }
            } else {
                $user = $this->resolveUser($token);
                if ($user === null && $mode === true) {
                    throw new ApiException('UNAUTHORIZED', 'Authentication required.', 401);
                }
                if ($user !== null) {
                    $this->attach($request, $user);
                }
            }
        }

        return $next($request);
    }

    private function attach(Request $request, array $user): void
    {
        $request->attributes['user'] = $user;
        // `user_id` for controllers from the concurrently-shipped stack
        // (AccountController reads attributes['user_id']).
        $request->attributes['user_id'] = (int) $user['id'];
    }

    /** Like resolveUser(), but any failure (unknown/expired/revoked) yields null. */
    private function resolveOrNull(string $token): ?array
    {
        try {
            return $this->resolveUser($token);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null null when the token is unknown/revoked (expired throws) */
    private function resolveUser(string $token): ?array
    {
        $tokens = new TokenService(Connection::pdo());
        $row = $tokens->verifyAccess($token); // throws TOKEN_INVALID / TOKEN_EXPIRED

        $stmt = Connection::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$row['user_id']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new ApiException('TOKEN_INVALID', 'Access token is invalid.', 401);
        }
        return $user;
    }
}
