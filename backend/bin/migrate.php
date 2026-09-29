<?php

/**
 * Migration CLI.
 *
 *   php backend/bin/migrate.php                 # apply pending (default; used by bin/lib.sh)
 *   php backend/bin/migrate.php migrate         # same, explicit
 *   php backend/bin/migrate.php status          # applied / pending / drift
 *   php backend/bin/migrate.php rollback [--step=1]
 *
 * Options:
 *   --db=NAME     override DB_NAME (tests use scratch databases)
 *   --dir=PATH    override migrations dir
 *   --step=N      rollback depth (default 1); rollback needs NNNN_*.down.sql
 *
 * Database credentials come from config.php (.env + process env). Creating the
 * database itself is NOT this tool's job: the target DB must already exist
 * (tests create scratch databases over the root socket).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Checkmate\Database\Connection;
use Checkmate\Db\Migrator;

$command = 'migrate';
$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $eq = strpos($arg, '=');
        $key = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
        $value = $eq === false ? '1' : substr($arg, $eq + 1);
        $options[$key] = $value;
    } else {
        $command = $arg;
    }
}

if (!in_array($command, ['migrate', 'status', 'rollback'], true)) {
    fwrite(STDERR, "Unknown command: {$command}\nUsage: migrate.php [migrate|status|rollback] [--step=N] [--db=NAME]\n");
    exit(2);
}

try {
    $pdo = Connection::pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cannot connect to the database: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Hint: is the local MariaDB running? (backend/bin/dev.sh starts it)\n");
    exit(1);
}

$migrationsDir = $options['dir'] ?? (string) config('paths.migrations', dirname(__DIR__) . '/migrations');
$step = max(1, (int) ($options['step'] ?? 1));
$migrator = new Migrator($pdo, $migrationsDir);

printf("database  : %s\n", (string) config('db.name', ''));
printf("migrations: %s\n\n", $migrationsDir);

try {
    switch ($command) {
        case 'status':
            foreach ($migrator->status() as $row) {
                if ($row['applied_at'] === null) {
                    printf("  PENDING  %s\n", $row['version']);
                } else {
                    $flag = $row['checksum_ok'] ? '  ' : '!!';
                    printf("  %sAPPLIED %s  at %s\n", $flag, $row['version'], $row['applied_at']);
                }
            }
            break;

        case 'migrate':
            $ran = $migrator->migrate();
            if ($ran === []) {
                echo "  nothing to do — database is up to date\n";
            }
            foreach ($ran as $row) {
                printf("  MIGRATED %s  (%d statements, %d ms)\n", basename($row['name'], '.sql'), $row['statements'], $row['duration_ms']);
            }
            break;

        case 'rollback':
            $rolled = $migrator->rollback($step);
            if ($rolled === []) {
                echo "  nothing to roll back\n";
            }
            foreach ($rolled as $version) {
                printf("  ROLLED BACK %s\n", $version);
            }
            break;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\ndone.\n";
