<?php

declare(strict_types=1);

namespace Checkmate\Auth;

use Checkmate\Support\Log;

/**
 * Placeholder transport used when MAIL_TRANSPORT is unset/unknown (the default)
 * and in production until a real provider exists.
 *
 * Nothing is delivered. Endpoints consult isConfigured():
 *   - resend-verification -> 503 MAIL_NOT_CONFIGURED (docs/API.md §1)
 *   - development only: devPreview() surfaces {token, expires_at} so the flow
 *     stays testable; production never sees a token.
 */
final class NullMailer implements Mailer
{
    public function __construct(private readonly string $reason = 'no transport configured')
    {
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $to, string $subject, string $body, array $meta = []): void
    {
        // Intentionally silent: callers must check isConfigured() first.
        Log::event('mail.dropped', ['reason' => $this->reason, 'subject' => $subject]);
    }

    public function devPreview(array $meta): ?array
    {
        if (config('app.env') === 'development') {
            return $meta === [] ? null : $meta;
        }
        return null;
    }
}
