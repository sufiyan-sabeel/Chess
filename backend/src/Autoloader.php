<?php

declare(strict_types=1);

namespace Checkmate;

/**
 * Tiny PSR-4 style autoloader (no Composer in this environment).
 *
 *   Checkmate\Http\Request  ->  <src>/Http/Request.php
 */
final class Autoloader
{
    private const PREFIX = 'Checkmate\\';

    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, self::PREFIX)) {
                return;
            }
            $relative = substr($class, strlen(self::PREFIX));
            $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
