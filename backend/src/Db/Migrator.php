<?php

declare(strict_types=1);

namespace Checkmate\Db;

use Checkmate\Support\Clock;

/**
 * File-based migration runner.
 *
 * Files live in backend/migrations and are named `NNNN_name.sql` (up) with an
 * optional `NNNN_name.down.sql` (explicit rollback). Applied files are recorded
 * in `schema_migrations` together with a SHA-256 checksum:
 *
 *   - re-running `migrate` is idempotent (applied files are skipped);
 *   - a file edited after being applied is reported as "drift" by `status`
 *     (never silently re-run);
 *   - `rollback` requires an explicit down script and refuses to guess.
 *
 * INTEROP: a concurrently-shipped migrator (Checkmate\Database\Migrator) writes
 * the same `schema_migrations` table with version keys *without* the .sql
 * suffix and without checksum columns. This runner therefore:
 *   - stores versions without the .sql suffix too,
 *   - ALTERs in any missing columns (checksum defaults to '' = unknown),
 *   - adopts rows recorded by the other runner (fills the checksum on first
 *     sight instead of flagging drift).
 *
 * All migration SQL must be idempotent itself (IF NOT EXISTS / CREATE OR
 * REPLACE) because MariaDB auto-commits DDL: a crash between statements can
 * leave a file partially applied, and a re-run then has to be safe.
 */
final class Migrator
{
    /** @var list<array{name:string, version:string, down:?string}> */
    private array $files = [];

