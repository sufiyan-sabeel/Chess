<?php

declare(strict_types=1);

namespace Checkmate\Auth;

use Checkmate\Http\ApiException;
use Checkmate\Support\Clock;
use Checkmate\Support\Log;
use Checkmate\Support\Validator;

/**
 * Authentication use cases (docs/API.md §1).
 *
 * Security properties implemented here:
 *  - uniform login failures (unknown e-mail and wrong password are
 *    indistinguishable in both response and timing: >= 100 ms budget)
 *  - enumeration-safe verify / resend / forgot (identical 200/503 regardless of
 *    whether the account exists)
 *  - password_reset and account-wide revocation of sessions
 *  - Argon2id (bcrypt fallback) password hashing
 */
final class AuthService
{
    private readonly TokenService $tokens;
    private readonly UserRepository $users;
    private readonly Mailer $mailer;

    public function __construct(private readonly \PDO $pdo, ?Mailer $mailer = null)
    {
        $this->tokens = new TokenService($pdo);
        $this->users = new UserRepository($pdo);
        $this->mailer = $mailer ?? MailerFactory::create();
    }

    /**
     * POST /auth/register -> 201 payload.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function register(array $input): array
    {
        $fields = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|password',
            'display_name' => 'required|string|min:3|max:20|regex:/^[A-Za-z0-9_]+$/',
        ]);
        if ($fields !== []) {
            throw self::invalid($fields);
        }

        $email = UserRepository::normalizeEmail((string) $input['email']);
        if ($this->users->findByEmail($email) !== null) {
            throw new ApiException('EMAIL_TAKEN', 'That e-mail address is already registered.', 409);
        }

        try {
            $userId = $this->users->create(
                $email,
                PasswordService::hash((string) $input['password']),
                (string) $input['display_name'],
            );
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                // Unique index raced with a concurrent registration.
                throw new ApiException('EMAIL_TAKEN', 'That e-mail address is already registered.', 409);
            }
            throw $e;
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw ApiException::internal();
        }

        // E-mail sending must never break registration: failures are logged and
        // the token can be requested again via /auth/resend-verification.
        $this->sendVerificationMail($user, 'verify');

        $pair = $this->tokens->issue($userId);

        return [
            'user' => UserRepository::toPublic($user),
            'access_token' => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'expires_in' => $pair['expires_in'],
        ];
    }

    /**
     * POST /auth/login -> 200 payload. Failures are timing-equalized.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function login(array $input): array
    {
        $fields = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|string|min:1|max:1024',
        ]);
        if ($fields !== []) {
            throw self::invalid($fields);
        }

        $start = hrtime(true);

        $user = $this->users->findByEmail(UserRepository::normalizeEmail((string) $input['email']));
        if ($user !== null) {
            $ok = PasswordService::verify((string) $input['password'], (string) $user['password_hash']);
        } else {
            // Burn the same CPU as a real verification so response time does
            // not reveal whether the account exists.
            $ok = false;
            PasswordService::verify((string) $input['password'], PasswordService::dummyHash());
        }

        $budgetNs = (int) config('security.login_min_budget_ms', 100) * 1_000_000;
        $elapsed = hrtime(true) - $start;
        if ($elapsed < $budgetNs) {
            usleep((int) (($budgetNs - $elapsed) / 1000));
        }

        if (!$ok || $user === null) {
            Log::event('auth.login_failed'); // no e-mail, no IP: enumeration-safe logging
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid e-mail or password.', 401);
        }

        if (PasswordService::needsRehash((string) $user['password_hash'])) {
            $this->users->updatePassword((int) $user['id'], PasswordService::hash((string) $input['password']));
        }

        $pair = $this->tokens->issue((int) $user['id']);

        return [
            'access_token' => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'expires_in' => $pair['expires_in'],
            'user' => UserRepository::toPublic($user),
        ];
    }

    /**
     * POST /auth/refresh -> new pair (rotation; reuse revokes the family).
     *
     * @return array<string,mixed>
     */
    public function refresh(string $refreshToken): array
    {
        return $this->tokens->rotate($refreshToken);
    }

    /**
     * POST /auth/logout -> {ok:true}, idempotent (unknown / already-revoked
     * tokens are a success, docs/API.md §1).
     *
     * @return array<string,mixed>
     */
    public function logout(string $refreshToken): array
    {
        $this->tokens->revokeByRefreshToken($refreshToken, 'logout');
        return ['ok' => true];
    }

