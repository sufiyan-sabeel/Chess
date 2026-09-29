<?php

declare(strict_types=1);

namespace Checkmate\Http;

/**
 * Static route table with {param} segment support.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:array, meta:array}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string}|callable $handler @param array<string,mixed> $meta */
    public function add(string $method, string $pattern, array $handler, array $meta = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'meta' => $meta,
        ];
    }

    /**
     * @return array{route: array<string,mixed>, params: array<string,string>}|null
     */
    public function find(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $params = self::matchPattern($route['pattern'], $path);
            if ($params !== null) {
                return ['route' => $route, 'params' => $params];
            }
        }
        return null;
    }

    /** Any method matches this path? (used for CORS preflight routing) */
    public function matchesPath(string $path): bool
    {
        foreach ($this->routes as $route) {
            if (self::matchPattern($route['pattern'], $path) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,string>|null */
    private static function matchPattern(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', $pattern);
        $pathParts = explode('/', $path);

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $part, $m)) {
                $params[$m[1]] = rawurldecode($pathParts[$i]);
                continue;
            }
            if ($part !== $pathParts[$i]) {
                return null;
            }
        }
        return $params;
    }
}
