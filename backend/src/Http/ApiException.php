<?php

declare(strict_types=1);

namespace Checkmate\Http;

/**
 * Carries an application-level error into the single exception handler in App.
 * The message is always safe to show to clients (never SQL / paths / internals).
 */
final class ApiException extends \RuntimeException
{
    /** @param array<string,mixed> $extra extra keys merged into the error object */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message = 'The submitted request is invalid.'): self
    {
        return new self('VALIDATION_ERROR', $message, 400);
    }

    /**
     * VALIDATION_ERROR carrying the per-field messages (error.fields), which
     * the client can render next to its inputs.
     *
     * @param array<string,string> $fields
     */
    public static function invalidFields(array $fields, string $message = 'The submitted request is invalid.'): self
    {
        return new self('VALIDATION_ERROR', $message, 400, ['fields' => $fields]);
    }

    /** 429 helper with the mandatory Retry-After header. */
    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            'RATE_LIMITED',
            'Too many requests. Please try again later.',
            429,
            [],
            ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self('UNAUTHORIZED', $message, 401);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): self
    {
        return new self('NOT_FOUND', $message, 404);
    }

    public static function internal(string $message = 'An unexpected error occurred.'): self
    {
        return new self('INTERNAL_ERROR', $message, 500);
    }
}
