<?php

/**
 * Checkmate backend API test suite — custom runner (docs/API.md §1 + §2).
 *
 * No PHPUnit/Composer in this environment: tiny assert + registry + summary.
 * Run with:  backend/bin/test.sh   (boots DB, migrates, starts API, then this)
 * Exit code: 0 = all passed, 1 = at least one failure.
 *
 * Covers docs/API.md §1 (auth) and §2 (health) end-to-end over real HTTP
 * against the built-in server, plus DB-level assertions via PDO.
 *
 * NOTE: backend/tests/run.php + test_*.php belong to the concurrent agent's
 * parallel harness; this file is the suite wired into bin/test.sh.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Checkmate\Database\Connection;

const TEST_EMAIL_DOMAIN = 'example.test';

$BASE = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8081';
$SUF = bin2hex(random_bytes(4));
/** @var int[] $FIXTURE_MATCH_IDS matches inserted by tests, removed in cleanup */
$FIXTURE_MATCH_IDS = [];

// ---------------------------------------------------------------- HTTP client

/**
 * @param array<string,mixed>|null $body
 * @param array<string,string> $headers
 * @return array{status:int, json:?array, raw:string, headers:array<string,string>, time:float}
 */
function http(string $method, string $path, ?array $body = null, array $headers = [], ?string $origin = null): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $hdrs = ['Accept: application/json'];
    if ($body !== null) {
        $hdrs[] = 'Content-Type: application/json';
    }
    foreach ($headers as $k => $v) {
        $hdrs[] = $k . ': ' . $v;
    }
    if ($origin !== null) {
        $hdrs[] = 'Origin: ' . $origin;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        throw new RuntimeException("curl failed: {$err}");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    // curl_close() is deprecated in PHP 8.5 (no-op since 8.0) — drop the handle
    // by letting it go out of scope instead.

    $headerStr = substr($raw, 0, $headerSize);
    $bodyStr = substr($raw, $headerSize);
    $headerMap = [];
    foreach (explode("\r\n", $headerStr) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headerMap[strtolower(trim($k))] = trim($v);
        }
    }

    return [
        'status' => $status,
        'json' => json_decode($bodyStr, true),
        'raw' => $bodyStr,
        'headers' => $headerMap,
        'time' => $time,
    ];
}

// ------------------------------------------------------------------ assertions

final class AssertionFailed extends Exception
{
}

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}

