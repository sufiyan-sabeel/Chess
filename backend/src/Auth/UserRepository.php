<?php

declare(strict_types=1);

namespace Checkmate\Auth;

use Checkmate\Support\Clock;

/**
 * Users table access. E-mails are stored lowercased+trimmed (single form) so
 * the unique index is the enumeration/consistency backstop; lookups are always
 * done with the normalised value.
 */
final class UserRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([self::normalizeEmail($email)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @throws \PDOException 23000 when the e-mail already exists
     * @return int new user id
     */
    public function create(string $email, string $passwordHash, string $displayName): int
    {
        $now = Clock::sqlNow();
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, email_verified, avatar_seed, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        );
        $stmt->execute([
            self::normalizeEmail($email),
            $passwordHash,
            $displayName,
            bin2hex(random_bytes(8)),
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markVerified(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET email_verified = 1, updated_at = ? WHERE id = ?');
        $stmt->execute([Clock::sqlNow(), $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$passwordHash, Clock::sqlNow(), $id]);
    }

    /**
     * Ratings summary for /auth/me: every mode always present, missing rows
     * fall back to the initial rating (docs/API.md §1 example shows 1200/0).
     *
     * Storage lives in `player_ratings` (migration 0002_ratings_matches,
     * shipped by the concurrent agent; one row per user+mode).
     *
     * @return array<string, array{rating:int, games:int}>
     */
    public function ratings(int $userId): array
    {
        $modes = ['bullet', 'blitz', 'rapid', 'classical'];
        $out = [];
        foreach ($modes as $mode) {
            $out[$mode] = ['rating' => 1200, 'games' => 0];
        }

        $stmt = $this->pdo->prepare('SELECT mode, rating, games FROM player_ratings WHERE user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['mode']] = ['rating' => (int) $row['rating'], 'games' => (int) $row['games']];
        }
        return $out;
    }

    /**
     * Public user object (docs/API.md register/login).
     *
     * @param array<string,mixed> $row users table row
     * @return array<string,mixed>
     */
    public static function toPublic(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'email_verified' => (bool) $row['email_verified'],
            'created_at' => Clock::iso(Clock::fromSql((string) $row['created_at'])),
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
