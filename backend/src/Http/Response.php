<?php

declare(strict_types=1);

namespace Checkmate\Http;

/**
 * HTTP response value object.
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public array $headers = [],
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            if ($this->status !== 204 && !isset($this->headers['Content-Type'])) {
                header('Content-Type: application/json; charset=UTF-8', true);
            }
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        http_response_code($this->status);
        if ($this->status !== 204 && $this->body !== '') {
            echo $this->body;
        }
    }
}