function assert_same(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(
            $msg . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assert_status(array $res, int $code, string $msg): void
{
    assert_same($code, $res['status'], $msg . ' [body: ' . substr($res['raw'], 0, 300) . ']');
}

/** Assert the standard error envelope. */
function assert_error(array $res, int $status, string $code, string $msg): void
{
    assert_status($res, $status, $msg);
    assert_true(is_array($res['json']), $msg . ': body is JSON');
    assert_same(false, $res['json']['success'] ?? null, $msg . ': success flag');
    // array_key_exists, not ??: a legitimate null `data` must not read as "missing".
    assert_true(
        array_key_exists('data', $res['json']) && $res['json']['data'] === null,
        $msg . ': data must be present and null'
    );
    assert_same($code, $res['json']['error']['code'] ?? null, $msg . ': error code');
    assert_true(is_string($res['json']['error']['message'] ?? null), $msg . ': error message present');
}

// ---------------------------------------------------------------- test registry

/** @var array<int, array{0:string, 1:callable}> $TESTS */
$TESTS = [];

function test(string $name, callable $fn): void
{
    global $TESTS;
    $TESTS[] = [$name, $fn];
}

// ------------------------------------------------------------------ DB helpers

function db(): PDO
{
    return Connection::pdo();
}

function clear_rate_limits(): void
{
    db()->exec('DELETE FROM rate_limits');
}

function random_email(string $prefix): string
{
    global $SUF;
    return sprintf('%s-%s-%s@%s', $prefix, $SUF, bin2hex(random_bytes(3)), TEST_EMAIL_DOMAIN);
}

/** Register a user through the API; returns the 201 data payload. */
function register_user(string $email, string $password = 'Str0ng!Passw0rd', string $name = 'tester'): array
{
    $res = http('POST', '/api/v1/auth/register', [
        'email' => $email,
        'password' => $password,
        'display_name' => $name,
    ]);
    assert_status($res, 201, "register {$email}");
    return $res['json']['data'];
}

function login_user(string $email, string $password): array
{
    $res = http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    assert_status($res, 200, "login {$email}");
    return $res['json']['data'];
}

/** Extract a 43-char opaque token from a dev_preview mail body. */
function token_from_preview(string $preview): string
{
    assert_true(preg_match('/token=([A-Za-z0-9_-]{43})/', $preview, $m) === 1,
        'dev_preview contains a token link');
    return $m[1];
}

/** A syntactically valid but never-issued refresh/access token. */
function random_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

// ------------------------------------------------------ production-mode server
// A second server with APP_ENV=production proves the 503 MAIL_NOT_CONFIGURED
// behaviour (development uses the NullMailTransport preview instead).

/** @var array{proc:resource, port:int}|null $PROD_SERVER */
$PROD_SERVER = null;

function prod_base(): string
{
    global $PROD_SERVER;
    if ($PROD_SERVER !== null) {
        return 'http://127.0.0.1:' . $PROD_SERVER['port'];
    }

    $port = (int) (getenv('TEST_API_PORT') ?: 8081) + 11;
    $backend = dirname(__DIR__);
    $env = getenv(); // full environment as array
    $env['APP_ENV'] = 'production';
    $env['APP_DEBUG'] = 'false';

    $proc = proc_open(
        ['php', '-S', "127.0.0.1:{$port}", '-t', "{$backend}/public", "{$backend}/bin/front-controller.php"],
        [1 => ['file', "{$backend}/var/log/prod-server.log", 'a'], 2 => ['file', "{$backend}/var/log/prod-server.log", 'a']],
        $pipes,
        $backend,
        $env
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('cannot start production test server');
    }
    $PROD_SERVER = ['proc' => $proc, 'port' => $port];

    for ($i = 0; $i < 40; $i++) {
        $ch = curl_init("http://127.0.0.1:{$port}/api/v1/health");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        $ok = curl_exec($ch) !== false;
        if ($ok) {
            return 'http://127.0.0.1:' . $port;
        }
        usleep(250_000);
    }
    throw new RuntimeException('production test server did not come up');
}

/** Like http() but against the production-mode server. */
function http_prod(string $method, string $path, ?array $body = null): array
{
    $base = prod_base();
    $ch = curl_init($base . $path);
    $hdrs = ['Accept: application/json'];
    if ($body !== null) {
        $hdrs[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('curl failed: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $bodyStr = substr($raw, $headerSize);
    $headerMap = [];
    foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headerMap[strtolower(trim($k))] = trim($v);
        }
    }
    return [
        'status' => $status,
        'json' => json_decode($bodyStr, true),
        'raw' => $bodyStr,
        'headers' => $headerMap,
        'time' => 0.0,
    ];
}

function stop_prod_server(): void
{
    global $PROD_SERVER;
    if ($PROD_SERVER !== null) {
        proc_terminate($PROD_SERVER['proc']);
        proc_close($PROD_SERVER['proc']);
        $PROD_SERVER = null;
    }
}

// -------------------------------------------------------------------- cleanup

function cleanup(): void
{
    global $FIXTURE_MATCH_IDS;
    stop_prod_server();
    try {
        foreach ($FIXTURE_MATCH_IDS as $id) {
            $stmt = db()->prepare('DELETE FROM matches WHERE id = ?');
            $stmt->execute([$id]);
        }
        // Remove users created by this run (cascades tokens/ratings/sessions).
        db()->exec("DELETE FROM users WHERE email LIKE '%@" . TEST_EMAIL_DOMAIN . "'");
    } catch (Throwable $e) {
        fwrite(STDERR, "cleanup warning: {$e->getMessage()}\n");
    }
}

// Remove leftovers of a previous crashed run before testing anything.
function pre_clean(): void
{
    try {
        db()->exec("DELETE FROM users WHERE email LIKE '%@" . TEST_EMAIL_DOMAIN . "'");
        db()->exec('DELETE FROM rate_limits');
    } catch (Throwable $e) {
        fwrite(STDERR, "pre-clean warning: {$e->getMessage()}\n");
    }
}

// =========================================================== TEST DEFINITIONS
// --- Group A: health, security headers, CORS, request id, register ----------

test('health returns ok without touching auth', function () {
    $res = http('GET', '/api/v1/health');
    assert_status($res, 200, 'health 200');
    assert_same('ok', $res['json']['data']['status'] ?? null, 'health status');
    assert_true(isset($res['json']['data']['time'], $res['json']['data']['version']), 'health time+version');
    assert_same(true, $res['json']['success'] ?? null, 'envelope success');
});

test('ready reports DB reachability', function () {
    $res = http('GET', '/api/v1/ready');
    assert_status($res, 200, 'ready 200 when DB up');
    assert_same('ready', $res['json']['data']['status'] ?? null, 'ready status');
});

test('security headers present on every response', function () {
    $res = http('GET', '/api/v1/health');
    $h = $res['headers'];
    assert_same('nosniff', $h['x-content-type-options'] ?? null, 'X-Content-Type-Options');
    assert_same('DENY', $h['x-frame-options'] ?? null, 'X-Frame-Options');
    assert_true(isset($h['referrer-policy']), 'Referrer-Policy');
    assert_true(isset($h['cache-control']), 'Cache-Control');
    assert_same('no-store', $h['cache-control'] ?? null, 'Cache-Control no-store');
    assert_true(str_contains($h['content-type'] ?? '', 'application/json'), 'JSON content type');
    assert_true(!isset($h['x-powered-by']), 'X-Powered-By removed');
});

test('request id is echoed when provided and generated otherwise', function () {
    $res = http('GET', '/api/v1/health', null, ['X-Request-Id' => 'trace-abc.123']);
    assert_same('trace-abc.123', $res['headers']['x-request-id'] ?? null, 'echoed request id');

    $res2 = http('GET', '/api/v1/health');
    $id = $res2['headers']['x-request-id'] ?? '';
    assert_true(preg_match('/^[a-f0-9]{32}$/', $id) === 1, "generated request id (got '{$id}')");

    // Malformed incoming id is replaced, not echoed.
    $res3 = http('GET', '/api/v1/health', null, ['X-Request-Id' => 'bad id with spaces!!']);
    $id3 = $res3['headers']['x-request-id'] ?? '';
    assert_true(preg_match('/^[a-f0-9]{32}$/', $id3) === 1, 'malformed incoming id replaced');
});

test('CORS allows configured origin and rejects others', function () {
    $allowed = 'http://localhost:8080';

    $res = http('GET', '/api/v1/health', null, [], $allowed);
    assert_status($res, 200, 'allowed origin 200');
    assert_same($allowed, $res['headers']['access-control-allow-origin'] ?? null, 'ACAO echoed');
    assert_same('true', $res['headers']['access-control-allow-credentials'] ?? null, 'credentials allowed');
    assert_true(str_contains($res['headers']['vary'] ?? '', 'Origin'), 'Vary: Origin');

    $bad = http('GET', '/api/v1/health', null, [], 'https://evil.example');
    assert_error($bad, 403, 'FORBIDDEN', 'disallowed origin rejected');
    assert_true(!isset($bad['headers']['access-control-allow-origin']), 'no ACAO on rejection');

    $pre = http('OPTIONS', '/api/v1/auth/login', null, [], $allowed);
    assert_status($pre, 204, 'preflight 204');
    assert_true(isset($pre['headers']['access-control-allow-methods']), 'preflight allow-methods');
    assert_true(str_contains($pre['headers']['access-control-allow-headers'] ?? '', 'Authorization'), 'preflight allow-headers');

    $preBad = http('OPTIONS', '/api/v1/auth/login', null, [], 'https://evil.example');
    assert_error($preBad, 403, 'FORBIDDEN', 'preflight from disallowed origin rejected');
});

test('unknown route returns 404 NOT_FOUND envelope', function () {
    $res = http('GET', '/api/v1/nope');
    assert_error($res, 404, 'NOT_FOUND', 'unknown route');
    assert_true(isset($res['headers']['x-request-id']), 'request id on 404');
});

test('register creates user, ratings rows and opaque tokens', function () {
    clear_rate_limits();
    $email = random_email('reg-ok');
    $data = register_user($email, 'Str0ng!Passw0rd', 'umaiz_ok');

    assert_same(strtolower($email), $data['user']['email'] ?? null, 'email lowercased');
    assert_same('umaiz_ok', $data['user']['display_name'] ?? null, 'display name');
    assert_same(false, $data['user']['email_verified'] ?? null, 'not verified yet');
    assert_true(is_int($data['user']['id'] ?? 0), 'user id is int');
    assert_true(isset($data['user']['created_at']), 'created_at present');

    $ratings = $data['user']['ratings'] ?? [];
    foreach (['bullet', 'blitz', 'rapid', 'classical'] as $mode) {
        assert_true(isset($ratings[$mode]), "rating row for {$mode}");
        assert_same(1200, $ratings[$mode]['rating'] ?? null, "{$mode} starts at 1200");
        assert_same(0, $ratings[$mode]['games'] ?? null, "{$mode} zero games");
    }

    foreach (['access_token', 'refresh_token'] as $t) {
        assert_true(isset($data[$t]), "{$t} present");
        assert_true(preg_match('/^[A-Za-z0-9_-]{43}$/', $data[$t]) === 1, "{$t} is 43-char base64url");
    }
    assert_same(900, $data['expires_in'] ?? null, 'expires_in 900');

    // DB-level: password is hashed (bcrypt/argon2id), never stored plaintext.
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $hash = (string) $stmt->fetchColumn();
    assert_true(str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2id$'), 'stored hash is bcrypt/argon2id');
    assert_true(!str_contains($hash, 'Str0ng!Passw0rd'), 'plaintext password not stored');

    $stmt = db()->prepare('SELECT COUNT(*) FROM player_ratings WHERE user_id = (SELECT id FROM users WHERE email = ?)');
    $stmt->execute([$email]);
    assert_same('4', (string) $stmt->fetchColumn(), '4 player_ratings rows');
});

test('register rejects duplicate email with 409 EMAIL_TAKEN', function () {
    clear_rate_limits();
    $email = random_email('reg-dup');
    register_user($email);
    $res = http('POST', '/api/v1/auth/register', [
        'email' => strtoupper($email), // case-insensitive duplicate
        'password' => 'Str0ng!Passw0rd',
        'display_name' => 'other_name',
    ]);
    assert_error($res, 409, 'EMAIL_TAKEN', 'duplicate email');
});

test('register validation: invalid email, weak password, bad display name', function () {
    clear_rate_limits();
    $cases = [
        ['email' => 'not-an-email', 'password' => 'Str0ng!Passw0rd', 'display_name' => 'valid_name'],
        ['email' => random_email('no-digit'), 'password' => 'abcdefghij', 'display_name' => 'valid_name'],   // no digit
        ['email' => random_email('no-lett'), 'password' => '1234567890', 'display_name' => 'valid_name'],   // no letter
        ['email' => random_email('short'), 'password' => 'Ab1', 'display_name' => 'valid_name'],            // too short
        ['email' => random_email('dn-short'), 'password' => 'Str0ng!Passw0rd', 'display_name' => 'ab'],
        ['email' => random_email('dn-space'), 'password' => 'Str0ng!Passw0rd', 'display_name' => 'has space'],
        ['email' => random_email('dn-long'), 'password' => 'Str0ng!Passw0rd', 'display_name' => str_repeat('a', 21)],
        ['email' => random_email('dn-dash'), 'password' => 'Str0ng!Passw0rd', 'display_name' => 'bad-name!'],
    ];
    foreach ($cases as $i => $payload) {
        clear_rate_limits(); // rejected attempts still count; isolate the validation rules
        $res = http('POST', '/api/v1/auth/register', $payload);
        assert_error($res, 400, 'VALIDATION_ERROR', "register case #{$i} rejected");
    }
    clear_rate_limits();
});
// --- Group B: login, timing uniformity, me, refresh rotation, logout --------

test('login success returns tokens and user; me works with access token', function () {
    clear_rate_limits();
    $email = random_email('login-ok');
    register_user($email);

    $data = login_user($email, 'Str0ng!Passw0rd');
    assert_true(preg_match('/^[A-Za-z0-9_-]{43}$/', $data['access_token'] ?? '') === 1, 'access token format');
    assert_true(preg_match('/^[A-Za-z0-9_-]{43}$/', $data['refresh_token'] ?? '') === 1, 'refresh token format');
    assert_same(900, $data['expires_in'] ?? null, 'expires_in 900');
    assert_same(strtolower($email), $data['user']['email'] ?? null, 'login returns user');

    $me = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $data['access_token']]);
    assert_status($me, 200, 'me 200');
    assert_same(strtolower($email), $me['json']['data']['user']['email'] ?? null, 'me email');
    assert_same($data['user']['id'], $me['json']['data']['user']['id'] ?? null, 'me id matches');
    $ratings = $me['json']['data']['user']['ratings'] ?? [];
    foreach (['bullet', 'blitz', 'rapid', 'classical'] as $mode) {
        assert_true(isset($ratings[$mode]), "me exposes {$mode} rating");
    }
});

test('login failures are identical for unknown email and wrong password (>=100ms)', function () {
    clear_rate_limits();
    $email = random_email('timing');
    register_user($email);

    $wrong = http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'WrongPass123']);
    $unknown = http('POST', '/api/v1/auth/login', ['email' => random_email('ghost'), 'password' => 'WrongPass123']);

    assert_error($wrong, 401, 'INVALID_CREDENTIALS', 'wrong password');
    assert_error($unknown, 401, 'INVALID_CREDENTIALS', 'unknown email');
    assert_same($wrong['raw'], $unknown['raw'], 'byte-identical error bodies');
    assert_same($wrong['status'], $unknown['status'], 'identical status');
    assert_true($wrong['time'] >= 0.098, sprintf('wrong-password timing >=100ms (got %.3fs)', $wrong['time']));
    assert_true($unknown['time'] >= 0.098, sprintf('unknown-email timing >=100ms (got %.3fs)', $unknown['time']));
});

