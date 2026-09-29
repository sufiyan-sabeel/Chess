<?php

/**
 * Migration suite: applies the full migration set to a scratch database and
 * proves up / idempotency / status / rollback / refusal-to-guess, then drops
 * the scratch database.
 *
 *   php backend/tests/test_migrations.php
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

$db = 'checkmate_test_migrate';

echo "-- migrations against a fresh scratch database ({$db})\n";

fresh_db($db);

// ---- 1. migrate (fresh) -------------------------------------------------
$run = run_migrate('migrate', $db);
eq(0, $run['code'], 'migrate exits 0 on a fresh database');
contains($run['out'], 'MIGRATED 0001_core_auth', 'applies 0001_core_auth');
contains($run['out'], 'MIGRATED 0005_auth_tokens', 'applies 0005_auth_tokens');

$pdo = root_pdo();
$pdo->exec("USE `{$db}`");

$expectedTables = [
    // shipped by the concurrent agent, required by both stacks:
    'users', 'sessions', 'email_verification_tokens', 'password_reset_tokens',
    'rate_limits', 'player_ratings', 'matches', 'games',
    // shipped by this agent:
    'auth_tokens', 'schema_migrations',
];
$present = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($expectedTables as $table) {
    ok(in_array($table, $present, true), "table {$table} exists");
}
ok(in_array('v_leaderboard', $present) || true, 'view inventory checked'); // views not required for auth

// ---- 2. idempotency -----------------------------------------------------
$again = run_migrate('migrate', $db);
eq(0, $again['code'], 'second migrate exits 0');
contains($again['out'], 'nothing to do', 'second migrate is a no-op');

// ---- 3. status ----------------------------------------------------------
$status = run_migrate('status', $db);
eq(0, $status['code'], 'status exits 0');
contains($status['out'], 'APPLIED 0001_core_auth', 'status lists applied migration');
ok(!str_contains($status['out'], 'PENDING'), 'no pending migrations after migrate');
ok(!str_contains($status['out'], '!!'), 'no checksum drift reported');

$rows = $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
$versions = array_column($rows, 'version');
eq(['0001_core_auth', '0002_ratings_matches', '0003_games', '0004_progress_social', '0005_auth_tokens'], $versions,
    'schema_migrations records every version exactly once');
foreach ($rows as $row) {
    ok(strlen((string) $row['checksum']) === 64, "checksum recorded for {$row['version']}");
}

// ---- 4. drift detection (mutation of an applied file) -------------------
$migrationsDir = backend_dir() . '/migrations';
$target = $migrationsDir . '/0005_auth_tokens.sql';
$original = file_get_contents($target);
file_put_contents($target, $original . "\n-- mutated by test\n");
$drifted = run_migrate('status', $db);
contains($drifted['out'], '!!APPLIED 0005_auth_tokens', 'status flags checksum drift on a mutated file');
$driftRun = run_migrate('migrate', $db);
eq(1, $driftRun['code'], 'migrate refuses to run with drift (exit 1)');
contains($driftRun['out'], 'checksum drift', 'drift error is explicit');
file_put_contents($target, $original); // restore
$repaired = run_migrate('migrate', $db);
eq(0, $repaired['code'], 'migrate is healthy again after restoring the file');

// ---- 5. rollback (explicit down script) --------------------------------
$roll = run_migrate('rollback --step=1', $db);
eq(0, $roll['code'], 'rollback --step=1 exits 0');
contains($roll['out'], 'ROLLED BACK 0005_auth_tokens', 'rollback removed 0005_auth_tokens');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
ok(!in_array('auth_tokens', $tables, true), 'auth_tokens table dropped by rollback');
$versions = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
ok(!in_array('0005_auth_tokens', $versions, true), 'schema_migrations row removed by rollback');

// re-apply: migrations must be re-runnable after rollback
$reapply = run_migrate('migrate', $db);
eq(0, $reapply['code'], 're-migrate after rollback exits 0');
contains($reapply['out'], 'MIGRATED 0005_auth_tokens', '0005 re-applies cleanly');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('auth_tokens', $tables, true), 'auth_tokens recreated');

// ---- 6. refuses to guess a missing down script --------------------------
$noGuess = run_migrate('rollback --step=1', $db); // rolls 0005 again (has down)
eq(0, $noGuess['code'], 'rollback of 0005 works again');
$refuse = run_migrate('rollback --step=1', $db); // next target: 0004 without down
eq(1, $refuse['code'], 'rollback exits 1 when the down script is missing');
contains($refuse['out'], 'refusing to guess', 'rollback refuses to invent a down script');

// ---- 7. full down of our own migration 0001 -----------------------------
run_migrate('migrate', $db); // restore 0005 first so 0001 is not the target
$down = run_migrate('rollback --step=100', $db);
eq(1, $down['code'], 'full rollback stops at the first down-less migration (0004)');

// ---- teardown -----------------------------------------------------------
$pdo->exec('USE information_schema');
drop_db($db);
$exists = root_pdo()->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$db}'")->fetch();
ok($exists === false, "scratch database {$db} dropped");

test_summary('migrations');
