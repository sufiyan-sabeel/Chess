<?php

declare(strict_types=1);

namespace Checkmate\Http;

use Checkmate\Http\Controllers\AuthController;
use Checkmate\Http\Controllers\HealthController;
use Checkmate\Http\Middleware\AuthenticateMiddleware;
use Checkmate\Http\Middleware\BodyLimitMiddleware;
use Checkmate\Http\Middleware\CorsMiddleware;
use Checkmate\Http\Middleware\ErrorHandlingMiddleware;
use Checkmate\Http\Middleware\RateLimitMiddleware;
use Checkmate\Http\Middleware\RequestIdMiddleware;
use Checkmate\Http\Middleware\SecurityHeadersMiddleware;

/**
 * The HTTP kernel: one request in, one envelope out.
 *
 * Pipeline (outermost -> handler):
 *
 *   RequestId        assigns/echoes X-Request-Id
 *   SecurityHeaders  nosniff / CSP / Referrer-Policy / no-store on the result
 *   Cors             allowlist + OPTIONS preflight short-circuit
 *   ErrorHandling    ApiException -> documented code; Throwable -> generic 500
 *   BodyLimit        BODY_MAX_BYTES cap
 *   RateLimit        per IP+route fixed window, 429 + Retry-After
 *   Authenticate     Bearer token for routes marked [auth]
 *   dispatch         controller call, JSON envelope, 404 for unknown routes
 *
 * Paths are matched with and without the /api/v1 prefix (config app.base_path),
 * so both /api/v1/auth/login and /auth/login work.
 */
final class Kernel
{
    private Router $router;
    private RequestIdMiddleware $requestId;
    private SecurityHeadersMiddleware $security;
    private CorsMiddleware $cors;
    private ErrorHandlingMiddleware $errors;
    private BodyLimitMiddleware $bodyLimit;
    private RateLimitMiddleware $rateLimit;
    private AuthenticateMiddleware $authenticate;

    public function __construct()
    {
        $this->router = self::buildRouter();
        $this->requestId = new RequestIdMiddleware();
        $this->security = new SecurityHeadersMiddleware();
        $this->cors = new CorsMiddleware();
        $this->errors = new ErrorHandlingMiddleware(
            (bool) config('app.debug', false) && (string) config('app.env', 'development') !== 'production'
        );
        $this->bodyLimit = new BodyLimitMiddleware((int) config('security.body_max_bytes', 65536));
        $this->rateLimit = new RateLimitMiddleware();
        $this->authenticate = new AuthenticateMiddleware();
    }

    public function handle(Request $request): Response
    {
        // Resolve the route before the pipeline: rate limiting and auth read
        // the route meta from request attributes.
        $match = $this->router->find($request->method(), self::normalizePath($request->path()));
        if ($match !== null) {
            $request->attributes['route'] = [
                'meta' => $match['route']['meta'],
                'handler' => $match['route']['handler'],
                'params' => $match['params'],
            ];
        }

        $response = $this->requestId->handle($request, fn(Request $r): Response =>
            $this->security->handle($r, fn(Request $r): Response =>
                $this->cors->handle($r, fn(Request $r): Response =>
                    $this->errors->handle($r, fn(Request $r): Response =>
                        $this->bodyLimit->handle($r, fn(Request $r): Response =>
                            $this->rateLimit->handle($r, fn(Request $r): Response =>
                                $this->authenticate->handle($r, fn(Request $r): Response =>
                                    $this->dispatch($r)
                                )
                            )
                        )
                    )
                )
            )
        );

        if (!isset($response->headers['Content-Type'])) {
            $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $route = $request->attributes['route'] ?? null;
        if (!is_array($route) || !isset($route['handler'])) {
            throw ApiException::notFound('The requested endpoint was not found.');
        }

        [$class, $method] = $route['handler'];
        $controller = self::controller($class);
        $response = $controller->{$method}($request, $route['params']);

        if (!$response instanceof Response) {
            throw new \RuntimeException("Controller {$class}::{$method} did not return a Response");
        }
        if (!isset($response->headers['Content-Type'])) {
            $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        return $response;
    }

    /** docs/API.md §1 + §2 endpoint table. */
    private static function buildRouter(): Router
    {
        $router = new Router();

        $router->add('POST', '/auth/register', [AuthController::class, 'register'], ['rate' => 'register']);
        $router->add('POST', '/auth/login', [AuthController::class, 'login'], ['rate' => 'login']);
        $router->add('POST', '/auth/refresh', [AuthController::class, 'refresh'], ['rate' => 'default']);
        $router->add('POST', '/auth/logout', [AuthController::class, 'logout'], ['rate' => 'default', 'auth' => true]);
        $router->add('GET', '/auth/me', [AuthController::class, 'me'], ['rate' => 'default', 'auth' => true]);
        $router->add('POST', '/auth/verify-email', [AuthController::class, 'verifyEmail'], ['rate' => 'verify']);
        $router->add('POST', '/auth/resend-verification', [AuthController::class, 'resendVerification'], ['rate' => 'resend', 'auth' => 'optional']);
        $router->add('POST', '/auth/forgot-password', [AuthController::class, 'forgotPassword'], ['rate' => 'forgot']);
        $router->add('POST', '/auth/reset-password', [AuthController::class, 'resetPassword'], ['rate' => 'reset']);

        // docs/API.md §1 DELETE /account — controller shipped by the concurrent
        // agent (src/Controllers/AccountController); it needs constructor
        // injection, handled by self::controller() below.
        $router->add('DELETE', '/account', [\Checkmate\Controllers\AccountController::class, 'destroy'], ['rate' => 'default', 'auth' => true]);

        $router->add('GET', '/health', [HealthController::class, 'health'], ['rate' => 'default']);
        $router->add('GET', '/ready', [HealthController::class, 'ready'], ['rate' => 'default']);

        return $router;
    }

    /**
     * Build a controller. Most controllers are dependency-free; a few (e.g.
     * the concurrent agent's AccountController) require the Services stack —
     * satisfy required constructor parameters instead of crashing.
     */
    private static function controller(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = self::service((string) $type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }
            throw new \RuntimeException("Cannot construct {$class}: no service for \${$parameter->getName()}");
        }
        return $reflection->newInstanceArgs($args);
    }

    /** Shared services for controllers from the concurrently-shipped stack. */
    private static function service(string $type): object
    {
        return match ($type) {
            \Checkmate\Services\AuthService::class => new \Checkmate\Services\AuthService(
                new \Checkmate\Services\TokenService(),
                new \Checkmate\Services\MailService(),
            ),
            \Checkmate\Services\TokenService::class => new \Checkmate\Services\TokenService(),
            \Checkmate\Services\MailService::class => new \Checkmate\Services\MailService(),
            default => new $type(),
        };
    }

    /** Strip the /api/v1 prefix if present; collapse trailing slashes. */
    private static function normalizePath(string $path): string
    {
        $base = (string) config('app.base_path', '/api/v1');
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
            if ($path === '') {
                $path = '/';
            }
        }
        return $path;
    }
}
