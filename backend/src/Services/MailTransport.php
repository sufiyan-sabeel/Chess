<?php

declare(strict_types=1);

namespace Checkmate\Services;

/**
 * Outbound mail abstraction. No SMTP exists in this environment, so the only
 * implementation ships as NullMailTransport (see there). A real transport can
 * be added later without touching call sites.
 */
interface MailTransport
{
    /** False => callers must answer 503 MAIL_NOT_CONFIGURED (never pretend). */
    public function isConfigured(): bool;

    /**
     * @return MailResult sent (preview may be populated) or not_configured
     */
    public function send(string $to, string $subject, string $body): MailResult;
}
