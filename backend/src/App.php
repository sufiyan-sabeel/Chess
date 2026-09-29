<?php

declare(strict_types=1);

namespace Checkmate;

use Checkmate\Controllers\AccountController;
use Checkmate\Controllers\AuthController;
use Checkmate\Controllers\HealthController;
use Checkmate\Http\ApiException;
use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Http\Router;
use Checkmate\Middleware\AuthMiddleware;
use Checkmate\Middleware\CorsMiddleware;
use Checkmate\Middleware\RateLimitMiddleware;
use Checkmate\Middleware\RequestIdMiddleware;
use Checkmate\Middleware\SecurityHeadersMiddleware;
use Checkmate\Services\AuthService;
use Checkmate\Services\MailService;
use Checkmate\Services\RateLimiter;
use Checkmate\Services\TokenService;
use Checkmate\Support\Log;

/**
 * HTTP kernel: routing -> middleware pipeline -> controller -> envelope.
 *
 * Every response (success, handled error, or crash) passes through
 * finalize(), which guarantees X-Request-Id, security headers and CORS
 * headers are present exactly once with consistent values.
 */
final class App
{
    private Router $router;
    private ?AuthService $authService = null;
    private ?RateLimiter $rateLimiter = null;

    /** @param array<int, array> $routes route table from routes/api.php */
    public function __construct(private readonly array $routes)
    {
        $this->router = new Router();
        foreach ($routes as $route) {
            $this->router->add($route[0], $route[1], $route[2], $route[3] ?? []);
        }
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (ApiException $e) {
            $response = JsonResponse::fromException($e);
        } catch (\Throwable $e) {
            // Never leak SQL / paths / internals to the client.
            Log::exception($e, [
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
            $response = JsonResponse::error(
                'INTERNAL_ERROR',
                'An unexpected error occurred.',
                500,
            );
        }

        return $this->finalize($response, $request);
    }

    private function dispatch(Request $request): Response
    {
        // CORS preflight: any known path answers, regardless of method.
        if ($request->method() === 'OPTIONS' && $this->router->matchesPath($request->path())) {
            return $this->pipeline($request, ['rate' => false], []);
        }

        $match = $this->router->find($request->method(), $request->path());
        if ($match === null) {
            throw ApiException::notFound();
        }

        $maxBytes = (int) config('security.body_max_bytes', 65536);
        if ($request->bodySize() > $maxBytes) {
            throw new ApiException(
                'VALIDATION_ERROR',
                'Request body exceeds the ' . $maxBytes . ' byte limit.',
                413,
            );
        }

        return $this->pipeline($request, $match['route']['meta'], $match['params']);
    }

    /**
     * @param array<string,mixed> $meta @param array<string,string> $params
     */
    private function pipeline(Request $request, array $meta, array $params): Response
    {
        $requestId = new RequestIdMiddleware();
        $security = new SecurityHeadersMiddleware();
        $cors = new CorsMiddleware();
        $rateLimit = new RateLimitMiddleware($this->rateLimiter());
        $auth = new AuthMiddleware(new TokenService());

        // Built inside-out; arrow functions capture the previous stage by value.
        $next = fn(Request $r): Response => $this->invoke($r, $meta, $params);
        $next = fn(Request $r): Response => $auth->handle($r, $meta, $next);
        $next = fn(Request $r): Response => $rateLimit->handle($r, $meta, $next);
        $next = fn(Request $r): Response => $cors->handle($r, $meta, $next);
        $next = fn(Request $r): Response => $security->handle($r, $meta, $next);
        $next = fn(Request $r): Response => $requestId->handle($r, $meta, $next);

        return $next($request);
    }

    /** @param array<string,mixed> $meta @param array<string,string> $params */
    private function invoke(Request $request, array $meta, array $params): Response
    {
        $route = $this->router->find($request->method(), $request->path());
        if ($route === null) {
            // OPTIONS: answer with 204, headers come from finalize().
            if ($request->method() === 'OPTIONS') {
                return new Response(204, '');
            }
            throw ApiException::notFound();
        }

        [$class, $method] = $route['route']['handler'];
        $controller = $this->controller($class);
        return $controller->{$method}($request, $params);
    }

    private function controller(string $class): object
    {
        return match ($class) {
            AuthController::class => new AuthController($this->auth()),
            AccountController::class => new AccountController($this->auth()),
            HealthController::class => new HealthController(),
            default => new $class(),
        };
    }

    private function auth(): AuthService
    {
        return $this->authService ??= new AuthService(new TokenService(), new MailService());
    }

    private function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter ??= new RateLimiter();
    }

    /**
     * Idempotent response decoration: request id, security headers, CORS.
     * Also applied to responses produced by the exception handler, which
     * bypass the middleware unwind path.
     */
    private function finalize(Response $response, Request $request): Response
    {
        $requestId = $request->requestId();
        if ($requestId !== null && !isset($response->headers['X-Request-Id'])) {
            $response->withHeader('X-Request-Id', $requestId);
        }

        if (!isset($response->headers['X-Content-Type-Options'])) {
            $response->withHeader('X-Content-Type-Options', 'nosniff');
            $response->withHeader('X-Frame-Options', 'DENY');
            $response->withHeader('Referrer-Policy', 'no-referrer');
            $response->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
            $response->withHeader('Cross-Origin-Resource-Policy', 'same-site');
            $response->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
            $response->withHeader('Cache-Control', 'no-store');
            $response->withHeader('X-Api-Version', (string) config('app.version', '1.0.0'));
        }

        $origin = $request->attributes['cors_origin'] ?? null;
        if (is_string($origin) && $origin !== '' && !isset($response->headers['Access-Control-Allow-Origin'])) {
            $isPreflight = ($request->attributes['cors_preflight'] ?? false) === true;
            CorsMiddleware::decorate($response, $origin, $isPreflight);
        }

        return $response;
    }
}