    private bool $loaded = false;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $dir,
    ) {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version     VARCHAR(191) NOT NULL,
                checksum    CHAR(64)     NOT NULL DEFAULT \'\',
                applied_at  DATETIME     NOT NULL,
                duration_ms INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (version)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Add columns the other migrator's table may lack, in dependency order.
        $have = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM schema_migrations') as $column) {
            $have[(string) $column['Field']] = true;
        }
        $missing = [];
        if (!isset($have['checksum'])) {
            $missing[] = "ADD COLUMN checksum CHAR(64) NOT NULL DEFAULT '' AFTER version";
        }
        if (!isset($have['applied_at'])) {
            $missing[] = "ADD COLUMN applied_at DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00' AFTER version";
        }
        if (!isset($have['duration_ms'])) {
            $missing[] = 'ADD COLUMN duration_ms INT NOT NULL DEFAULT 0 AFTER applied_at';
        }
        if ($missing !== []) {
            $this->pdo->exec('ALTER TABLE schema_migrations ' . implode(', ', $missing));
        }
    }

    /** @return list<array{name:string, version:string, down:?string}> */
    public function files(): array
    {
        if ($this->loaded) {
            return $this->files;
        }
        $this->loaded = true;

        $paths = glob(rtrim($this->dir, '/') . '/*.sql');
        if ($paths === false) {
            return $this->files;
        }
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $base = basename($path);
            if (str_ends_with($base, '.down.sql')) {
                continue; // rollback scripts are paired with their up file below
            }
            if (!preg_match('/^\d{4}_[A-Za-z0-9_]+\.sql$/', $base)) {
                continue;
            }
            $downPath = substr($path, 0, -4) . '.down.sql';
            $this->files[] = [
                'name' => $base,
                'version' => substr($base, 0, -4),
                'down' => is_file($downPath) ? $downPath : null,
            ];
        }
        return $this->files;
    }

    /** @return array<string,string> version => checksum ('' = recorded without one) */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        /** @var array<string,string> $rows */
        return $rows;
    }

    /**
     * Apply every pending migration in order.
     *
     * @return list<array{name:string, statements:int, duration_ms:int}>
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $ran = [];

        foreach ($this->files() as $file) {
            $version = $file['version'];
            $path = rtrim($this->dir, '/') . '/' . $file['name'];
            $checksum = self::checksum($path);

            if (array_key_exists($version, $applied)) {
                $recorded = $applied[$version];
                if ($recorded === '') {
                    // Recorded by the other runner without a checksum: adopt it.
                    $upd = $this->pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE version = ?');
                    $upd->execute([$checksum, $version]);
                    continue;
                }
                if ($recorded !== $checksum) {
                    throw new \RuntimeException(
                        "Migration {$version} was modified after it was applied (checksum drift). "
                        . 'Add a new migration instead of editing an applied one.'
                    );
                }
                continue;
            }

            $sql = self::read($path);
            $statements = self::splitStatements($sql);
            $start = microtime(true);

            foreach ($statements as $i => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException $e) {
                    throw new \RuntimeException(
                        sprintf('Migration %s failed at statement #%d: %s', $version, $i + 1, $e->getMessage()),
                        0,
                        $e
                    );
                }
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $stmt = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version, checksum, applied_at, duration_ms) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$version, $checksum, Clock::sqlNow(), $durationMs]);
            $ran[] = ['name' => $file['name'], 'statements' => count($statements), 'duration_ms' => $durationMs];
        }

        return $ran;
    }

    /**
     * @return list<array{version:string, applied_at:string|null, checksum_ok:bool}>
     */
    public function status(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT version, checksum, applied_at FROM schema_migrations ORDER BY version')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $byVersion = [];
        foreach ($this->files() as $file) {
            $byVersion[$file['version']] = $file;
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $version = (string) $row['version'];
            $seen[$version] = true;
            $file = $byVersion[$version] ?? null;
            $recorded = (string) $row['checksum'];
            $ok = true;
            if ($recorded !== '' && $file !== null) {
                $ok = self::checksum(rtrim($this->dir, '/') . '/' . $file['name']) === $recorded;
            }
            $out[] = [
                'version' => $version,
                'applied_at' => (string) $row['applied_at'],
                'checksum_ok' => $ok,
            ];
        }

        foreach ($byVersion as $version => $file) {
            if (!isset($seen[$version])) {
                $out[] = ['version' => $version, 'applied_at' => null, 'checksum_ok' => true];
            }
        }
        return $out;
    }

    /**
     * Roll back the last $steps applied migrations (highest version first).
     * Only migrations with an explicit .down.sql script are reversible.
     *
     * @return list<string> rolled back migration versions
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new \InvalidArgumentException('--step must be >= 1');
        }
        $this->ensureTable();
        $byVersion = [];
        foreach ($this->files() as $file) {
            $byVersion[$file['version']] = $file;
        }

        $applied = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version DESC')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $rolled = [];
        foreach ($applied as $version) {
            $version = (string) $version;
            if (count($rolled) >= $steps) {
                break;
            }
            $file = $byVersion[$version] ?? null;
            if ($file === null) {
                throw new \RuntimeException("Applied migration {$version} has no matching file; cannot roll back.");
            }
            if ($file['down'] === null) {
                throw new \RuntimeException(
                    "Migration {$version} has no down script ({$version}.down.sql missing); refusing to guess."
                );
            }
            foreach (self::splitStatements(self::read($file['down'])) as $i => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException $e) {
                    throw new \RuntimeException(
                        sprintf('Rollback %s failed at statement #%d: %s', $version, $i + 1, $e->getMessage()),
                        0,
                        $e
                    );
                }
            }
            $del = $this->pdo->prepare('DELETE FROM schema_migrations WHERE version = ?');
            $del->execute([$version]);
            $rolled[] = $version;
        }
        return $rolled;
    }

    public static function checksum(string $path): string
    {
        $sql = self::read($path);
        return hash('sha256', str_replace("\r\n", "\n", $sql));
    }

    private static function read(string $path): string
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read migration file: {$path}");
        }
        return $sql;
    }

    /**
     * Split a SQL script into individual statements.
     *
     * Understands 'single quotes', "double quotes", `backtick identifiers`,
     * -- line comments (dash-dash followed by whitespace/EOL), # comments and
     * slash-star block comments. Statements without a trailing semicolon at
     * EOF are still returned. No DELIMITER handling (we never use triggers).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // line comments: '-- ' / "--\n" and '#'
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-'
                && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $length : $nl + 1;
                continue;
            }
            if ($char === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $length : $nl + 1;
                continue;
            }
            // block comment
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                $i++;
                while ($i < $length) {
                    $buffer .= $sql[$i];
                    if ($sql[$i] === '\\' && $quote !== '`' && $i + 1 < $length) {
                        $buffer .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $quote) {
                        // doubled quote == escaped quote
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $buffer .= $sql[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }
}
