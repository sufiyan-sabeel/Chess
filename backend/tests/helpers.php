<?php

/**
 * Shared test helpers for the auth/http/db suite. Plain PHP — no frameworks.
 *
 * Every test file:  require __DIR__ . '/helpers.php';
 *                   ... cases ...  test_summary('name');
 *
 * Environment assumptions:
 *   - the private MariaDB is running (backend/var/run/mysqld.sock, root via
 *     socket needs no password — the same way bin/lib.sh talks to it);
 *   - PHP 8 CLI available as `php`.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ------------------------------------------------------------ assertions

$GLOBALS['__test_pass'] = 0;
$GLOBALS['__test_fail'] = 0;

function ok(bool $cond, string $message): void
{
    if ($cond) {
        $GLOBALS['__test_pass']++;
        echo "  ok    {$message}\n";
    } else {
        $GLOBALS['__test_fail']++;
        echo "  FAIL  {$message}\n";
    }
}

function eq(mixed $expected, mixed $actual, string $message): void
{
    $detail = '';
    if ($expected !== $actual) {
        $detail = sprintf(
            ' (expected %s, got %s)',
            var_export($expected, true),
            var_export($actual, true),
        );
    }
    ok($expected === $actual, $message . $detail);
}

function contains(string $haystack, string $needle, string $message): void
{
    ok(str_contains($haystack, $needle), $message . (str_contains($haystack, $needle) ? '' : " (missing {$needle})"));
}

function test_summary(string $name): never
{
    printf(
        "== %s: %d passed, %d failed ==\n",
        $name,
        $GLOBALS['__test_pass'],
        $GLOBALS['__test_fail'],
    );
    exit($GLOBALS['__test_fail'] > 0 ? 1 : 0);
}

// ------------------------------------------------------------ environment

function backend_dir(): string
{
    return dirname(__DIR__); // backend/tests -> backend
}

function db_socket(): string
{
    return backend_dir() . '/var/run/mysqld.sock';
}

/** Root over the local socket — used ONLY to create/drop scratch test DBs. */
function root_pdo(): PDO
{
    return new PDO(
        'mysql:unix_socket=' . db_socket(),
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );
}

/**
 * Environment for subprocesses (migrate CLI, built-in server): process env
 * wins over backend/.env (config.php precedence), pointing at the scratch DB
 * and the passwordless root socket.
 *
 * @param array<string,string> $overrides
 * @return array<string,string>
 */
function test_env(array $overrides = []): array
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['DB_HOST'] = '';
    $env['DB_PORT'] = '0';
    $env['DB_SOCKET'] = db_socket();
    $env['DB_USER'] = 'root';
    $env['DB_PASSWORD'] = '';
    $env['APP_ENV'] = 'development';
    $env['MAIL_TRANSPORT'] = 'file';
    return $env + $overrides;
}

/** Drop-if-exists + create a scratch database. Returns the new DB name. */
function fresh_db(string $name): string
{
    $pdo = root_pdo();
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
    $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $name;
}

function drop_db(string $name): void
{
    root_pdo()->exec("DROP DATABASE IF EXISTS `{$name}`");
}

/**
 * Run bin/migrate.php against a database.
 *
 * @param array<string,string> $overrides
 * @return array{code:int, out:string}
 */
function run_migrate(string $args, string $dbName, array $overrides = []): array
{
    $cmd = [PHP_BINARY, backend_dir() . '/bin/migrate.php'] ;
    // split args like "status" / "rollback --step=1"
    foreach (preg_split('/\s+/', $args) ?: [] as $arg) {
        if ($arg !== '') {
            $cmd[] = $arg;
        }
    }
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, test_env(['DB_NAME' => $dbName] + $overrides));
    if (!is_resource($proc)) {
        throw new RuntimeException('failed to start migrate.php');
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($proc), 'out' => $out];
}

// ------------------------------------------------------------ front controller pinning

/**
 * The concurrently-developed stack overwrites public/index.php from
 * bin/front-controller.php on every bin/dev.sh run; we pin the entry point
 * WE own (public/index.php -> src/Http/front-controller.php) before starting
 * the test server so the suite exercises the auth/http/db kernel no matter
 * who wrote last. Both copies are byte-identical and location-independent.
 */
function pin_front_controller(): void
{
    $canonical = backend_dir() . '/src/Http/front-controller.php';
    $live = backend_dir() . '/public/index.php';
    if (!is_file($canonical)) {
        throw new RuntimeException('canonical front controller missing: ' . $canonical);
    }
    $want = file_get_contents($canonical);
    $have = is_file($live) ? file_get_contents($live) : null;
    if ($have !== $want) {
        if (file_put_contents($live, $want) === false) {
            throw new RuntimeException('cannot pin public/index.php');
        }
        echo "  pinned public/index.php to the auth/http/db front controller\n";
    }
}

