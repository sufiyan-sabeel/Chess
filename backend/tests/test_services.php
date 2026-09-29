<?php

/**
 * Service-level unit tests: password hashing, opaque tokens, CORS allowlist,
 * validation, SQL splitting, and the mail-not-configured contract — no HTTP
 * server needed, but a scratch database is (tokens are stored server-side).
 *
 *   php backend/tests/test_services.php
 */

declare(strict_types=1);

// Must run BEFORE bootstrap/config is first loaded anywhere in this process:
// this suite exercises the "no mail transport" contract.
putenv('MAIL_TRANSPORT=null');

require __DIR__ . '/helpers.php';

use Checkmate\Auth\MailerFactory;
use Checkmate\Auth\NullMailer;
use Checkmate\Auth\PasswordService;
use Checkmate\Auth\TokenService;
use Checkmate\Auth\UserRepository;
use Checkmate\Auth\AuthService;
use Checkmate\Db\Migrator;
use Checkmate\Http\ApiException;
use Checkmate\Http\Middleware\CorsMiddleware;
use Checkmate\Support\Validator;

require backend_dir() . '/src/bootstrap.php';

$db = 'checkmate_test_unit';

echo "-- service unit tests (scratch db {$db})\n";

fresh_db($db);
$mig = run_migrate('migrate', $db);
eq(0, $mig['code'], 'scratch migrations applied');

// Give THIS process the scratch DB (process env wins over .env).
putenv("DB_NAME={$db}");
putenv('DB_SOCKET=' . db_socket());
putenv('DB_USER=root');
putenv('DB_PASSWORD=');
putenv('DB_HOST=');

use Checkmate\Database\Connection;
$pdo = Connection::pdo();
$pdo->exec("USE `{$db}`");

// ------------------------------------------------------------ passwords
echo "-- passwords\n";
$hash = PasswordService::hash('Str0ng!Passw0rd');
ok(PasswordService::verify('Str0ng!Passw0rd', $hash), 'password verifies');
ok(!PasswordService::verify('Str0ng!Passw0rdX', $hash), 'wrong password fails');
ok(!PasswordService::verify('anything', ''), 'empty stored hash fails safely');
ok(!PasswordService::verify('x', PasswordService::dummyHash()), 'dummy hash never verifies');
ok(is_string(PasswordService::algorithmName()) && PasswordService::algorithmName() !== '',
    'hash algorithm selected (argon2id when available, bcrypt fallback here)');
eq('bcrypt', PasswordService::algorithmName(), 'this Termux PHP build falls back to bcrypt (no libargon2)');

// ------------------------------------------------------------ tokens
echo "-- opaque tokens\n";
$token = TokenService::randomOpaque();
eq(43, strlen($token), 'token is 43 chars');
ok(preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1, 'token is base64url without padding');
eq(hash('sha256', $token), TokenService::hash($token), 'sha256 hex is 64 chars');
eq(64, strlen(TokenService::hash($token)), 'stored hash length');

$users = new UserRepository($pdo);
$uid = $users->create('tokens@example.com', PasswordService::hash('Str0ng!Passw0rd'), 'tokenuser');
$tokens = new TokenService($pdo);
$pair = $tokens->issue($uid);
eq(43, strlen($pair['access_token']), 'issued access token length');
eq(900, $pair['expires_in'], 'access TTL 15 min');
$row = $tokens->verifyAccess($pair['access_token']);
eq($uid, (int) $row['user_id'], 'verifyAccess resolves the issuing user');

try {
    $tokens->verifyAccess('no-such-token');
    ok(false, 'unknown access token rejected');
} catch (ApiException $e) {
    eq('TOKEN_INVALID', $e->errorCode, 'unknown access token -> TOKEN_INVALID');
    eq(401, $e->status, 'TOKEN_INVALID is 401');
}

// expired access token
$pdo->prepare('UPDATE auth_tokens SET expires_at = ? WHERE id = ?')
    ->execute(['2020-01-01 00:00:00', $row['id']]);
try {
    $tokens->verifyAccess($pair['access_token']);
    ok(false, 'expired access token rejected');
} catch (ApiException $e) {
    eq('TOKEN_EXPIRED', $e->errorCode, 'expired access token -> TOKEN_EXPIRED');
}

// rotation + reuse detection
$rotated = $tokens->rotate($pair['refresh_token']);
ok(isset($rotated['access_token'], $rotated['refresh_token']), 'refresh rotates to a new pair');
try {
    $tokens->rotate($pair['refresh_token']);
    ok(false, 'reuse of a rotated refresh token rejected');
} catch (ApiException $e) {
    eq('TOKEN_INVALID', $e->errorCode, 'rotated-token reuse -> TOKEN_INVALID');
}
try {
    $tokens->rotate($rotated['refresh_token']);
    ok(false, 'family revocation cascades to the newest token');
} catch (ApiException $e) {
    eq('TOKEN_INVALID', $e->errorCode, 'reuse revokes the whole family');
}

