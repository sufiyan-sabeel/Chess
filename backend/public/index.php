<?php

/**
 * Canonical front controller for the auth/http/db stack (owner: auth/http/db
 * agent). Path resolution is location-independent: this file works both as
 * backend/src/Http/front-controller.php (canonical copy) and, byte-identical,
 * as backend/public/index.php (deployment entry point). tests/run.php pins
 * public/index.php to this content before booting the test server — the same
 * trick bin/dev.sh uses for the concurrently-shipped stack.
 *
 * Dev server:  php -S 127.0.0.1:8080 -t backend/public
 * (Without a router argument the built-in server falls back to index.php for
 *  non-file paths; with a router argument this file also serves real static
 *  files itself via the cli-server passthrough below.)
 *
 * Production: point nginx/Apache at this file (try_files -> /index.php).
 * TLS belongs to the reverse proxy — this process speaks plain HTTP on
 * loopback only (docs/API.md: HTTPS is mandatory in production and enforced
 * by the Android network security config).
 */

declare(strict_types=1);

// Locate the backend root regardless of where this file is included from
// (backend/ or backend/src/Http/): first ancestor with src/bootstrap.php.
$checkmateBackend = __DIR__;
for ($i = 0; $i < 6 && !is_file($checkmateBackend . '/src/bootstrap.php'); $i++) {
    $parent = dirname($checkmateBackend);
    if ($parent === $checkmateBackend) {
        break;
    }
    $checkmateBackend = $parent;
}

// Let the built-in server deliver real static files itself.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file($checkmateBackend . '/public' . $path)) {
        return false;
    }
}

require_once $checkmateBackend . '/src/bootstrap.php';

use Checkmate\Http\Kernel;
use Checkmate\Http\Request;

$request = Request::fromGlobals();
$kernel = new Kernel();
$response = $kernel->handle($request);
$response->send();
