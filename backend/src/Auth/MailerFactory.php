<?php

declare(strict_types=1);

namespace Checkmate\Auth;

use Checkmate\Support\Log;

/**
 * Chooses the mail transport from MAIL_TRANSPORT:
 *
 *   file   -> FileMailer, but ONLY outside production (dev/test: messages land
 *             in backend/var/mail.log so tokens are retrievable in dev only).
 *             In production it degrades to NullMailer with a warning — a real
 *             SMTP/API provider has to be implemented before shipping e-mail.
 *   smtp   -> not implemented yet (no provider/credentials in this
 *             environment) -> NullMailer.
 *   null / anything else -> NullMailer (503 MAIL_NOT_CONFIGURED upstream).
 */
final class MailerFactory
{
    public static function create(): Mailer
    {
        $transport = strtolower((string) config('mail.transport', 'null'));
        $isProduction = config('app.env') === 'production';

        if ($transport === 'file') {
            if ($isProduction) {
                Log::event('mail.transport_unsafe', ['transport' => 'file']);
                return new NullMailer('file transport refused in production');
            }
            return new FileMailer(
                (string) config('paths.var', dirname(__DIR__, 2) . '/var') . '/mail.log',
                (string) config('mail.from', 'no-reply@checkmate.local'),
            );
        }

        if ($transport === 'smtp') {
            return new NullMailer('smtp transport not implemented (no provider configured)');
        }

        return new NullMailer("transport '{$transport}' not configured");
    }
}
