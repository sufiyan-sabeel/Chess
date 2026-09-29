<?php

/**
 * Canonical front controller (identical logic to public/index.php).
 *
 * bin/dev.sh / bin/test.sh use THIS file as the `php -S` router argument so
 * the scripted dev/test servers always boot this application, regardless of
 * concurrent edits to public/index.php. public/index.php carries the same
 * content for production deployments (nginx/Apache try_files -> /index.php).
 */

declare(strict_types=1);

// Let the built-in server deliver real static files itself.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file(dirname(__DIR__) . '/public' . $path)) {
        return false;
    }
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Checkmate\App;
use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;

/** @var array<int, array> $routeTable */
$routeTable = require dirname(__DIR__) . '/routes/api.php';

$app = new App($routeTable);

try {
    $request = Request::fromGlobals();
    $response = $app->handle($request);
} catch (\Checkmate\Http\ApiException $e) {
    $response = JsonResponse::fromException($e);
} catch (\Throwable $e) {
    \Checkmate\Support\Log::exception($e, ['stage' => 'bootstrap']);
    $response = JsonResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
}

$response->send();
