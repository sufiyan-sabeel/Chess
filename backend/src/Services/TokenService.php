<?php

declare(strict_types=1);

namespace Checkmate\Services;

use Checkmate\Database\Connection;
use Checkmate\Http\ApiException;
use Checkmate\Support\Clock;

/**
 * Opaque token issue/rotation/validation (docs/API.md §1).
 *
 * - Tokens: 32 random bytes, base64url ("43-char" strings).
 * - Only SHA-256 hashes are stored; plaintext tokens exist only in responses.
 * - Access TTL 15 min, refresh TTL 30 days.
 * - Refresh tokens rotate on every use; presenting an already-used refresh
 *   token revokes the whole family (theft detection).
 */
final class TokenService
{
    public static function generate(): string
    {
        return self::base64url(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /**
     * Create a brand-new session (family).
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int, family_id:string, session_id:int}
     */
    public function issue(int $userId, string $ip): array
    {
        $now = Clock::now();
        $access = self::generate();
        $refresh = self::generate();
        $familyId = self::uuid4();

        $stmt = Connection::pdo()->prepare(
            'INSERT INTO sessions
                (user_id, family_id, token_hash, access_token_hash, access_expires_at, expires_at, created_ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $familyId,
            self::hash($refresh),
            self::hash($access),
            Clock::sql($now->modify('+' . (int) config('tokens.access_ttl') . ' seconds')),
            Clock::sql($now->modify('+' . (int) config('tokens.refresh_ttl') . ' seconds')),
            substr($ip, 0, 45),
            Clock::sql($now),
        ]);
        $sessionId = (int) Connection::pdo()->lastInsertId();

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => (int) config('tokens.access_ttl'),
            'family_id' => $familyId,
            'session_id' => $sessionId,
        ];
    }

    /**
     * Rotate a refresh token: marks the old one used and issues a new pair
     * in the same family. Reuse of a used token revokes the family.
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int}
     */
    public function rotate(string $refreshToken, string $ip): array
    {
        $pdo = Connection::pdo();
        $now = Clock::now();
        $tokenHash = self::hash($refreshToken);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id, user_id, family_id, used_at, revoked_at, expires_at
                 FROM sessions WHERE token_hash = ? FOR UPDATE'
            );
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                $pdo->rollBack();
                throw new ApiException('TOKEN_INVALID', 'The refresh token is invalid or has been revoked.', 401);
            }

            if ($row['revoked_at'] !== null) {
                $pdo->rollBack();
                throw new ApiException('TOKEN_INVALID', 'The refresh token is invalid or has been revoked.', 401);
            }

            if ($row['used_at'] !== null) {
                // Reuse of a rotated token => assume theft, kill the family.
                $revoke = $pdo->prepare(
                    'UPDATE sessions SET revoked_at = ?, revoked_reason = ?
                     WHERE family_id = ? AND revoked_at IS NULL'
                );
                $revoke->execute([Clock::sql($now), 'refresh_reuse', $row['family_id']]);
                $pdo->commit();
                \Checkmate\Support\Log::event('auth.refresh_reuse_revoked_family', [
                    'user_id' => (int) $row['user_id'],
                    'family_id' => (string) $row['family_id'],
                ]);
                throw new ApiException('TOKEN_INVALID', 'The refresh token is invalid or has been revoked.', 401);
            }

            if (Clock::fromSql((string) $row['expires_at']) <= $now) {
                $pdo->rollBack();
                throw new ApiException('TOKEN_EXPIRED', 'The refresh token has expired.', 401);
            }

            $mark = $pdo->prepare('UPDATE sessions SET used_at = ? WHERE id = ?');
            $mark->execute([Clock::sql($now), $row['id']]);

            $access = self::generate();
            $refresh = self::generate();
            $insert = $pdo->prepare(
                'INSERT INTO sessions
                    (user_id, family_id, token_hash, access_token_hash, access_expires_at, expires_at, created_ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                (int) $row['user_id'],
                (string) $row['family_id'],
                self::hash($refresh),
                self::hash($access),
                Clock::sql($now->modify('+' . (int) config('tokens.access_ttl') . ' seconds')),
                Clock::sql($now->modify('+' . (int) config('tokens.refresh_ttl') . ' seconds')),
                substr($ip, 0, 45),
                Clock::sql($now),
            ]);

            $pdo->commit();
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        \Checkmate\Support\Log::event('auth.refresh_rotated', ['user_id' => (int) $row['user_id']]);

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => (int) config('tokens.access_ttl'),
        ];
    }

    /**
     * Validate an access token. Returns null when unknown/used/revoked/expired.
     *
     * @return array{session_id:int, user_id:int, family_id:string}|null
     */
    public function validateAccess(string $token): ?array
    {
        if (!self::isWellFormed($token)) {
            return null;
        }
        $stmt = Connection::pdo()->prepare(
            'SELECT id, user_id, family_id
             FROM sessions
             WHERE access_token_hash = ?
               AND used_at IS NULL
               AND revoked_at IS NULL
               AND access_expires_at > ?'
        );
        $stmt->execute([self::hash($token), Clock::sqlNow()]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        return [
            'session_id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'family_id' => (string) $row['family_id'],
        ];
    }

    /** Revoke every row of a family (logout, reset, theft detection). */
    public function revokeFamily(string $familyId, string $reason): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE sessions SET revoked_at = ?, revoked_reason = ? WHERE family_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([Clock::sqlNow(), substr($reason, 0, 32), $familyId]);
    }

    /** Revoke all sessions of a user (password reset, account changes). */
    public function revokeAllForUser(int $userId, string $reason): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE sessions SET revoked_at = ?, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([Clock::sqlNow(), substr($reason, 0, 32), $userId]);
    }

    /**
     * Idempotent logout: revoke the family owning this refresh token.
     * Returns true even when the token is unknown or already revoked.
     */
    public function revokeByRefreshToken(string $refreshToken, string $reason): bool
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT family_id FROM sessions WHERE token_hash = ?'
        );
        $stmt->execute([self::hash($refreshToken)]);
        $familyId = $stmt->fetchColumn();
        if ($familyId === false) {
            return true;
        }
        $this->revokeFamily((string) $familyId, $reason);
        return true;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex = bin2hex($b);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
