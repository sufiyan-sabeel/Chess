<?php

/**
 * HTTP integration suite: boots `php -S 127.0.0.1:8099 -t backend/public`
 * against a scratch database and exercises docs/API.md §1 end-to-end —
 * envelope, headers, CORS, auth happy paths, failure modes, token rotation
 * reuse, rate limiting.
 *
 *   php backend/tests/test_http.php
 */

declare(strict_types=1);

require __DIR__ . '/helpers.php';

const TEST_DB = 'checkmate_test_http';
const TEST_PORT = 8099;
const TEST_BASE = 'http://127.0.0.1:8099';

function clear_rate_limits(): void
{
    root_pdo()->exec('DELETE FROM `'.TEST_DB.'`.`rate_limits`');
}

function fresh_email(string $prefix): string
{
    return sprintf('%s%d%d@example.test', $prefix, getmypid(), random_int(100000, 999999));
}

/** @return array{user:array, access:string, refresh:string, email:string} */
function make_user(string $prefix = 'user'): array
{
    $email = fresh_email($prefix);
    $res = http('POST', '/api/v1/auth/register', ['body' => [
        'email' => $email,
        'password' => 'Str0ng!Passw0rd',
        'display_name' => 'umaiz_dev',
    ]]);
    eq(201, $res['status'], "setup: register {$prefix} -> 201");
    $data = $res['json']['data'] ?? [];
    if (!isset($data['access_token'])) {
        throw new RuntimeException('setup: register failed: ' . $res['body']);
    }
    return ['user' => $data['user'], 'access' => $data['access_token'], 'refresh' => $data['refresh_token'], 'email' => $email];
}

echo "-- http suite against a fresh scratch database (" . TEST_DB . ")\n";

fresh_db(TEST_DB);
$mig = run_migrate('migrate', TEST_DB);
eq(0, $mig['code'], 'scratch migrations applied');

$server = server_start(TEST_PORT, ['DB_NAME' => TEST_DB, 'MAIL_TRANSPORT' => 'file', 'APP_ENV' => 'development']);
register_shutdown_function(static function () use ($server): void {
    server_stop($server);
});
server_wait(TEST_PORT);
echo "  server up on :" . TEST_PORT . "\n";

// ------------------------------------------------------------ §2 health + headers
echo "-- health, envelope, headers\n";
$health = http('GET', '/api/v1/health');
eq(200, $health['status'], 'GET /api/v1/health -> 200');
assert_envelope($health, true, null, 'health');
eq('ok', $health['json']['data']['status'] ?? null, 'health status ok');
ok(isset($health['json']['data']['time'], $health['json']['data']['version']), 'health has time + version');

$ready = http('GET', '/api/v1/ready');
eq(200, $ready['status'], 'GET /api/v1/ready -> 200 (DB reachable)');

$missing = http('GET', '/api/v1/no-such-endpoint');
eq(404, $missing['status'], 'unknown route -> 404');
assert_envelope($missing, false, 'NOT_FOUND', '404');

foreach (['content-security-policy', 'x-content-type-options', 'referrer-policy', 'cache-control'] as $header) {
    ok(isset($health['headers'][$header]), "security header {$header} present");
    ok(isset($missing['headers'][$header]), "security header {$header} present on error responses");
}
contains($health['headers']['cache-control'] ?? '', 'no-store', 'Cache-Control: no-store');
contains($health['headers']['content-security-policy'] ?? '', "default-src 'none'", 'restrictive CSP');

$echo = http('GET', '/api/v1/health', ['headers' => ['X-Request-Id: mycorrelation1234']]);
eq('mycorrelation1234', $echo['headers']['x-request-id'] ?? null, 'X-Request-Id echoed');
ok(preg_match('/^[a-f0-9]{32}$/', $echo2 = http('GET', '/api/v1/health')['headers']['x-request-id'] ?? '') === 1,
    'missing X-Request-Id is generated (32 hex)');

// ------------------------------------------------------------ CORS
echo "-- CORS preflight\n";
$pre = http('OPTIONS', '/api/v1/auth/login', ['headers' => [
    'Origin: http://localhost:5173',
    'Access-Control-Request-Method: POST',
    'Access-Control-Request-Headers: content-type',
]]);
eq(204, $pre['status'], 'preflight -> 204');
eq('http://localhost:5173', $pre['headers']['access-control-allow-origin'] ?? null, 'wildcard dev origin allowed');
contains(strtoupper($pre['headers']['access-control-allow-methods'] ?? ''), 'POST', 'preflight allows POST');
contains($pre['headers']['access-control-allow-headers'] ?? '', 'Authorization', 'preflight allows Authorization');
eq('Origin', $pre['headers']['vary'] ?? null, 'preflight varies on Origin');

