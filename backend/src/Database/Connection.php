<?php

declare(strict_types=1);

namespace Checkmate\Database;

use PDO;

/**
 * PDO connection singleton (least-privilege `checkmate` user, no root).
 */
final class Connection
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = config('db');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // real server-side prepared statements
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if (!empty($db['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $db['socket'], $db['name'], $db['charset']);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
        }

        self::$pdo = new PDO($dsn, $db['user'], $db['password'], $options);
        return self::$pdo;
    }

    /** Used by /ready. */
    public static function ping(): bool
    {
        try {
            self::pdo()->query('SELECT 1')->fetchColumn();
            return true;
        } catch (\Throwable) {
            self::$pdo = null;
            return false;
        }
    }

    /** Test/CLI helper: drop the cached handle (next call reconnects). */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
