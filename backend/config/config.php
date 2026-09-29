<?php

/**
 * Config loader (env based).
 *
 * Order of precedence (highest wins):
 *   1. Real process environment (putenv/$_ENV/getenv) — used by tests to override per-process.
 *   2. backend/.env file (local development).
 *   3. Hard defaults below.
 *
 * Returns a plain nested array. Never log this array: it contains DB credentials.
 */

declare(strict_types=1);

if (!function_exists('checkmate_load_dotenv')) {
    /**
     * Minimal .env parser: KEY=value lines, `#` comments, optional single/double
     * quotes. No interpolation, no export keyword, no multiline values.
     *
     * @return array<string,string>
     */
    function checkmate_load_dotenv(string $path): array
    {
        $out = [];
        if (!is_file($path) || !is_readable($path)) {
            return $out;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $out;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $out[$key] = $value;
        }
        return $out;
    }
}

if (!function_exists('checkmate_env')) {
    /**
     * Read one setting: process environment beats .env file beats default.
     *
     * A variable that is *set* in the process environment wins even when it is
     * empty (e.g. `DB_PASSWORD=` for the passwordless root socket used by
     * tests) — only a variable that is not present falls through to .env.
     */
    function checkmate_env(string $key, string $default = ''): string
    {
        static $file = null;
        if ($file === null) {
            $file = checkmate_load_dotenv(dirname(__DIR__) . '/.env');
        }
        $v = getenv($key);
        if ($v !== false) {
            return $v;
        }
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        if (isset($file[$key]) && $file[$key] !== '') {
            return $file[$key];
        }
        return $default;
    }
}

if (!function_exists('checkmate_config')) {
    function checkmate_config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $bool = static fn(string $key, string $default): bool =>
            strtolower(checkmate_env($key, $default)) === 'true';
        $int = static fn(string $key, string $default): int =>
            (int) checkmate_env($key, $default);

        $origins = array_values(array_filter(array_map(
            static fn(string $o): string => rtrim(trim($o), '/'),
            explode(',', checkmate_env('CORS_ALLOWED_ORIGINS', ''))
        )));

        $backendDir = dirname(__DIR__);

        $config = [
            'app' => [
                'env' => checkmate_env('APP_ENV', 'development'),
                'debug' => $bool('APP_DEBUG', 'false'),
                'url' => rtrim(checkmate_env('APP_URL', 'http://127.0.0.1:8080'), '/'),
                'version' => checkmate_env('APP_VERSION', '1.0.0'),
                'base_path' => '/api/v1',
            ],
            'db' => [
                'host' => checkmate_env('DB_HOST', '127.0.0.1'),
                'port' => $int('DB_PORT', '3307'),
                'socket' => checkmate_env('DB_SOCKET', ''),
                'name' => checkmate_env('DB_NAME', 'checkmate'),
                'user' => checkmate_env('DB_USER', 'checkmate'),
                'password' => checkmate_env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
            ],
            'tokens' => [
                'access_ttl' => $int('ACCESS_TOKEN_TTL', '900'),      // 15 min
                'refresh_ttl' => $int('REFRESH_TOKEN_TTL', '2592000'), // 30 days
                'email_verify_ttl' => 86400,                           // 24 h (docs/API.md §1)
                'password_reset_ttl' => 3600,                          // 1 h
            ],
            'security' => [
                'body_max_bytes' => $int('BODY_MAX_BYTES', '65536'), // 64 KB
                'password_min_length' => $int('PASSWORD_MIN_LENGTH', '10'),
                'password_require_letter' => $bool('PASSWORD_REQUIRE_LETTER', 'true'),
                'password_require_digit' => $bool('PASSWORD_REQUIRE_DIGIT', 'true'),
                'login_min_budget_ms' => 100, // enumeration-safe timing floor (docs/API.md §1)
            ],
            'cors' => [
                'allowed_origins' => $origins,
            ],
            'rate_limits' => [
                'window' => $int('RATE_WINDOW_SECONDS', '60'),
                'login' => $int('RATE_LIMIT_LOGIN', '10'),
                'register' => $int('RATE_LIMIT_REGISTER', '5'),
                'verify' => $int('RATE_LIMIT_VERIFY', '10'),
                'resend' => $int('RATE_LIMIT_RESEND', '3'),
                'forgot' => $int('RATE_LIMIT_FORGOT', '3'),
                'reset' => $int('RATE_LIMIT_RESET', '5'),
                'default' => $int('RATE_LIMIT_DEFAULT', '120'),
            ],
            'mail' => [
                'transport' => checkmate_env('MAIL_TRANSPORT', 'null'),
                'from' => checkmate_env('MAIL_FROM', 'no-reply@checkmate.local'),
            ],
            'paths' => [
                'backend' => $backendDir,
                'var' => $backendDir . '/var',
                'log' => $backendDir . '/var/log/app.log',
                'migrations' => $backendDir . '/migrations',
            ],
        ];

        return $config;
    }
}

if (!function_exists('config')) {
    /** Dot-path accessor: config('db.host'). */
    function config(string $key, mixed $default = null): mixed
    {
        $node = checkmate_config();
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