test('me without token is 401; with garbage bearer is 401', function () {
    $no = http('GET', '/api/v1/auth/me');
    assert_error($no, 401, 'UNAUTHORIZED', 'me without token');

    $garbage = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer not-a-real-token']);
    assert_error($garbage, 401, 'UNAUTHORIZED', 'me with garbage token');
});

test('refresh rotates tokens; old refresh token stops working', function () {
    clear_rate_limits();
    $email = random_email('refresh');
    $reg = register_user($email);
    $oldRefresh = $reg['refresh_token'];

    $r1 = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $oldRefresh]);
    assert_status($r1, 200, 'refresh 200');
    assert_true(preg_match('/^[A-Za-z0-9_-]{43}$/', $r1['json']['data']['access_token'] ?? '') === 1, 'rotated access');
    assert_true(preg_match('/^[A-Za-z0-9_-]{43}$/', $r1['json']['data']['refresh_token'] ?? '') === 1, 'rotated refresh');
    assert_same(900, $r1['json']['data']['expires_in'] ?? null, 'expires_in');

    // New access token works.
    $me = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $r1['json']['data']['access_token']]);
    assert_status($me, 200, 'rotated access token valid');

    // Old (used) refresh token is rejected.
    $reuse = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $oldRefresh]);
    assert_error($reuse, 401, 'TOKEN_INVALID', 'used refresh token rejected');
});

