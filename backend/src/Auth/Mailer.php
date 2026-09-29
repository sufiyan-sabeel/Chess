<?php

declare(strict_types=1);

namespace Checkmate\Auth;

/**
 * Mail transport abstraction.
 *
 * HONEST LIMITATION: this environment has no SMTP server, no API credentials
 * and no network e-mail provider. Production therefore CANNOT send e-mail yet —
 * until a real provider is implemented (SMTP/API driver behind this interface),
 * non-development environments report `isConfigured() === false` and the
 * affected endpoints answer 503 MAIL_NOT_CONFIGURED (docs/API.md §1 resend).
 *
 * Development uses FileMailer, which appends messages (including the raw
 * single-use token) to backend/var/mail.log so tests and developers can read
 * them. That file must never be exposed in production — FileMailer refuses to
 * run when APP_ENV=production.
 */
interface Mailer
{
    /** Whether a real (or dev-file) transport can actually deliver. */
    public function isConfigured(): bool;

    /**
     * Deliver a message. No-op when not configured (callers check first).
     *
     * @param array<string,mixed> $meta optional structured context (token, expiry…)
     * @throws \RuntimeException when the transport is configured but delivery fails
     */
    public function send(string $to, string $subject, string $body, array $meta = []): void;

    /**
     * Development-only preview payload ({token, expires_at, …}) attached to
     * error/success payloads when the transport is not configured and
     * APP_ENV=development. Returns null in any other environment.
     *
     * @return array<string,mixed>|null
     */
    public function devPreview(array $meta): ?array;
}
