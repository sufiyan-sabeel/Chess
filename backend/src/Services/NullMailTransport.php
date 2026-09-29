<?php

declare(strict_types=1);

namespace Checkmate\Services;

use Checkmate\Support\Log;

/**
 * No SMTP exists here. This transport:
 *  - APP_ENV=development: logs the message body to var/log/mail-dev.log and
 *    returns it as a preview (the API surfaces it as `dev_preview`).
 *  - anything else: reports "not configured" — endpoints then answer
 *    503 MAIL_NOT_CONFIGURED. Mail is never pretended to be sent.
 */
final class NullMailTransport implements MailTransport
{
    public function isConfigured(): bool
    {
        return config('app.env') === 'development';
    }

    public function send(string $to, string $subject, string $body): MailResult
    {
        if (!self::logsBody()) {
            return MailResult::notConfigured();
        }

        Log::mailPreview(sprintf("To: %s\nSubject: %s\n\n%s\n", $to, $subject, $body));

        return MailResult::sent($body);
    }

    private static function logsBody(): bool
    {
        // Body logging is development-only (never in production).
        return config('app.env') === 'development';
    }
}