test('refresh reuse revokes the whole token family', function () {
    clear_rate_limits();
    $email = random_email('reuse');
    $reg = register_user($email);

    $r1 = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $reg['refresh_token']]);
    assert_status($r1, 200, 'first rotation ok');
    $newRefresh = $r1['json']['data']['refresh_token'];

    // Reuse of the already-used token => theft detection.
    $reuse = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $reg['refresh_token']]);
    assert_error($reuse, 401, 'TOKEN_INVALID', 'reuse rejected');

    // The legitimate rotated token must now ALSO be dead (family revoked).
    $after = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $newRefresh]);
    assert_error($after, 401, 'TOKEN_INVALID', 'family revoked after reuse');

    // And the current access token from r1 dies with the family.
    $me = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $r1['json']['data']['access_token']]);
    assert_error($me, 401, 'UNAUTHORIZED', 'access token revoked with family');
});

test('refresh with unknown or malformed token is 401 TOKEN_INVALID', function () {
    clear_rate_limits();
    $unknown = http('POST', '/api/v1/auth/refresh', ['refresh_token' => random_token()]);
    assert_error($unknown, 401, 'TOKEN_INVALID', 'unknown refresh token');

    // Malformed vs unknown must be indistinguishable — no token-format oracle
    // (docs/API.md §1 only specifies TOKEN_INVALID for refresh failures).
    $malformed = http('POST', '/api/v1/auth/refresh', ['refresh_token' => 'zzz']);
    assert_error($malformed, 401, 'TOKEN_INVALID', 'malformed refresh token');
    assert_same($unknown['raw'], $malformed['raw'], 'identical bodies for unknown vs malformed token');

    $missing = http('POST', '/api/v1/auth/refresh', []);
    assert_error($missing, 400, 'VALIDATION_ERROR', 'missing refresh token');
});

