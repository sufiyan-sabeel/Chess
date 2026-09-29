<?php

declare(strict_types=1);

namespace Checkmate\Http;

use Checkmate\Support\Json;

/**
 * Immutable-ish HTTP request wrapper (built from PHP globals).
 */
final class Request
{
    /** @var array<string,mixed>|null|null null = not decoded yet */
    private ?array $jsonCache = null;

    /** @var array<string,mixed> attributes set by middleware (request id, user, cors origin) */
    public array $attributes = [];

    /**
     * @param array<string,string> $query lowercased header map => value
     * @param array<string,string> $headers lowercased header names
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly string $rawBody,
        private readonly string $remoteAddr,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $raw = file_get_contents('php://input');
        $raw = $raw === false ? '' : $raw;

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            array_map('strval', $_GET ?? []),
            $headers,
            $raw,
            $ip,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function ip(): string
    {
        return $this->remoteAddr;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function bodySize(): int
    {
        $declared = (int) ($this->header('content-length') ?? '0');
        return max($declared, strlen($this->rawBody));
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonCache === null) {
            $this->jsonCache = Json::decodeObject($this->rawBody);
        }
        return $this->jsonCache;
    }

    public function requestId(): ?string
    {
        $id = $this->attributes['request_id'] ?? null;
        return is_string($id) ? $id : null;
    }

    public function setRequestId(string $id): void
    {
        $this->attributes['request_id'] = $id;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return null;
        }
        return $m[1];
    }
}
