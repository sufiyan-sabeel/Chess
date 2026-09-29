<?php

declare(strict_types=1);

namespace Checkmate\Services;

use Checkmate\Database\Connection;
use Checkmate\Support\Log;

/**
 * Fixed-window, DB-backed rate limiter (per IP + route, docs/API.md "Rate limits").
 * Table `rate_limits` — survives process restarts, no extra infrastructure.
 * Fails OPEN when the DB is unreachable: an outage must not lock users out.
 */
final class RateLimiter
{
    /**
     * @return array{allowed:bool, limit:int, remaining:int, retry_after:int, reset:int}
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $reset = $windowStart + $windowSeconds;

        try {
            $pdo = Connection::pdo();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT window_start, hits FROM rate_limits WHERE bucket = ? FOR UPDATE'
            );
            $stmt->execute([$bucket]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                $ins = $pdo->prepare(
                    'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)'
                );
                $ins->execute([$bucket, $windowStart]);
                $hits = 1;
            } elseif ((int) $row['window_start'] < $windowStart) {
                $upd = $pdo->prepare(
                    'UPDATE rate_limits SET window_start = ?, hits = 1 WHERE bucket = ?'
                );
                $upd->execute([$windowStart, $bucket]);
                $hits = 1;
            } else {
                $hits = (int) $row['hits'] + 1;
                $upd = $pdo->prepare(
                    'UPDATE rate_limits SET hits = ? WHERE bucket = ?'
                );
                $upd->execute([$hits, $bucket]);
            }

            $pdo->commit();
        } catch (\Throwable) {
            if (Connection::ping()) {
                try {
                    Connection::pdo()->rollBack();
                } catch (\Throwable) {
                }
            }
            Log::event('ratelimit.error', ['route' => explode('|', $bucket)[0]]);
            return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit, 'retry_after' => 0, 'reset' => $reset];
        }

        $allowed = $hits <= $limit;
        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'remaining' => max(0, $limit - $hits),
            'retry_after' => $allowed ? 0 : max(1, $reset - $now),
            'reset' => $reset,
        ];
    }

    /** Test hygiene: clear all counters. */
    public function clearAll(): void
    {
        Connection::pdo()->exec('DELETE FROM rate_limits');
    }

    /** Housekeeping: drop windows older than an hour. */
    public function purge(int $olderThanSeconds = 3600): void
    {
        $stmt = Connection::pdo()->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $stmt->execute([time() - $olderThanSeconds]);
    }
}