test('logout is idempotent and kills the session family', function () {
    clear_rate_limits();
    $email = random_email('logout');
    $reg = register_user($email);
    $access = $reg['access_token'];
    $refresh = $reg['refresh_token'];

    $out1 = http('POST', '/api/v1/auth/logout', ['refresh_token' => $refresh], ['Authorization' => 'Bearer ' . $access]);
    assert_status($out1, 200, 'logout first call');
    assert_same(true, $out1['json']['data']['ok'] ?? null, 'logout ok');

    // Idempotent: same call again still succeeds (access token now revoked
    // with the family, refresh token already revoked — docs/API.md §1).
    $out2 = http('POST', '/api/v1/auth/logout', ['refresh_token' => $refresh], ['Authorization' => 'Bearer ' . $access]);
    assert_status($out2, 200, 'logout second call still 200');
    assert_same(true, $out2['json']['data']['ok'] ?? null, 'logout still ok');

    // Refresh after logout: rejected.
    $re = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $refresh]);
    assert_error($re, 401, 'TOKEN_INVALID', 'refresh after logout rejected');

    // Access token after logout: rejected.
    $me = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $access]);
    assert_error($me, 401, 'UNAUTHORIZED', 'access after logout rejected');
});
// --- Group C: e-mail verification, resend, forgot/reset password ------------