$webview = http('OPTIONS', '/api/v1/auth/login', ['headers' => [
    'Origin: https://appassets.androidplatform.net',
    'Access-Control-Request-Method: POST',
]]);
eq('https://appassets.androidplatform.net', $webview['headers']['access-control-allow-origin'] ?? null,
    'WebView asset origin allowed');

$evil = http('OPTIONS', '/api/v1/auth/login', ['headers' => [
    'Origin: https://evil.example.com',
    'Access-Control-Request-Method: POST',
]]);
eq(204, $evil['status'], 'preflight for unknown origin still 204 (no route leak)');
ok(!isset($evil['headers']['access-control-allow-origin']), 'unknown origin gets no ACAO header');

$actual = http('GET', '/api/v1/health', ['headers' => ['Origin: http://127.0.0.1:4000']]);
eq('http://127.0.0.1:4000', $actual['headers']['access-control-allow-origin'] ?? null, 'actual request carries ACAO');

// ------------------------------------------------------------ register
echo "-- POST /auth/register\n";
clear_rate_limits();
$email = fresh_email('main');
$res = http('POST', '/api/v1/auth/register', ['body' => [
    'email' => '  ' . strtoupper($email) . '  ',
    'password' => 'Str0ng!Passw0rd',
    'display_name' => 'umaiz',
]]);
eq(201, $res['status'], 'register -> 201');
assert_envelope($res, true, null, 'register');
$data = $res['json']['data'];
ok(is_int($data['user']['id'] ?? null), 'user.id is an integer');
eq(strtolower(trim($email)), $data['user']['email'] ?? null, 'e-mail lowercased+trimmed');
eq('umaiz', $data['user']['display_name'] ?? null, 'display_name echoed');
eq(false, $data['user']['email_verified'] ?? null, 'email_verified starts false');
ok(is_string($data['user']['created_at'] ?? null), 'created_at present');
ok(preg_match('/^[A-Za-z0-9_-]{43}$/', $data['access_token'] ?? '') === 1, 'access token: 43-char base64url');
ok(preg_match('/^[A-Za-z0-9_-]{43}$/', $data['refresh_token'] ?? '') === 1, 'refresh token: 43-char base64url');
eq(900, $data['expires_in'] ?? null, 'expires_in = 900 (15 min)');

// validation failures
$bad = http('POST', '/api/v1/auth/register', ['body' => [
    'email' => 'not-an-email',
    'password' => 'short',
    'display_name' => 'a b!',
]]);
eq(400, $bad['status'], 'invalid register -> 400');
assert_envelope($bad, false, 'VALIDATION_ERROR', 'invalid register');
ok(isset($bad['json']['error']['fields']['email']), 'field-level error for email');
ok(isset($bad['json']['error']['fields']['password']), 'field-level error for password');
ok(isset($bad['json']['error']['fields']['display_name']), 'field-level error for display_name');

$dup = http('POST', '/api/v1/auth/register', ['body' => [
    'email' => $email,
    'password' => 'Str0ng!Passw0rd',
    'display_name' => 'othername',
]]);
eq(409, $dup['status'], 'duplicate e-mail -> 409');
assert_envelope($dup, false, 'EMAIL_TAKEN', 'duplicate register');

$empty = http('POST', '/api/v1/auth/register', ['body' => []]);
eq(400, $empty['status'], 'empty register body -> 400');
assert_envelope($empty, false, 'VALIDATION_ERROR', 'empty register');

// ------------------------------------------------------------ login
echo "-- POST /auth/login\n";
clear_rate_limits();
$ok1 = http('POST', '/api/v1/auth/login', ['body' => ['email' => $email, 'password' => 'Str0ng!Passw0rd']]);
eq(200, $ok1['status'], 'login -> 200');
assert_envelope($ok1, true, null, 'login');
ok(isset($ok1['json']['data']['access_token'], $ok1['json']['data']['refresh_token'], $ok1['json']['data']['user']), 'login returns token pair + user');
$loginAccess = $ok1['json']['data']['access_token'];
$loginRefresh = $ok1['json']['data']['refresh_token'];

