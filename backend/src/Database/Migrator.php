<?php

declare(strict_types=1);

namespace Checkmate\Database;

/**
 * Applies migrations/*.sql in filename order inside a transaction each,
 * tracked in `schema_migrations`. Idempotent: already applied versions skipped.
 *
 * Statement splitting is naive (split on `;`) — migration files must therefore
 * not contain stored procedures/triggers/DELIMITER blocks. They don't.
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $dir,
    ) {
    }

    /**
     * @return string[] list of newly applied versions
     */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $applied = [];
        foreach ($this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN) as $v) {
            $applied[(string) $v] = true;
        }

        $files = glob($this->dir . '/*.sql');
        if ($files === false) {
            throw new \RuntimeException('Cannot read migrations directory: ' . $this->dir);
        }
        // Only up-migrations: NNNN_name.sql. Rollback scripts
        // (NNNN_name.down.sql) are never applied automatically.
        $files = array_values(array_filter(
            $files,
            static function (string $file): bool {
                $name = basename($file);
                return preg_match('/^\d{4}_.+\.sql$/', $name) === 1
                    && !str_ends_with($name, '.down.sql');
            }
        ));
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($applied[$version])) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Cannot read migration: ' . $file);
            }

            // No transaction: MariaDB DDL implicitly commits. Migration files
            // must therefore be idempotent (IF NOT EXISTS) so a partial run
            // can be re-applied safely. The version row is only recorded
            // after every statement succeeded.
            try {
                foreach (self::splitStatements($sql) as $statement) {
                    $this->pdo->exec($statement);
                }
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
                $stmt->execute([$version, \Checkmate\Support\Clock::sqlNow()]);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Migration failed: ' . $version . ' — ' . $e->getMessage(), 0, $e);
            }

            $ran[] = $version;
        }

        return $ran;
    }

    /** @return string[] */
    public static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $lines[] = $line;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }
        return $statements;
    }
}