test('verify-email: valid token verifies, then reuse is rejected', function () {
    clear_rate_limits();
    $email = random_email('verify');
    register_user($email);

    $res = http('POST', '/api/v1/auth/resend-verification', ['email' => $email]);
    assert_status($res, 200, 'resend 200');
    $preview = $res['json']['data']['dev_preview'] ?? null;
    assert_true(is_string($preview) && $preview !== '', 'dev_preview returned in development');
    $token = token_from_preview($preview);

    $ok = http('POST', '/api/v1/auth/verify-email', ['token' => $token]);
    assert_status($ok, 200, 'verify 200');
    assert_same(true, $ok['json']['data']['ok'] ?? null, 'verify ok');

    // DB says verified.
    $stmt = db()->prepare('SELECT email_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    assert_same('1', (string) $stmt->fetchColumn(), 'email_verified set in DB');

    // Reuse of the same token is rejected with TOKEN_INVALID.
    $again = http('POST', '/api/v1/auth/verify-email', ['token' => $token]);
    assert_error($again, 400, 'TOKEN_INVALID', 'reused verify token rejected');
});

test('verify-email: unknown and malformed tokens are TOKEN_INVALID, expired is TOKEN_EXPIRED', function () {
    clear_rate_limits();

    // Unknown but well-formed token (43-char base64url) -> TOKEN_INVALID.
    $unknown = http('POST', '/api/v1/auth/verify-email', ['token' => random_token()]);
    assert_error($unknown, 400, 'TOKEN_INVALID', 'unknown verify token');

    // Malformed token -> same TOKEN_INVALID shape (never NOT_FOUND).
    $malformed = http('POST', '/api/v1/auth/verify-email', ['token' => 'nope']);
    assert_error($malformed, 400, 'TOKEN_INVALID', 'malformed verify token');

    // Expired token -> TOKEN_EXPIRED.
    $email = random_email('verify-exp');
    register_user($email);
    $res = http('POST', '/api/v1/auth/resend-verification', ['email' => $email]);
    $token = token_from_preview($res['json']['data']['dev_preview'] ?? '');
    $stmt = db()->prepare("UPDATE email_verification_tokens SET expires_at = '2020-01-01 00:00:00' WHERE token_hash = ?");
    $stmt->execute([hash('sha256', $token)]);
    assert_same(1, $stmt->rowCount(), 'expiry backdated');

    $expired = http('POST', '/api/v1/auth/verify-email', ['token' => $token]);
    assert_error($expired, 400, 'TOKEN_EXPIRED', 'expired verify token');
});

test('resend-verification is generic 200 whether or not the email exists', function () {
    clear_rate_limits();
    $email = random_email('resend');
    register_user($email); // exists

    $known = http('POST', '/api/v1/auth/resend-verification', ['email' => $email]);
    $unknown = http('POST', '/api/v1/auth/resend-verification', ['email' => random_email('resend-ghost')]);

    assert_status($known, 200, 'resend known email 200');
    assert_status($unknown, 200, 'resend unknown email 200');
    assert_same(true, $known['json']['data']['ok'] ?? null, 'known: ok=true');
    assert_same(true, $unknown['json']['data']['ok'] ?? null, 'unknown: ok=true');
    // dev_preview only exists in development for an existing account; the
    // *status and ok flag* are identical, which is what production exposes.
    assert_true(!isset($unknown['json']['data']['dev_preview']), 'unknown email has no preview');

    // Authenticated variant needs no body.
    $reg = register_user(random_email('resend-auth'));
    $authed = http('POST', '/api/v1/auth/resend-verification', null, ['Authorization' => 'Bearer ' . $reg['access_token']]);
    assert_status($authed, 200, 'authed resend 200');
    assert_same(true, $authed['json']['data']['ok'] ?? null, 'authed resend ok');
});

test('forgot-password is generic 200 for existing and unknown emails', function () {
    clear_rate_limits();
    $email = random_email('forgot');
    register_user($email); // exists

    $known = http('POST', '/api/v1/auth/forgot-password', ['email' => $email]);
    $unknown = http('POST', '/api/v1/auth/forgot-password', ['email' => random_email('forgot-ghost')]);

    assert_status($known, 200, 'forgot known 200');
    assert_status($unknown, 200, 'forgot unknown 200');
    assert_same(true, $known['json']['data']['ok'] ?? null, 'known ok=true');
    assert_same(true, $unknown['json']['data']['ok'] ?? null, 'unknown ok=true');
    assert_same(null, $unknown['json']['data']['dev_preview'] ?? null, 'unknown has no preview key');
});

test('reset-password: happy path, sessions revoked, token single-use', function () {
    clear_rate_limits();
    $email = random_email('reset');
    $reg = register_user($email);

    $forgot = http('POST', '/api/v1/auth/forgot-password', ['email' => $email]);
    $token = token_from_preview($forgot['json']['data']['dev_preview'] ?? '');

    $reset = http('POST', '/api/v1/auth/reset-password', ['token' => $token, 'password' => 'N3w!Passw0rd']);
    assert_status($reset, 200, 'reset 200');
    assert_same(true, $reset['json']['data']['ok'] ?? null, 'reset ok');

    // Old password no longer works, new one does.
    $old = http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'Str0ng!Passw0rd']);
    assert_error($old, 401, 'INVALID_CREDENTIALS', 'old password rejected after reset');

    $fresh = login_user($email, 'N3w!Passw0rd');
    assert_true(isset($fresh['access_token']), 'new password logs in');

    // Every session issued before the reset is revoked.
    $me = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $reg['access_token']]);
    assert_error($me, 401, 'UNAUTHORIZED', 'pre-reset access token revoked');

    // Reusing the reset token fails.
    $reuse = http('POST', '/api/v1/auth/reset-password', ['token' => $token, 'password' => 'Another1Pass']);
    assert_error($reuse, 400, 'TOKEN_INVALID', 'reset token single-use');
});