// ------------------------------------------------------------ HTTP

/**
 * @param array<string,mixed> $opts keys: base, headers (list), body (array|string), json (bool default true)
 * @return array{status:int, headers:array<string,string>, body:string, json:?array, ms:float}
 */
function http(string $method, string $path, array $opts = []): array
{
    $base = $opts['base'] ?? 'http://127.0.0.1:8099';
    $url = rtrim($base, '/') . $path;

    $headers = $opts['headers'] ?? [];
    $body = $opts['body'] ?? null;
    if (is_array($body)) {
        $body = json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($body !== null && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $start = hrtime(true);
    $raw = curl_exec($ch);
    $ms = (hrtime(true) - $start) / 1e6;
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP {$method} {$path} failed: {$error}");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $headerBlockEnd = strpos($raw, "\r\n\r\n");
    $headerBlock = $headerBlockEnd === false ? '' : substr($raw, 0, $headerBlockEnd);
    $content = $headerBlockEnd === false ? '' : substr($raw, $headerBlockEnd + 4);

    $map = [];
    $currentBlock = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (preg_match('#^HTTP/\d\.\d\s+\d+#', $line)) {
            $currentBlock = []; // ignore 1xx/redirect intermediates
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $currentBlock[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        }
    }
    $map = $currentBlock;

    return [
        'status' => $status,
        'headers' => $map,
        'body' => $content,
        'json' => is_array(json_decode($content, true)) ? json_decode($content, true) : null,
        'ms' => $ms,
    ];
}

/** Envelope assertion: success flag + optional error code/status. */
function assert_envelope(array $res, bool $success, ?string $errorCode, string $label): void
{
    $json = $res['json'];
    ok(is_array($json), "{$label}: JSON body parses");
    if (!is_array($json)) {
        return;
    }
    ok(array_key_exists('success', $json) && array_key_exists('data', $json) && array_key_exists('error', $json),
        "{$label}: envelope has success/data/error");
    eq($success, $json['success'], "{$label}: success flag");
    if ($errorCode === null) {
        ok($json['error'] === null, "{$label}: error is null");
    } else {
        ok(is_array($json['error']), "{$label}: error object present");
        eq($errorCode, $json['error']['code'] ?? null, "{$label}: error code");
    }
}

// ------------------------------------------------------------ server

/**
 * Start `php -S 127.0.0.1:<port> -t backend/public` (no router argument —
 * the built-in server falls back to index.php, which we pinned first).
 *
 * @param array<string,string> $envOverrides
 * @return array{proc:resource, pid:int}
 */
function server_start(int $port, array $envOverrides = []): array
{
    pin_front_controller();

    $cmd = [
        PHP_BINARY,
        '-S', "127.0.0.1:{$port}",
        '-t', backend_dir() . '/public',
    ];
    $logPath = sys_get_temp_dir() . "/checkmate-test-server-{$port}.log";
    $log = fopen($logPath, 'w');
    if ($log === false) {
        throw new RuntimeException('cannot open server log');
    }
    $proc = proc_open($cmd, [1 => $log, 2 => $log], $pipes, backend_dir(), test_env($envOverrides));
    if (!is_resource($proc)) {
        throw new RuntimeException('failed to start php -S');
    }
    fclose($log);
    return ['proc' => $proc, 'pid' => 0, 'log' => $logPath];
}

function server_wait(int $port, int $tries = 40): void
{
    for ($i = 0; $i < $tries; $i++) {
        try {
            $res = http('GET', "/api/v1/health", ['base' => "http://127.0.0.1:{$port}"]);
            if ($res['status'] === 200) {
                return;
            }
        } catch (Throwable) {
            // not up yet
        }
        usleep(250000);
    }
    throw new RuntimeException("test server on :{$port} did not become ready");
}

function server_stop(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        $status = proc_get_status($server['proc']);
        if (!empty($status['running'])) {
            // SIGTERM the whole process group entry (php -S has no children).
            proc_terminate($server['proc']);
            usleep(300000);
        }
        proc_close($server['proc']);
    }
}

// ------------------------------------------------------------ mail log

/**
 * Read backend/var/mail.log (FileMailer JSONL) and return the token of the
 * newest message addressed to $to (dev-only retrieval path).
 */
function mail_token_for(string $to, string $kind = 'verify'): ?string
{
    $path = backend_dir() . '/var/mail.log';
    if (!is_file($path)) {
        return null;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return null;
    }
    $found = null;
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || ($row['to'] ?? null) !== $to) {
            continue;
        }
        if (($row['kind'] ?? null) !== $kind) {
            continue;
        }
        if (is_string($row['token'] ?? null)) {
            $found = $row['token'];
        }
    }
    return $found;
}