$wrong = http('POST', '/api/v1/auth/login', ['body' => ['email' => $email, 'password' => 'WrongPass999']]);
eq(401, $wrong['status'], 'wrong password -> 401');
assert_envelope($wrong, false, 'INVALID_CREDENTIALS', 'wrong password');
$wrongMsg = $wrong['json']['error']['message'] ?? '';

$unknown = http('POST', '/api/v1/auth/login', ['body' => ['email' => fresh_email('ghost'), 'password' => 'WrongPass999']]);
eq(401, $unknown['status'], 'unknown e-mail -> 401');
assert_envelope($unknown, false, 'INVALID_CREDENTIALS', 'unknown e-mail');
eq($wrongMsg, $unknown['json']['error']['message'] ?? '', 'identical message for both failure modes');

ok($wrong['ms'] >= 95, sprintf('wrong-password failure takes >=100 ms (%.0f ms)', $wrong['ms']));
ok($unknown['ms'] >= 95, sprintf('unknown-e-mail failure takes >=100 ms (%.0f ms)', $unknown['ms']));

// ------------------------------------------------------------ me
echo "-- GET /auth/me\n";
clear_rate_limits();
$noAuth = http('GET', '/api/v1/auth/me');
eq(401, $noAuth['status'], 'me without token -> 401');
assert_envelope($noAuth, false, 'UNAUTHORIZED', 'me unauthenticated');

$badAuth = http('GET', '/api/v1/auth/me', ['headers' => ['Authorization: Bearer notarealtoken']]);
eq(401, $badAuth['status'], 'me with garbage token -> 401');
assert_envelope($badAuth, false, 'TOKEN_INVALID', 'me garbage token');

$me = http('GET', '/api/v1/auth/me', ['headers' => ['Authorization: Bearer ' . $loginAccess]]);
eq(200, $me['status'], 'me with token -> 200');
assert_envelope($me, true, null, 'me');
$user = $me['json']['data']['user'];
eq(strtolower(trim($email)), $user['email'], 'me returns the e-mail');
ok(isset($user['avatar_seed']) && is_string($user['avatar_seed']), 'me has avatar_seed');
ok(isset($user['created_at']), 'me has created_at');
$modes = array_keys($user['ratings'] ?? []);
sort($modes);
eq(['blitz', 'bullet', 'classical', 'rapid'], $modes, 'me exposes ratings for all four modes');
foreach ($user['ratings'] as $mode => $rating) {
    ok(is_int($rating['rating']) && is_int($rating['games']), "ratings.{$mode} has rating + games integers");
    eq(1200, $rating['rating'], "ratings.{$mode} starts at 1200");
}

// ------------------------------------------------------------ refresh / rotation
echo "-- POST /auth/refresh (rotation + reuse revocation)\n";
clear_rate_limits();
$rf1 = http('POST', '/api/v1/auth/refresh', ['body' => ['refresh_token' => $loginRefresh]]);
eq(200, $rf1['status'], 'refresh -> 200');
assert_envelope($rf1, true, null, 'refresh');
$newAccess = $rf1['json']['data']['access_token'];
$newRefresh = $rf1['json']['data']['refresh_token'];
ok($newRefresh !== $loginRefresh, 'refresh token rotated (old != new)');
eq(900, $rf1['json']['data']['expires_in'] ?? null, 'rotated access TTL 900');

$reuse = http('POST', '/api/v1/auth/refresh', ['body' => ['refresh_token' => $loginRefresh]]);
eq(401, $reuse['status'], 'reuse of rotated token -> 401');
assert_envelope($reuse, false, 'TOKEN_INVALID', 'refresh reuse');

$cascade = http('POST', '/api/v1/auth/refresh', ['body' => ['refresh_token' => $newRefresh]]);
eq(401, $cascade['status'], 'reuse revokes the whole family (newest token dies too)');
assert_envelope($cascade, false, 'TOKEN_INVALID', 'family revocation');

$noBody = http('POST', '/api/v1/auth/refresh', ['body' => []]);
eq(400, $noBody['status'], 'refresh without token -> 400 VALIDATION_ERROR');
assert_envelope($noBody, false, 'VALIDATION_ERROR', 'refresh missing token');

// ------------------------------------------------------------ logout
echo "-- POST /auth/logout\n";
clear_rate_limits();
$u2 = make_user('logout');
$lo1 = http('POST', '/api/v1/auth/logout', [
    'headers' => ['Authorization: Bearer ' . $u2['access']],
    'body' => ['refresh_token' => $u2['refresh']],
]);
eq(200, $lo1['status'], 'logout -> 200');
assert_envelope($lo1, true, null, 'logout');
eq(true, $lo1['json']['data']['ok'] ?? null, 'logout data.ok = true');

