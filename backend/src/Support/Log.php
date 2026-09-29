<?php

declare(strict_types=1);

namespace Checkmate\Support;

/**
 * Minimal file logger.
 *
 * NEVER log passwords, tokens, or e-mail addresses — log only IDs and event
 * names (see docs/BACKEND.md). The one exception is the development-only mail
 * preview written by NullMailTransport, which goes to a separate file and only
 * when APP_ENV=development.
 */
final class Log
{
    public static function event(string $event, array $context = []): void
    {
        self::write(self::path(), $event, $context);
    }

    public static function exception(\Throwable $e, array $context = []): void
    {
        self::write(self::path(), 'exception', $context + [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    }

    /** Development-only mail body preview (APP_ENV=development only, see NullMailTransport). */
    public static function mailPreview(string $body): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $line = sprintf("[%s] %s\n", gmdate('c'), $body);
        @file_put_contents($dir . '/mail-dev.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function path(): string
    {
        return (string) config('paths.log', dirname(__DIR__, 2) . '/var/log/app.log');
    }

    private static function write(string $path, string $event, array $context): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $line = json_encode(
            ['ts' => gmdate('c'), 'event' => $event] + $context,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