test('reset-password: expired token rejected', function () {
    clear_rate_limits();
    $email = random_email('reset-exp');
    register_user($email);

    $forgot = http('POST', '/api/v1/auth/forgot-password', ['email' => $email]);
    $token = token_from_preview($forgot['json']['data']['dev_preview'] ?? '');
    $stmt = db()->prepare("UPDATE password_reset_tokens SET expires_at = '2020-01-01 00:00:00' WHERE token_hash = ?");
    $stmt->execute([hash('sha256', $token)]);
    assert_same(1, $stmt->rowCount(), 'reset expiry backdated');

    $expired = http('POST', '/api/v1/auth/reset-password', ['token' => $token, 'password' => 'N3w!Passw0rd']);
    assert_error($expired, 400, 'TOKEN_EXPIRED', 'expired reset token');

    // Password unchanged: original still works.
    login_user($email, 'Str0ng!Passw0rd');
});
// --- Group D: rate limits, body cap, authz, error sanitization, deletion ----

test('login rate limit: 429 RATE_LIMITED after the documented burst of 10/min', function () {
    clear_rate_limits();
    $email = random_email('burst');
    register_user($email);

    // Attempts 1..10 stay within the limit (all legitimately 401).
    for ($i = 1; $i <= 10; $i++) {
        $res = http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'WrongPass123']);
        assert_status($res, 401, "attempt {$i} within limit is 401");
    }

    // Attempt 11 exceeds 10/min.
    $blocked = http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'WrongPass123']);
    assert_error($blocked, 429, 'RATE_LIMITED', '11th login attempt');
    $retry = (int) ($blocked['headers']['retry-after'] ?? 0);
    assert_true($retry >= 1 && $retry <= 60, "Retry-After in 1..60 (got {$retry})");
    assert_same('10', $blocked['headers']['x-ratelimit-limit'] ?? null, 'X-RateLimit-Limit header');

    clear_rate_limits(); // do not poison later tests
});

test('request bodies over 64 KB are rejected with 413', function () {
    clear_rate_limits();
    $payload = [
        'email' => random_email('bigbody'),
        'password' => 'Str0ng!Passw0rd',
        'display_name' => 'bigbody',
        'junk' => str_repeat('A', 70 * 1024),
    ];
    $res = http('POST', '/api/v1/auth/register', $payload);
    assert_true($res['status'] === 413, '413 for oversized body, got ' . $res['status']);
    assert_same('VALIDATION_ERROR', $res['json']['error']['code'] ?? null, 'validation error code');
});

test('protected routes reject anonymous and foreign tokens', function () {
    $anon1 = http('GET', '/api/v1/auth/me');
    assert_error($anon1, 401, 'UNAUTHORIZED', 'me anonymous');
    $anon2 = http('DELETE', '/api/v1/account', ['password' => 'whatever123']);
    assert_error($anon2, 401, 'UNAUTHORIZED', 'delete account anonymous');
    $anon3 = http('POST', '/api/v1/auth/logout', ['refresh_token' => random_token()]);
    assert_error($anon3, 401, 'UNAUTHORIZED', 'logout anonymous');

    // A syntactically valid but unknown bearer token is still 401.
    $foreign = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . random_token()]);
    assert_error($foreign, 401, 'UNAUTHORIZED', 'unknown bearer token');
});

test('internal errors never leak SQL, paths or credentials', function () {
    clear_rate_limits();
    $email = random_email('sqlerr');
    $reg = register_user($email);

    // Break the schema temporarily: /me must fail with a generic 500.
    db()->exec('RENAME TABLE sessions TO sessions_bak_for_test');
    try {
        $res = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $reg['access_token']]);
        assert_status($res, 500, 'DB failure surfaces as 500');
        assert_same('INTERNAL_ERROR', $res['json']['error']['code'] ?? null, 'generic error code');
        $raw = strtolower($res['raw']);
        foreach (['sqlstate', 'mysql', 'mariadb', '/root/', 'backend/src', 'select ', 'sessions_bak'] as $needle) {
            assert_true(!str_contains($raw, $needle), "response must not contain '{$needle}'");
        }
        assert_true(isset($res['headers']['x-request-id']), 'request id present on 500');
    } finally {
        db()->exec('RENAME TABLE sessions_bak_for_test TO sessions');
    }

    // Same token works again after the schema is restored.
    $ok = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $reg['access_token']]);
    assert_status($ok, 200, 'me recovers after schema restore');
});