    /**
     * GET /auth/me -> {user:{...ratings...}}.
     *
     * @param array<string,mixed> $userRow
     * @return array<string,mixed>
     */
    public function me(array $userRow): array
    {
        $public = UserRepository::toPublic($userRow);
        unset($public['email_verified']); // re-added below in the documented order
        $public = [
            'id' => $public['id'],
            'email' => $public['email'],
            'display_name' => $public['display_name'],
            'email_verified' => (bool) $userRow['email_verified'],
            'avatar_seed' => (string) $userRow['avatar_seed'],
            'ratings' => $this->users->ratings((int) $userRow['id']),
            'created_at' => $public['created_at'],
        ];
        return ['user' => $public];
    }

    /**
     * POST /auth/verify-email -> {ok:true}; every failure mode returns the
     * same generic TOKEN_INVALID so tokens/addresses cannot be probed.
     *
     * @return array<string,mixed>
     */
    public function verifyEmail(string $token): array
    {
        $invalid = new ApiException(
            'TOKEN_INVALID',
            'This verification link is invalid or has expired.',
            401,
        );

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw $invalid;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM email_verification_tokens WHERE token_hash = ?');
        $stmt->execute([TokenService::hash($token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row['used_at'] !== null
            || strcmp((string) $row['expires_at'], Clock::sqlNow()) <= 0) {
            throw $invalid;
        }

        $now = Clock::sqlNow();
        $upd = $this->pdo->prepare('UPDATE email_verification_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $upd->execute([$now, $row['id']]);
        if ($upd->rowCount() === 0) {
            throw $invalid; // raced with another single-use consumption
        }

        $this->users->markVerified((int) $row['user_id']);
        $clear = $this->pdo->prepare(
            'UPDATE email_verification_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
        );
        $clear->execute([$now, $row['user_id']]);

        Log::event('auth.email_verified', ['user_id' => (int) $row['user_id']]);
        return ['ok' => true];
    }

    /**
     * POST /auth/resend-verification. Always {ok:true} when mail works —
     * 503 MAIL_NOT_CONFIGURED when it does not (dev gets dev_preview).
     *
     * @param array<string,mixed>|null $authUser populated when called logged-in
     * @return array<string,mixed>
     */
    public function resendVerification(?array $authUser, ?string $email): array
    {
        $user = $authUser;
        if ($user === null && $email !== null) {
            $user = $this->users->findByEmail($email);
        }

        if (!$this->mailer->isConfigured()) {
            // Uniform for every address (transport state, not account state) —
            // cannot be used for enumeration.
            $extra = [];
            if ($user !== null) {
                $preview = $this->mailer->devPreview($this->buildVerificationMail($user)['meta']);
                if ($preview !== null) {
                    $extra['dev_preview'] = $preview;
                }
            }
            throw new ApiException(
                'MAIL_NOT_CONFIGURED',
                'E-mail delivery is not configured on this server.',
                503,
                $extra,
            );
        }

        if ($user !== null && !(bool) $user['email_verified']) {
            $this->sendVerificationMail($user, 'verify');
        }
        return ['ok' => true];
    }

    /**
     * POST /auth/forgot-password -> always {ok:true} (enumeration-safe).
     * Development-only dev_preview surfaces the token when no transport exists.
     *
     * @return array<string,mixed>
     */
    public function forgotPassword(string $email): array
    {
        $user = $this->users->findByEmail(UserRepository::normalizeEmail($email));
        if ($user === null) {
            return ['ok' => true];
        }

        if (!$this->mailer->isConfigured()) {
            $preview = $this->mailer->devPreview($this->buildPasswordResetMail($user)['meta']);
            Log::event('mail.not_configured', ['flow' => 'forgot_password', 'user_id' => (int) $user['id']]);
            return $preview === null ? ['ok' => true] : ['ok' => true, 'dev_preview' => $preview];
        }

        $this->sendPasswordResetMail($user);
        return ['ok' => true];
    }

    /**
     * POST /auth/reset-password -> {ok:true}; invalid/expired token is the
     * same generic TOKEN_INVALID; success revokes every session.
     *
     * @return array<string,mixed>
     */
    public function resetPassword(string $token, string $password): array
    {
        if (!Validator::passwordAcceptable($password)) {
            throw self::invalid(['password' => 'This password is too weak.']);
        }

        $invalid = new ApiException(
            'TOKEN_INVALID',
            'This reset link is invalid or has expired.',
            401,
        );

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw $invalid;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM password_reset_tokens WHERE token_hash = ?');
        $stmt->execute([TokenService::hash($token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row['used_at'] !== null
            || strcmp((string) $row['expires_at'], Clock::sqlNow()) <= 0) {
            throw $invalid;
        }

        $now = Clock::sqlNow();
        $upd = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $upd->execute([$now, $row['id']]);
        if ($upd->rowCount() === 0) {
            throw $invalid;
        }

        $userId = (int) $row['user_id'];
        $this->users->updatePassword($userId, PasswordService::hash($password));
        $clear = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
        );
        $clear->execute([$now, $userId]);
        // docs/API.md §1: a password reset revokes ALL sessions.
        $this->tokens->revokeAllForUser($userId, 'password_reset');

        Log::event('auth.password_reset', ['user_id' => $userId]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ mail

    /**
     * Create a fresh single-use verification token and e-mail it.
     * Mail failures are swallowed (logged): registration must not fail because
     * of an unavailable transport; /resend reports 503 explicitly instead.
     *
     * @param array<string,mixed> $userRow
     */
    private function sendVerificationMail(array $userRow): void
    {
        try {
            $built = $this->buildVerificationMail($userRow);
            $this->mailer->send($built['to'], $built['subject'], $built['body'], $built['meta']);
        } catch (\Throwable $e) {
            Log::exception($e, ['flow' => 'verify_mail']);
        }
    }

    /**
     * @param array<string,mixed> $userRow
     * @return array{to:string, subject:string, body:string, meta:array<string,mixed>}
     */
    private function buildVerificationMail(array $userRow): array
    {
        $userId = (int) $userRow['id'];
        $token = TokenService::randomHex();
        $ttl = (int) config('tokens.email_verify_ttl', 86400);
        $expiresAt = Clock::sql(Clock::now()->modify("+{$ttl} seconds"));

        // Invalidate any previous outstanding token: one valid link at a time.
        $clear = $this->pdo->prepare(
            'UPDATE email_verification_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
        );
        $clear->execute([Clock::sqlNow(), $userId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$userId, TokenService::hash($token), $expiresAt, Clock::sqlNow()]);

        $link = rtrim((string) config('app.url'), '/') . '/verify-email?token=' . $token;
        $displayName = (string) $userRow['display_name'];

        return [
            'to' => (string) $userRow['email'],
            'subject' => 'Verify your e-mail — Checkmate',
            'body' => "Hi {$displayName},\n\n"
                . "Confirm your e-mail address to activate your Checkmate account:\n\n"
                . "{$link}\n\n"
                . "Or enter this code manually: {$token}\n"
                . "It expires in 24 hours and can be used once.\n\n"
                . "If you did not create this account, ignore this message.\n",
            'meta' => ['kind' => 'verify', 'user_id' => $userId, 'token' => $token, 'expires_at' => $expiresAt],
        ];
    }

    /** @param array<string,mixed> $userRow */
    private function sendPasswordResetMail(array $userRow): void
    {
        try {
            $built = $this->buildPasswordResetMail($userRow);
            $this->mailer->send($built['to'], $built['subject'], $built['body'], $built['meta']);
        } catch (\Throwable $e) {
            Log::exception($e, ['flow' => 'reset_mail']);
        }
    }

    /**
     * @param array<string,mixed> $userRow
     * @return array{to:string, subject:string, body:string, meta:array<string,mixed>}
     */
    private function buildPasswordResetMail(array $userRow): array
    {
        $userId = (int) $userRow['id'];
        $token = TokenService::randomHex();
        $ttl = (int) config('tokens.password_reset_ttl', 3600);
        $expiresAt = Clock::sql(Clock::now()->modify("+{$ttl} seconds"));

        $clear = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
        );
        $clear->execute([Clock::sqlNow(), $userId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$userId, TokenService::hash($token), $expiresAt, Clock::sqlNow()]);

        $link = rtrim((string) config('app.url'), '/') . '/reset-password?token=' . $token;
        $displayName = (string) $userRow['display_name'];

        return [
            'to' => (string) $userRow['email'],
            'subject' => 'Reset your password — Checkmate',
            'body' => "Hi {$displayName},\n\n"
                . "Choose a new password for your Checkmate account:\n\n"
                . "{$link}\n\n"
                . "Or enter this code manually: {$token}\n"
                . "It expires in 1 hour and can be used once. "
                . "Using it signs out every device.\n\n"
                . "If you did not request this, ignore this message — your password stays unchanged.\n",
            'meta' => ['kind' => 'reset', 'user_id' => $userId, 'token' => $token, 'expires_at' => $expiresAt],
        ];
    }

    /** @param array<string,string> $fields */
    private static function invalid(array $fields): ApiException
    {
        return new ApiException(
            'VALIDATION_ERROR',
            'The submitted request is invalid.',
            400,
            ['fields' => $fields],
        );
    }
}
