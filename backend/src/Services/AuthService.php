<?php

declare(strict_types=1);

namespace Checkmate\Services;

use Checkmate\Database\Connection;
use Checkmate\Http\ApiException;
use Checkmate\Support\Clock;
use Checkmate\Support\Log;

/**
 * All authentication / account flows (docs/API.md §1).
 *
 * Security invariants:
 *  - password_hash(PASSWORD_ARGON2ID) when the build supports it, else the
 *    documented PASSWORD_BCRYPT fallback (this Termux PHP build has no argon2).
 *  - Login answers are identical for unknown email and wrong password, both
 *    padded to a >=100 ms budget (enumeration safety).
 *  - Tokens: opaque, 32 random bytes base64url, only SHA-256 stored.
 *  - Logs contain IDs and event names only — never passwords/tokens/emails.
 */
final class AuthService
{
    /** Valid bcrypt hash used to equalize login timing for unknown e-mails. */
    private const DUMMY_HASH = '$2y$12$3ds9Ei0NMNjNK1LLgHFvqeEeJpj3KI77IqChzgZPR81vSh0O/oAu.';

    private const MODES = ['bullet', 'blitz', 'rapid', 'classical'];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly MailService $mail,
    ) {
    }

    // ---------------------------------------------------------------- register

    /** @return array<string,mixed> data payload for 201 response */
    public function register(string $email, string $password, string $displayName, string $ip): array
    {
        $email = self::normalizeEmail($email);
        $pdo = Connection::pdo();

        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetchColumn() !== false) {
            throw new ApiException('EMAIL_TAKEN', 'An account with this email already exists.', 409);
        }

        $hash = self::hashPassword($password);
        $now = Clock::now();
        $avatarSeed = bin2hex(random_bytes(8));

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, password_hash, display_name, email_verified, avatar_seed, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?)'
            );
            $stmt->execute([$email, $hash, $displayName, $avatarSeed, Clock::sql($now), Clock::sql($now)]);
            $userId = (int) $pdo->lastInsertId();

            $rating = $pdo->prepare(
                'INSERT INTO player_ratings
                    (user_id, mode, rating, highest_rating, wins, losses, draws, games, updated_at)
                 VALUES (?, ?, 1200, 1200, 0, 0, 0, 0, ?)'
            );
            foreach (self::MODES as $mode) {
                $rating->execute([$userId, $mode, Clock::sql($now)]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                // Concurrent registration of the same e-mail.
                throw new ApiException('EMAIL_TAKEN', 'An account with this email already exists.', 409);
            }
            throw $e;
        }

        $pair = $this->tokens->issue($userId, $ip);
        Log::event('auth.registered', ['user_id' => $userId]);

        return [
            'user' => $this->publicUserById($userId),
            'access_token' => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'expires_in' => $pair['expires_in'],
        ];
    }

    // ------------------------------------------------------------------- login

    /** @return array<string,mixed> data payload for 200 response */
    public function login(string $email, string $password, string $ip): array
    {
        $started = microtime(true);
        $email = self::normalizeEmail($email);

        $stmt = Connection::pdo()->prepare(
            'SELECT id, password_hash FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            // Unknown e-mail: burn the same work as a real verify, then pad.
            password_verify($password, self::DUMMY_HASH);
            $this->padToBudget($started);
            Log::event('auth.login_failed', ['reason' => 'unknown_email']);
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        $ok = password_verify($password, (string) $row['password_hash']);
        if (!$ok) {
            $this->padToBudget($started);
            Log::event('auth.login_failed', ['user_id' => (int) $row['id']]);
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        $pair = $this->tokens->issue((int) $row['id'], $ip);
        $this->padToBudget($started);
        Log::event('auth.login_ok', ['user_id' => (int) $row['id']]);

        return [
            'access_token' => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'expires_in' => $pair['expires_in'],
            'user' => $this->publicUserById((int) $row['id']),
        ];
    }

    // ----------------------------------------------------------------- refresh

    /** @return array{access_token:string, refresh_token:string, expires_in:int} */
    public function refresh(string $refreshToken, string $ip): array
    {
        $result = $this->tokens->rotate($refreshToken, $ip);
        Log::event('auth.refresh_ok', []);
        return $result;
    }

    // ------------------------------------------------------------------ logout

    /**
     * Idempotent: unknown / already-revoked tokens still return ok.
     * $currentUserId / $currentFamilyId are null when the access token was
     * already revoked (retry of a completed logout) — then revocation is
     * driven solely by the refresh token in the request body.
     */
    public function logout(?int $currentUserId, ?string $currentFamilyId, ?string $refreshToken): void
    {
        if ($refreshToken !== null && $refreshToken !== '') {
            $this->tokens->revokeByRefreshToken($refreshToken, 'logout');
        }
        if ($currentFamilyId !== null && $currentFamilyId !== '') {
            $this->tokens->revokeFamily($currentFamilyId, 'logout');
        }
        Log::event('auth.logout', $currentUserId === null ? ['source' => 'idempotent_retry'] : ['user_id' => $currentUserId]);
    }

    // ---------------------------------------------------------------------- me

    /** @return array<string,mixed> {user: ...} */
    public function me(int $userId): array
    {
        return ['user' => $this->publicUserById($userId)];
    }

    // ----------------------------------------------------------- verify e-mail

    public function verifyEmail(string $token): void
    {
        if (!TokenService::isWellFormed($token)) {
            throw new ApiException('TOKEN_INVALID', 'This verification link is invalid or has already been used.', 400);
        }

        $pdo = Connection::pdo();
        $now = Clock::now();

        $stmt = $pdo->prepare(
            'SELECT id, user_id, expires_at, used_at FROM email_verification_tokens WHERE token_hash = ?'
        );
        $stmt->execute([TokenService::hash($token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row['used_at'] !== null) {
            throw new ApiException('TOKEN_INVALID', 'This verification link is invalid or has already been used.', 400);
        }
        if (Clock::fromSql((string) $row['expires_at']) <= $now) {
            throw new ApiException('TOKEN_EXPIRED', 'This verification link has expired.', 400);
        }

        $pdo->beginTransaction();
        try {
            $consume = $pdo->prepare(
                'UPDATE email_verification_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL'
            );
            $consume->execute([Clock::sql($now), $row['id']]);
            if ($consume->rowCount() === 0) {
                $pdo->rollBack();
                throw new ApiException('TOKEN_INVALID', 'This verification link is invalid or has already been used.', 400);
            }

            $verify = $pdo->prepare(
                'UPDATE users SET email_verified = 1, updated_at = ? WHERE id = ?'
            );
            $verify->execute([Clock::sql($now), $row['user_id']]);
            $pdo->commit();
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Log::event('auth.email_verified', ['user_id' => (int) $row['user_id']]);
    }

    // ----------------------------------------------------- resend verification

    /**
     * Always generic 200 {ok:true} — never reveals whether the e-mail exists.
     * 503 MAIL_NOT_CONFIGURED when the transport is down (checked BEFORE the
     * user lookup so the status code cannot leak existence either).
     *
     * @return array<string,mixed> response data
     */
    public function resendVerification(?string $email, ?int $authenticatedUserId): array
    {
        $this->requireMail();

        $user = null;
        if ($authenticatedUserId !== null) {
            $user = $this->fetchUser($authenticatedUserId);
        } elseif ($email !== null && $email !== '') {
            $user = $this->fetchUserByEmail(self::normalizeEmail($email));
        }

        if ($user !== null) {
            $pdo = Connection::pdo();
            $del = $pdo->prepare(
                'DELETE FROM email_verification_tokens WHERE user_id = ? AND used_at IS NULL'
            );
            $del->execute([$user['id']]);

            $token = TokenService::generate();
            $insert = $pdo->prepare(
                'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->execute([
                (int) $user['id'],
                TokenService::hash($token),
                Clock::sql(Clock::now()->modify('+' . (int) config('tokens.email_verify_ttl') . ' seconds')),
                Clock::sqlNow(),
            ]);

            $result = $this->mail->sendVerificationEmail((string) $user['email'], $token);
            if (!$result->configured) {
                $this->requireMail(); // -> 503 (config can change under us)
            }
            Log::event('auth.verification_sent', ['user_id' => (int) $user['id']]);
            return $this->okWithPreview($result->preview);
        }

        Log::event('auth.verification_resend_ignored', []);
        return $this->okWithPreview(null);
    }

    // --------------------------------------------------------- forgot password

    /**
     * Always generic 200 {ok:true} (enumeration safe). Mail-not-configured is
     * checked before the user lookup so 503 cannot reveal existence either.
     *
     * @return array<string,mixed> response data
     */
    public function forgotPassword(string $email): array
    {
        $this->requireMail();

        $user = $this->fetchUserByEmail(self::normalizeEmail($email));
        if ($user !== null) {
            $pdo = Connection::pdo();
            $del = $pdo->prepare(
                'DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL'
            );
            $del->execute([$user['id']]);

            $token = TokenService::generate();
            $insert = $pdo->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->execute([
                (int) $user['id'],
                TokenService::hash($token),
                Clock::sql(Clock::now()->modify('+' . (int) config('tokens.password_reset_ttl') . ' seconds')),
                Clock::sqlNow(),
            ]);

            $result = $this->mail->sendPasswordResetEmail((string) $user['email'], $token);
            if (!$result->configured) {
                $this->requireMail();
            }
            Log::event('auth.password_reset_sent', ['user_id' => (int) $user['id']]);
            return $this->okWithPreview($result->preview);
        }

        Log::event('auth.password_reset_ignored', []);
        return $this->okWithPreview(null);
    }

    // --------------------------------------------------------- reset password

    public function resetPassword(string $token, string $newPassword): void
    {
        if (!TokenService::isWellFormed($token)) {
            throw new ApiException('TOKEN_INVALID', 'This reset link is invalid or has already been used.', 400);
        }

        $pdo = Connection::pdo();
        $now = Clock::now();

        $stmt = $pdo->prepare(
            'SELECT id, user_id, expires_at, used_at FROM password_reset_tokens WHERE token_hash = ?'
        );
        $stmt->execute([TokenService::hash($token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row['used_at'] !== null) {
            throw new ApiException('TOKEN_INVALID', 'This reset link is invalid or has already been used.', 400);
        }
        if (Clock::fromSql((string) $row['expires_at']) <= $now) {
            throw new ApiException('TOKEN_EXPIRED', 'This reset link has expired.', 400);
        }

        $hash = self::hashPassword($newPassword);

        $pdo->beginTransaction();
        try {
            $consume = $pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL'
            );
            $consume->execute([Clock::sql($now), $row['id']]);
            if ($consume->rowCount() === 0) {
                $pdo->rollBack();
                throw new ApiException('TOKEN_INVALID', 'This reset link is invalid or has already been used.', 400);
            }

            $update = $pdo->prepare(
                'UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?'
            );
            $update->execute([$hash, Clock::sql($now), $row['user_id']]);
            $pdo->commit();
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Password changed => every issued session dies (docs/API.md §1).
        $this->tokens->revokeAllForUser((int) $row['user_id'], 'password_reset');
        Log::event('auth.password_reset_completed', ['user_id' => (int) $row['user_id']]);
    }

    // -------------------------------------------------------- delete account

    /**
     * Hard-deletes the user, tokens, ratings and games; match records survive
     * with the player link nulled out (ON DELETE SET NULL, docs/API.md §1).
     */
    public function deleteAccount(int $userId, string $password): void
    {
        $user = $this->fetchUser($userId);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        $pdo = Connection::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Log::event('account.deleted', ['user_id' => $userId]);
    }

    // ---------------------------------------------------------------- helpers

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Throws 503 MAIL_NOT_CONFIGURED when the transport cannot send. */
    private function requireMail(): void
    {
        if ($this->mail->isConfigured()) {
            return;
        }
        $extra = [];
        if (config('app.env') === 'development') {
            $extra['dev_preview'] = null;
        }
        throw new ApiException(
            'MAIL_NOT_CONFIGURED',
            'Mail transport is not configured.',
            503,
            $extra
        );
    }

    /** @return array<string,mixed> */
    private function okWithPreview(?string $preview): array
    {
        $data = ['ok' => true];
        if (config('app.env') === 'development' && $preview !== null) {
            $data['dev_preview'] = $preview;
        }
        return $data;
    }

    private function padToBudget(float $startedAt): void
    {
        $budgetMs = (int) config('security.login_min_budget_ms', 100);
        $remainingUs = (int) (($budgetMs / 1000 - (microtime(true) - $startedAt)) * 1_000_000);
        if ($remainingUs > 0) {
            usleep($remainingUs);
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchUser(int $userId): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT id, email, password_hash, display_name, email_verified, avatar_seed, created_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function fetchUserByEmail(string $email): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT id, email, password_hash, display_name, email_verified, avatar_seed, created_at
             FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Public user shape incl. per-mode ratings (docs/API.md §1 /me).
     *
     * @return array<string,mixed>
     */
    private function publicUserById(int $userId): array
    {
        $user = $this->fetchUser($userId);
        if ($user === null) {
            throw new ApiException('UNAUTHORIZED', 'Authentication required.', 401);
        }

        $ratings = array_fill_keys(self::MODES, [
            'rating' => 1200,
            'highest_rating' => 1200,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'games' => 0,
        ]);

        $stmt = Connection::pdo()->prepare(
            'SELECT mode, rating, highest_rating, wins, losses, draws, games
             FROM player_ratings WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $mode = (string) $row['mode'];
            $ratings[$mode] = [
                'rating' => (int) $row['rating'],
                'highest_rating' => (int) $row['highest_rating'],
                'wins' => (int) $row['wins'],
                'losses' => (int) $row['losses'],
                'draws' => (int) $row['draws'],
                'games' => (int) $row['games'],
            ];
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'email_verified' => (bool) $user['email_verified'],
            'avatar_seed' => (string) $user['avatar_seed'],
            'ratings' => $ratings,
            'created_at' => Clock::iso(Clock::fromSql((string) $user['created_at'])),
        ];
    }
}
