<?php

declare(strict_types=1);

namespace Checkmate\Auth;

use Checkmate\Http\ApiException;
use Checkmate\Support\Clock;

/**
 * Opaque token issue/verify/rotate (docs/API.md §1):
 *
 *  - access  : 43-char base64url (32 random bytes), TTL 15 min, stateless
 *  - refresh : 43-char base64url, TTL 30 days, rotated on every refresh
 *  - only SHA-256 hashes are stored — a DB leak cannot be replayed
 *  - refresh tokens issued from the same login form a `family_id`; presenting a
 *    token that was already rotated (or revoked) revokes the whole family —
 *    classic refresh-token theft detection
 */
final class TokenService
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** 43-char base64url string (32 bytes, no padding). */
    public static function randomOpaque(): string
    {
        return self::b64url(random_bytes(32));
    }

    /** 32 random bytes hex-encoded (e-mail verification / reset tokens). */
    public static function randomHex(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Issue an access+refresh pair for a session.
     *
     * @return array{family_id:string, access_token:string, refresh_token:string, expires_in:int}
     */
    public function issue(int $userId, ?string $familyId = null): array
    {
        $familyId ??= self::uuid();
        $access = self::randomOpaque();
        $refresh = self::randomOpaque();
        $now = Clock::now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, family_id, kind, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $accessTtl = (int) config('tokens.access_ttl', 900);
        $refreshTtl = (int) config('tokens.refresh_ttl', 2592000);

        $stmt->execute([$userId, $familyId, 'access', self::hash($access), Clock::sql($now->modify("+{$accessTtl} seconds")), Clock::sql($now)]);
        $stmt->execute([$userId, $familyId, 'refresh', self::hash($refresh), Clock::sql($now->modify("+{$refreshTtl} seconds")), Clock::sql($now)]);

        $this->purgeExpired();

        return [
            'family_id' => $familyId,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $accessTtl,
        ];
    }

    /**
     * Validate an access token.
     *
     * @return array<string,mixed> the auth_tokens row
     * @throws ApiException TOKEN_INVALID / TOKEN_EXPIRED (401)
     */
    public function verifyAccess(string $token): array
    {
        $row = $this->findByHash($token, 'access');
        if ($row === null) {
            throw new ApiException('TOKEN_INVALID', 'Access token is invalid.', 401);
        }
        if ($row['revoked_at'] !== null) {
            throw new ApiException('TOKEN_INVALID', 'Access token is invalid.', 401);
        }
        if (strcmp((string) $row['expires_at'], Clock::sqlNow()) <= 0) {
            throw new ApiException('TOKEN_EXPIRED', 'Access token has expired.', 401);
        }
        return $row;
    }

    /**
     * Rotate a refresh token: the presented token is marked used and a new
     * access+refresh pair is issued in the same family.
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int}
     * @throws ApiException on invalid/expired/reused tokens
     */
    public function rotate(string $refreshToken): array
    {
        $row = $this->findByHash($refreshToken, 'refresh');
        if ($row === null) {
            throw new ApiException('TOKEN_INVALID', 'Refresh token is invalid.', 401);
        }

        // Theft detection: a rotated (already used) token presented again means
        // two parties hold the same family — revoke everything and force re-login.
        if ($row['used_at'] !== null) {
            $this->revokeFamily((string) $row['family_id'], 'reuse');
            throw new ApiException('TOKEN_INVALID', 'Refresh token reuse detected; the session has been revoked.', 401);
        }
        if ($row['revoked_at'] !== null) {
            throw new ApiException('TOKEN_INVALID', 'Refresh token has been revoked.', 401);
        }
        if (strcmp((string) $row['expires_at'], Clock::sqlNow()) <= 0) {
            throw new ApiException('TOKEN_EXPIRED', 'Refresh token has expired.', 401);
        }

        $now = Clock::now();
        $this->pdo->beginTransaction();
        try {
            // Conditional mark-used: only one concurrent caller can win.
            $upd = $this->pdo->prepare(
                'UPDATE auth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL'
            );
            $upd->execute([Clock::sql($now), $row['id']]);
            if ($upd->rowCount() === 0) {
                $this->pdo->rollBack();
                $this->revokeFamily((string) $row['family_id'], 'reuse');
                throw new ApiException('TOKEN_INVALID', 'Refresh token reuse detected; the session has been revoked.', 401);
            }

            $access = self::randomOpaque();
            $refresh = self::randomOpaque();
            $accessTtl = (int) config('tokens.access_ttl', 900);
            $refreshTtl = (int) config('tokens.refresh_ttl', 2592000);

            $ins = $this->pdo->prepare(
                'INSERT INTO auth_tokens (user_id, family_id, kind, token_hash, parent_id, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $row['user_id'], $row['family_id'], 'access', self::hash($access), $row['id'],
                Clock::sql($now->modify("+{$accessTtl} seconds")), Clock::sql($now),
            ]);
            $ins->execute([
                $row['user_id'], $row['family_id'], 'refresh', self::hash($refresh), $row['id'],
                Clock::sql($now->modify("+{$refreshTtl} seconds")), Clock::sql($now),
            ]);

            $this->pdo->commit();
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $accessTtl,
        ];
    }

    /** Revoke every token of one session chain (logout / reuse detected). */
    public function revokeFamily(string $familyId, string $reason): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_tokens SET revoked_at = ?, revoked_reason = ? WHERE family_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([Clock::sqlNow(), $reason, $familyId]);
        return $stmt->rowCount();
    }

    /** Revoke everything for a user (password reset, account closure). */
    public function revokeAllForUser(int $userId, string $reason): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_tokens SET revoked_at = ?, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([Clock::sqlNow(), $reason, $userId]);
        return $stmt->rowCount();
    }

    /** Idempotent logout helper: unknown tokens are a no-op success. */
    public function revokeByRefreshToken(string $refreshToken, string $reason): int
    {
        $row = $this->findByHash($refreshToken, 'refresh');
        if ($row === null) {
            return 0;
        }
        return $this->revokeFamily((string) $row['family_id'], $reason);
    }

    /** Housekeeping: drop tokens that are already expired. */
    public function purgeExpired(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_tokens WHERE expires_at < ?');
        $stmt->execute([Clock::sqlNow()]);
    }

    /** @return array<string,mixed>|null */
    private function findByHash(string $token, string $kind): ?array
    {
        // Reject obviously malformed tokens early (cheap length sanity check).
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM auth_tokens WHERE token_hash = ? AND kind = ?');
        $stmt->execute([self::hash($token), $kind]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