// raw tokens are never stored
$hashes = $pdo->query('SELECT token_hash FROM auth_tokens')->fetchAll(PDO::FETCH_COLUMN);
ok(!in_array($pair['access_token'], $hashes, true), 'raw access token absent from the DB');
ok(!in_array($pair['refresh_token'], $hashes, true), 'raw refresh token absent from the DB');

// ------------------------------------------------------------ CORS
echo "-- CORS allowlist\n";
$cors = new CorsMiddleware();
ok($cors->isAllowed('http://localhost:5173'), 'localhost with any port allowed');
ok($cors->isAllowed('http://127.0.0.1:3000'), '127.0.0.1 with any port allowed');
ok($cors->isAllowed('https://appassets.androidplatform.net'), 'Android WebView asset origin allowed');
ok($cors->isAllowed('http://10.0.2.2:8080'), 'configured emulator origin allowed');
ok(!$cors->isAllowed('https://evil.example.com'), 'unknown origin rejected');
ok(!$cors->isAllowed('http://localhost.evil.com'), 'look-alike localhost origin rejected');

// ------------------------------------------------------------ validation
echo "-- validation\n";
$errors = Validator::make(
    ['email' => 'nope', 'password' => 'short', 'display_name' => 'a b'],
    ['email' => 'required|email', 'password' => 'required|password', 'display_name' => 'required|string|min:3|max:20|regex:/^[A-Za-z0-9_]+$/']
);
$keys = array_keys($errors);
sort($keys);
eq(['display_name', 'email', 'password'], $keys, 'invalid fields reported');
ok(Validator::passwordAcceptable('Str0ng!Passw0rd'), 'strong password accepted');
ok(!Validator::passwordAcceptable('alllettersonly'), 'letter-only password rejected');
ok(!Validator::passwordAcceptable('1234567890'), 'digit-only password rejected');

// ------------------------------------------------------------ SQL split
echo "-- migration SQL splitting\n";
$parts = Migrator::splitStatements("INSERT INTO t VALUES ('a;b'); -- note; not a split\nINSERT INTO t VALUES ('it''s'); /* c; */ DELETE FROM t");
eq(3, count($parts), 'statements split respecting quotes and comments');
contains($parts[0], "'a;b'", 'semicolon inside single quotes preserved');
contains($parts[1], "'it''s'", 'doubled quote escape preserved');

// ------------------------------------------------------------ mail contract
echo "-- mail-not-configured contract (MAIL_TRANSPORT=null in this process)\n";
$factoryMailer = MailerFactory::create();
ok($factoryMailer instanceof NullMailer, 'null transport yields NullMailer');
ok(!$factoryMailer->isConfigured(), 'NullMailer reports not configured');

$service = new AuthService($pdo); // picks up MAIL_TRANSPORT=null from this process
$svcUser = $users->create('nomail@example.com', PasswordService::hash('Str0ng!Passw0rd'), 'nomail');
try {
    $service->resendVerification(null, 'nomail@example.com');
    ok(false, 'resend without transport raises');
} catch (ApiException $e) {
    eq('MAIL_NOT_CONFIGURED', $e->errorCode, 'resend -> MAIL_NOT_CONFIGURED');
    eq(503, $e->status, 'MAIL_NOT_CONFIGURED is 503');
    ok(isset($e->extra['dev_preview']['token']), 'development dev_preview carries the token');
}
try {
    $service->resendVerification(null, 'definitely-absent@example.com');
    ok(false, 'resend without transport raises (uniform behaviour)');
} catch (ApiException $e) {
    eq('MAIL_NOT_CONFIGURED', $e->errorCode, 'same 503 for unknown e-mail (no enumeration)');
    ok(!isset($e->extra['dev_preview']), 'no dev_preview for unknown e-mail');
}
$forgot = $service->forgotPassword('nomail@example.com');
eq(true, $forgot['ok'], 'forgot-password stays 200 {ok:true}');
ok(isset($forgot['dev_preview']['token']), 'dev_preview surfaces reset token in development');
$forgotUnknown = $service->forgotPassword('absent@example.com');
eq(['ok' => true], $forgotUnknown, 'unknown e-mail: identical {ok:true} without preview');

// ------------------------------------------------------------ teardown
drop_db($db);
echo "  scratch database {$db} dropped\n";

test_summary('services');
