<?php

/**
 * Application bootstrap: autoloader + timezone + config sanity.
 * Included by public/index.php and by CLI entry points (bin/migrate.php, tests).
 */

declare(strict_types=1);

require_once __DIR__ . '/Autoloader.php';

\Checkmate\Autoloader::register();

require_once dirname(__DIR__) . '/config/config.php';

date_default_timezone_set('UTC');