$lo2 = http('POST', '/api/v1/auth/logout', [
    'headers' => ['Authorization: Bearer ' . $u2['access']],
    'body' => ['refresh_token' => $u2['refresh']],
]);
eq(200, $lo2['status'], 'logout is idempotent (revoked token again -> 200)');

$afterLogout = http('POST', '/api/v1/auth/refresh', ['body' => ['refresh_token' => $u2['refresh']]]);
eq(401, $afterLogout['status'], 'refresh after logout -> 401');
assert_envelope($afterLogout, false, 'TOKEN_INVALID', 'refresh after logout');

// ------------------------------------------------------------ e-mail verification
echo "-- verify-email / resend-verification\n";
clear_rate_limits();
$u3 = make_user('verify');
$token = mail_token_for($u3['email'], 'verify');
ok(is_string($token) && strlen($token) === 64, 'verification token retrievable from dev mail log');

$noVerify = http('GET', '/api/v1/auth/me', ['headers' => ['Authorization: Bearer ' . $u3['access']]]);
eq(false, $noVerify['json']['data']['user']['email_verified'] ?? null, 'unverified before token use');

$vf = http('POST', '/api/v1/auth/verify-email', ['body' => ['token' => (string) $token]]);
eq(200, $vf['status'], 'verify-email -> 200');
assert_envelope($vf, true, null, 'verify-email');
eq(true, $vf['json']['data']['ok'] ?? null, 'verify data.ok = true');

$vf2 = http('POST', '/api/v1/auth/verify-email', ['body' => ['token' => (string) $token]]);
eq(401, $vf2['status'], 'verification token reuse -> 401');
assert_envelope($vf2, false, 'TOKEN_INVALID', 'verify reuse');

$vf3 = http('POST', '/api/v1/auth/verify-email', ['body' => ['token' => 'garbage']]);
eq(401, $vf3['status'], 'malformed verification token -> 401');
assert_envelope($vf3, false, 'TOKEN_INVALID', 'verify malformed');

$afterVerify = http('GET', '/api/v1/auth/me', ['headers' => ['Authorization: Bearer ' . $u3['access']]]);
eq(true, $afterVerify['json']['data']['user']['email_verified'] ?? null, 'email_verified flips to true');

$rs = http('POST', '/api/v1/auth/resend-verification', [
    'headers' => ['Authorization: Bearer ' . $u3['access']],
]);
eq(200, $rs['status'], 'resend (authenticated) -> 200');
assert_envelope($rs, true, null, 'resend auth');
$rs2 = http('POST', '/api/v1/auth/resend-verification', ['body' => ['email' => $u3['email']]]);
eq(200, $rs2['status'], 'resend (by e-mail) -> 200');
$rsGhost = http('POST', '/api/v1/auth/resend-verification', ['body' => ['email' => fresh_email('ghost')]]);
eq(200, $rsGhost['status'], 'resend for unknown e-mail -> 200 (no enumeration)');

// ------------------------------------------------------------ forgot / reset
echo "-- forgot-password / reset-password\n";
clear_rate_limits();
$forgotGhost = http('POST', '/api/v1/auth/forgot-password', ['body' => ['email' => fresh_email('ghost')]]);
eq(200, $forgotGhost['status'], 'forgot for unknown e-mail -> 200');
assert_envelope($forgotGhost, true, null, 'forgot unknown');
ok(!isset($forgotGhost['json']['data']['dev_preview']), 'no token leak for unknown e-mail');

$forgot = http('POST', '/api/v1/auth/forgot-password', ['body' => ['email' => $u3['email']]]);
eq(200, $forgot['status'], 'forgot for known e-mail -> 200 (same shape)');
assert_envelope($forgot, true, null, 'forgot known');
eq(true, $forgot['json']['data']['ok'] ?? null, 'forgot data.ok = true');
$resetToken = mail_token_for($u3['email'], 'reset');
ok(is_string($resetToken) && strlen($resetToken) === 64, 'reset token retrievable from dev mail log');

$badReset = http('POST', '/api/v1/auth/reset-password', ['body' => ['token' => 'nope', 'password' => 'N3w!Passw0rd']]);
eq(401, $badReset['status'], 'invalid reset token -> 401');
assert_envelope($badReset, false, 'TOKEN_INVALID', 'reset invalid token');

