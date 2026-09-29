<?php

declare(strict_types=1);

namespace Checkmate\Services;

/**
 * Builds and dispatches transactional e-mails.
 *
 * Call sites MUST handle MailResult->configured === false with
 * 503 MAIL_NOT_CONFIGURED (docs/API.md §1) — never pretend mail was sent.
 */
final class MailService
{
    private MailTransport $transport;

    public function __construct(?MailTransport $transport = null)
    {
        $this->transport = $transport ?? self::resolve();
    }

    /**
     * Pick the transport for MAIL_TRANSPORT:
     *   null | file | log | dev => NullMailTransport (development-only: logs the
     *                              body to var/log/mail-dev.log and returns it
     *                              as dev_preview; reports "not configured"
     *                              outside development, so production still
     *                              answers 503 MAIL_NOT_CONFIGURED)
     *   anything else            => NotConfiguredTransport (503 everywhere)
     */
    public static function resolve(): MailTransport
    {
        $configured = strtolower(trim((string) config('mail.transport', 'null')));
        $devLocal = ['null', 'file', 'log', 'dev'];

        return in_array($configured, $devLocal, true)
            ? new NullMailTransport()
            : new NotConfiguredTransport();
    }

    public function isConfigured(): bool
    {
        return $this->transport->isConfigured();
    }

    public function sendVerificationEmail(string $email, string $token): MailResult
    {
        $link = (string) config('app.url') . '/verify-email?token=' . urlencode($token);

        $body = implode("\n", [
            'Hi,',
            '',
            'Confirm your e-mail address to activate your Checkmate account.',
            '',
            'Open this link to verify:',
            $link,
            '',
            'The link expires in 24 hours.',
            'If you did not create a Checkmate account, you can ignore this message.',
        ]);

        return $this->transport->send($email, 'Confirm your Checkmate e-mail', $body);
    }

    public function sendPasswordResetEmail(string $email, string $token): MailResult
    {
        $link = (string) config('app.url') . '/reset-password?token=' . urlencode($token);

        $body = implode("\n", [
            'Hi,',
            '',
            'A password reset was requested for your Checkmate account.',
            '',
            'Open this link to choose a new password:',
            $link,
            '',
            'The link expires in 1 hour and can be used once.',
            'If you did not request this, you can ignore this message.',
        ]);

        return $this->transport->send($email, 'Reset your Checkmate password', $body);
    }
}
