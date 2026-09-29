<?php

declare(strict_types=1);

namespace Checkmate\Http\Controllers;

use Checkmate\Auth\AuthService;
use Checkmate\Database\Connection;
use Checkmate\Http\ApiException;
use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Support\Validator;

/**
 * docs/API.md §1 Authentication — thin HTTP layer over AuthService:
 * decode -> delegate -> envelope. All business/security rules live in
 * Checkmate\Auth\AuthService.
 */
final class AuthController
{
    private ?AuthService $service = null;

    private function service(): AuthService
    {
        return $this->service ??= new AuthService(Connection::pdo());
    }

    /** POST /auth/register -> 201 {user, access_token, refresh_token, expires_in} */
    public function register(Request $request): Response
    {
        return JsonResponse::success($this->service()->register($request->json()), 201);
    }

    /** POST /auth/login -> 200 {access_token, refresh_token, expires_in, user} */
    public function login(Request $request): Response
    {
        return JsonResponse::success($this->service()->login($request->json()));
    }

    /** POST /auth/refresh -> 200 {access_token, refresh_token, expires_in} */
    public function refresh(Request $request): Response
    {
        return JsonResponse::success($this->service()->refresh($this->requiredString($request, 'refresh_token')));
    }

    /** POST /auth/logout **[auth]** -> 200 {ok:true} (idempotent) */
    public function logout(Request $request): Response
    {
        return JsonResponse::success($this->service()->logout($this->requiredString($request, 'refresh_token')));
    }

    /** GET /auth/me **[auth]** -> 200 {user:{...ratings...}} */
    public function me(Request $request): Response
    {
        $user = $request->attributes['user'] ?? null;
        if (!is_array($user)) {
            throw ApiException::unauthorized();
        }
        return JsonResponse::success($this->service()->me($user));
    }

    /** POST /auth/verify-email -> 200 {ok:true} (uniform TOKEN_INVALID otherwise) */
    public function verifyEmail(Request $request): Response
    {
        return JsonResponse::success($this->service()->verifyEmail($this->requiredString($request, 'token')));
    }

    /** POST /auth/resend-verification (body {email} or **[auth]**) -> 200 {ok:true} / 503 */
    public function resendVerification(Request $request): Response
    {
        $authUser = $request->attributes['user'] ?? null;

        if (is_array($authUser)) {
            return JsonResponse::success($this->service()->resendVerification($authUser, null));
        }

        $email = $this->requiredString($request, 'email');
        $fields = Validator::make(['email' => $email], ['email' => 'required|email']);
        if ($fields !== []) {
            throw ApiException::invalidFields($fields);
        }
        return JsonResponse::success($this->service()->resendVerification(null, $email));
    }

    /** POST /auth/forgot-password -> always 200 {ok:true} (enumeration-safe) */
    public function forgotPassword(Request $request): Response
    {
        $email = $this->requiredString($request, 'email');
        $fields = Validator::make(['email' => $email], ['email' => 'required|email']);
        if ($fields !== []) {
            throw ApiException::invalidFields($fields);
        }
        return JsonResponse::success($this->service()->forgotPassword($email));
    }

    /** POST /auth/reset-password -> 200 {ok:true}, revokes every session */
    public function resetPassword(Request $request): Response
    {
        return JsonResponse::success($this->service()->resetPassword(
            $this->requiredString($request, 'token'),
            $this->requiredString($request, 'password'),
        ));
    }

    // ------------------------------------------------------------- helpers

    /** @return string */
    private function requiredString(Request $request, string $field): string
    {
        $body = $request->json();
        $value = $body[$field] ?? null;
        if ($value === null || $value === '' || !is_string($value)) {
            throw ApiException::invalidFields([$field => 'This field is required.']);
        }
        if (strlen($value) > 4096) {
            throw ApiException::invalidFields([$field => 'This field is too long.']);
        }
        return $value;
    }
}
