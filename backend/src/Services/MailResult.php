<?php

declare(strict_types=1);

namespace Checkmate\Services;

/**
 * Result of a MailTransport::send() call.
 */
final class MailResult
{
    private function __construct(
        public readonly bool $configured,
        public readonly ?string $preview,
    ) {
    }

    public static function sent(?string $preview = null): self
    {
        return new self(true, $preview);
    }

    public static function notConfigured(): self
    {
        return new self(false, null);
    }
}
