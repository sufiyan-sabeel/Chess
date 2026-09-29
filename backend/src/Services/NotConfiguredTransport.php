<?php

declare(strict_types=1);

namespace Checkmate\Services;

/**
 * Unknown/unwired transport: honestly "not configured" in every environment.
 */
final class NotConfiguredTransport implements MailTransport
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $to, string $subject, string $body): MailResult
    {
        return MailResult::notConfigured();
    }
}
