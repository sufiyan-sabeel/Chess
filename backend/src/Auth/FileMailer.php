<?php

declare(strict_types=1);

namespace Checkmate\Auth;

use Checkmate\Support\Log;

/**
 * File/dev mail transport: appends each message as one JSON line to
 * backend/var/mail.log (git-ignored). The message body contains the raw
 * single-use token, which is exactly the dev-only retrieval path tests use.
 *
 * NOT for production — MailerFactory refuses to construct it when
 * APP_ENV=production; a real SMTP/API provider must implement Mailer instead.
 * The log file is size-bounded (rotated at ~1 MB) so it cannot fill the disk.
 */
final class FileMailer implements Mailer
{
    private const MAX_BYTES = 1048576; // 1 MB

    public function __construct(
        private readonly string $path,
        private readonly string $from,
    ) {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $body, array $meta = []): void
    {
        if (config('app.env') === 'production') {
            // Defence in depth: never let a file transport run in production —
            // it would put live tokens on disk in cleartext.
            throw new \RuntimeException('FileMailer is a development-only transport.');
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create mail log directory: {$dir}");
        }
        $this->rotateIfNeeded();

        $line = json_encode([
            'ts' => gmdate('c'),
            'from' => $this->from,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ] + $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Cannot append to mail log: {$this->path}");
        }
    }

    public function devPreview(array $meta): ?array
    {
        return null; // configured transport: no preview needed
    }

    private function rotateIfNeeded(): void
    {
        $size = @filesize($this->path);
        if ($size !== false && $size > self::MAX_BYTES) {
            @rename($this->path, $this->path . '.1');
        }
    }
}