test('DELETE /account requires password, hard-deletes, orphans matches', function () {
    global $FIXTURE_MATCH_IDS;
    clear_rate_limits();
    $email = random_email('delacct');
    $reg = register_user($email);
    $access = $reg['access_token'];
    $userId = (int) $reg['user']['id'];

    // Fixture: a live match with this user as white player.
    $now = gmdate('Y-m-d H:i:s');
    db()->prepare('INSERT INTO matches (mode, rated, initial_time, increment, status, created_at) VALUES (?, 0, 600, 5, ?, ?)')
        ->execute(['rapid', 'live', $now]);
    $matchId = (int) db()->lastInsertId();
    $FIXTURE_MATCH_IDS[] = $matchId;
    db()->prepare('INSERT INTO match_players (match_id, user_id, color, rating_at_start, joined_at) VALUES (?, ?, ?, 1200, ?)')
        ->execute([$matchId, $userId, 'white', $now]);

    // Wrong password: 401, nothing deleted.
    $wrong = http('DELETE', '/api/v1/account', ['password' => 'WrongPassword1'], ['Authorization' => 'Bearer ' . $access]);
    assert_error($wrong, 401, 'INVALID_CREDENTIALS', 'wrong password on delete');
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    assert_same('1', (string) $stmt->fetchColumn(), 'user survives wrong-password delete');

    // Correct password: 200 + rows gone.
    $ok = http('DELETE', '/api/v1/account', ['password' => 'Str0ng!Passw0rd'], ['Authorization' => 'Bearer ' . $access]);
    assert_status($ok, 200, 'delete 200');
    assert_same(true, $ok['json']['data']['ok'] ?? null, 'delete ok');

    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    assert_same('0', (string) $stmt->fetchColumn(), 'user row deleted');

    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ?');
    $stmt->execute([$userId]);
    assert_same('0', (string) $stmt->fetchColumn(), 'sessions deleted');

    $stmt = db()->prepare('SELECT COUNT(*) FROM player_ratings WHERE user_id = ?');
    $stmt->execute([$userId]);
    assert_same('0', (string) $stmt->fetchColumn(), 'ratings deleted');

    $stmt = db()->prepare('SELECT COUNT(*) FROM email_verification_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    assert_same('0', (string) $stmt->fetchColumn(), 'verification tokens deleted');

    // Match survives, player link nulled out (orphaned).
    $stmt = db()->prepare('SELECT COUNT(*) FROM matches WHERE id = ?');
    $stmt->execute([$matchId]);
    assert_same('1', (string) $stmt->fetchColumn(), 'match row survives');
    $stmt = db()->prepare('SELECT user_id FROM match_players WHERE match_id = ?');
    $stmt->execute([$matchId]);
    assert_same(null, $stmt->fetchColumn(), 'match_players.user_id nulled out');

    // Token and credentials are dead afterwards.
    $me = http('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer ' . $access]);
    assert_error($me, 401, 'UNAUTHORIZED', 'access token dead after delete');
    $login = http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'Str0ng!Passw0rd']);
    assert_error($login, 401, 'INVALID_CREDENTIALS', 'login after delete rejected');
});

test('production without mail transport: 503 MAIL_NOT_CONFIGURED (no dev_preview)', function () {
    clear_rate_limits();
    $email = random_email('prodmail');
    register_user($email);

    $resend = http_prod('POST', '/api/v1/auth/resend-verification', ['email' => $email]);
    assert_true($resend['status'] === 503, 'resend 503 in production, got ' . $resend['status']);
    assert_same('MAIL_NOT_CONFIGURED', $resend['json']['error']['code'] ?? null, 'mail error code');
    assert_true(!isset($resend['json']['error']['dev_preview']), 'no dev_preview in production');

    // Enumeration safety: identical 503 whether or not the account exists.
    $ghost = http_prod('POST', '/api/v1/auth/forgot-password', ['email' => random_email('prodghost')]);
    $known = http_prod('POST', '/api/v1/auth/forgot-password', ['email' => $email]);
    assert_true($ghost['status'] === 503, 'forgot unknown 503, got ' . $ghost['status']);
    assert_true($known['status'] === 503, 'forgot known 503, got ' . $known['status']);
    assert_same($ghost['raw'], $known['raw'], 'identical bodies regardless of existence');
    assert_same('MAIL_NOT_CONFIGURED', $known['json']['error']['code'] ?? null, 'forgot mail error code');

    clear_rate_limits();
});
// __TESTS__

// ==================================================================== runner

pre_clean();

$failures = [];
$passed = 0;
$startedAt = microtime(true);

foreach ($TESTS as [$name, $fn]) {
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (AssertionFailed $e) {
        $failures[] = $name . ' — ' . $e->getMessage();
        fwrite(STDOUT, "FAIL  {$name}\n      {$e->getMessage()}\n");
    } catch (Throwable $e) {
        $cls = $e::class;
        $failures[] = $name . ' — ' . $cls . ': ' . $e->getMessage();
        fwrite(STDOUT, "ERROR {$name}\n      {$cls}: " . str_replace("\n", ' ', $e->getMessage()) . "\n");
    }
}

cleanup();

$elapsed = microtime(true) - $startedAt;
$total = $passed + count($failures);
fwrite(STDOUT, "\n========================================\n");
fwrite(STDOUT, sprintf("TOTAL: %d  PASSED: %d  FAILED: %d  (%.1fs)\n", $total, $passed, count($failures), $elapsed));
if ($failures !== []) {
    fwrite(STDOUT, "Failures:\n");
    foreach ($failures as $f) {
        fwrite(STDOUT, "  - {$f}\n");
    }
    fwrite(STDOUT, "RESULT: FAIL\n");
    exit(1);
}
fwrite(STDOUT, "RESULT: PASS\n");
exit(0);