$weakReset = http('POST', '/api/v1/auth/reset-password', ['body' => ['token' => (string) $resetToken, 'password' => 'weak']]);
eq(400, $weakReset['status'], 'weak new password -> 400');
assert_envelope($weakReset, false, 'VALIDATION_ERROR', 'reset weak password');

$reset = http('POST', '/api/v1/auth/reset-password', ['body' => ['token' => (string) $resetToken, 'password' => 'N3w!Passw0rd']]);
eq(200, $reset['status'], 'reset-password -> 200');
assert_envelope($reset, true, null, 'reset-password');

$oldPw = http('POST', '/api/v1/auth/login', ['body' => ['email' => $u3['email'], 'password' => 'Str0ng!Passw0rd']]);
eq(401, $oldPw['status'], 'old password no longer works');
$newPw = http('POST', '/api/v1/auth/login', ['body' => ['email' => $u3['email'], 'password' => 'N3w!Passw0rd']]);
eq(200, $newPw['status'], 'new password works');

$revoked = http('POST', '/api/v1/auth/refresh', ['body' => ['refresh_token' => $u3['refresh']]]);
eq(401, $revoked['status'], 'password reset revokes all existing sessions');
assert_envelope($revoked, false, 'TOKEN_INVALID', 'sessions revoked on reset');

// ------------------------------------------------------------ rate limiting
echo "-- rate limiting (register: 5/min per IP+route)\n";
clear_rate_limits();
// Start at the beginning of a fixed window so the 6 requests cannot straddle
// a window boundary (which would reset the counter mid-test).
$room = 60 - (time() % 60);
if ($room < 5) {
    sleep($room);
}
$limitStatuses = [];
for ($i = 1; $i <= 5; $i++) {
    $rl = http('POST', '/api/v1/auth/register', ['body' => ['email' => 'invalid', 'password' => 'x', 'display_name' => '!']]);
    $limitStatuses[] = $rl['status'];
    if ($i === 1) {
        ok(isset($rl['headers']['x-ratelimit-limit']), 'success carries X-RateLimit-Limit');
        eq('5', $rl['headers']['x-ratelimit-limit'] ?? null, 'register limit is 5');
    }
}
eq([400, 400, 400, 400, 400], $limitStatuses, 'first 5 requests inside the window are processed');
$blocked = http('POST', '/api/v1/auth/register', ['body' => ['email' => 'invalid', 'password' => 'x', 'display_name' => '!']]);
eq(429, $blocked['status'], '6th request -> 429');
assert_envelope($blocked, false, 'RATE_LIMITED', 'rate limited');
$retry = (int) ($blocked['headers']['retry-after'] ?? 0);
ok($retry >= 1 && $retry <= 60, "Retry-After is 1..60 seconds (got {$retry})");

$otherRoute = http('POST', '/api/v1/auth/forgot-password', ['body' => ['email' => fresh_email('rl')]]);
eq(200, $otherRoute['status'], 'rate limits are per-route (forgot unaffected by register bucket)');

// ------------------------------------------------------------ body cap + misc
echo "-- body cap, account deletion\n";
clear_rate_limits();
$tooBig = http('POST', '/api/v1/auth/register', ['body' => [
    'email' => fresh_email('big'),
    'password' => 'Str0ng!Passw0rd',
    'display_name' => str_repeat('a', 70000),
]]);
eq(413, $tooBig['status'], '70 KB body -> 413');
assert_envelope($tooBig, false, 'VALIDATION_ERROR', 'body cap');

// DELETE /account (§1) — wired to the concurrently-shipped AccountController.
clear_rate_limits();
$u4 = make_user('delete');
$del = http('DELETE', '/api/v1/account', [
    'headers' => ['Authorization: Bearer ' . $u4['access']],
    'body' => ['password' => 'Str0ng!Passw0rd'],
]);
eq(200, $del['status'], 'DELETE /account -> 200');
assert_envelope($del, true, null, 'delete account');
$delMe = http('GET', '/api/v1/auth/me', ['headers' => ['Authorization: Bearer ' . $u4['access']]]);
eq(401, $delMe['status'], 'tokens dead after account deletion');

// ------------------------------------------------------------ teardown
server_stop($server);
drop_db(TEST_DB);
echo "  server stopped, scratch database " . TEST_DB . " dropped\n";

test_summary('http');


