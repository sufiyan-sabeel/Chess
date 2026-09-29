<?php

declare(strict_types=1);

namespace Checkmate\Support;

use Checkmate\Http\ApiException;

/**
 * JSON helpers for request parsing.
 */
final class Json
{
    /**
     * Decode a request body into an associative array.
     * Empty body -> []. Malformed / non-object JSON -> VALIDATION_ERROR.
     *
     * @return array<string,mixed>
     */
    public static function decodeObject(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 32, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('VALIDATION_ERROR', 'The submitted request is invalid.', 400);
        }
        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
