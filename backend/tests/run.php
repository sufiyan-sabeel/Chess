<?php

/**
 * Test runner: executes every suite in backend/tests and prints a PASS/FAIL
 * summary. Plain PHP, no dependencies.
 *
 *   php backend/tests/run.php            # run everything
 *   php backend/tests/run.php http       # run one suite (migrations|services|http)
 *
 * Exit code: 0 = all green, 1 = at least one suite failed.
 *
 * bin/test.sh (shipped by the concurrent agent) calls this exact file after
 * booting its own API server on :8081; this runner is self-contained and
 * boots its own server on :8099 when needed, so both entry points work.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/helpers.php';

$filter = $argv[1] ?? '';

$suites = [
    'migrations' => 'test_migrations.php',
    'services' => 'test_services.php',
    'http' => 'test_http.php',
];

echo "=== Checkmate backend test suite ===\n";
echo 'php: ' . PHP_VERSION . "\n";

// Pre-flight: is the private MariaDB up? Fail fast with a useful hint.
try {
    root_pdo()->query('SELECT 1');
    echo "db:   ok (" . db_socket() . ")\n";
} catch (Throwable $e) {
    echo "db:   UNREACHABLE — start it with backend/bin/dev.sh or backend/bin/test.sh\n";
    echo '      (' . $e->getMessage() . ")\n";
    echo "=== SUMMARY: 0 passed, 3 failed ===\n";
    exit(1);
}

$passed = [];
$failed = [];

foreach ($suites as $name => $file) {
    if ($filter !== '' && $filter !== $name) {
        continue;
    }
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        $failed[] = $name . ' (missing file)';
        echo "[FAIL] {$file} — file not found\n";
        continue;
    }

    echo "\n[ RUN ] tests/{$file}\n";
    $start = microtime(true);

    $proc = proc_open(
        [PHP_BINARY, $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
    );
    if (!is_resource($proc)) {
        $failed[] = $name;
        echo "[FAIL] {$file} — could not start\n";
        continue;
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $elapsed = microtime(true) - $start;

    echo $out;
    if (trim($err) !== '') {
        echo $err;
    }

    if ($code === 0) {
        $passed[] = $name;
        printf("[ OK ] tests/%s (%.1fs)\n", $file, $elapsed);
    } else {
        $failed[] = $name;
        printf("[FAIL] tests/%s (exit %d, %.1fs)\n", $file, $code, $elapsed);
    }
}

printf(
    "\n=== SUMMARY: %d passed, %d failed%s ===\n",
    count($passed),
    count($failed),
    $failed === [] ? ' — ALL GREEN' : ' — FAILED: ' . implode(', ', $failed),
);

exit($failed === [] ? 0 : 1);
