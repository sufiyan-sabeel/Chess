<?php

declare(strict_types=1);

namespace Checkmate\Controllers;

use Checkmate\Http\ApiException;
use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Services\AuthService;
use Checkmate\Support\Validator;

/**
 * docs/API.md §1 Authentication. Controllers stay thin: validate input,
 * delegate to AuthService, shape the response.
 */
final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(Request $request): Response
    {
        $input = $request->json();
        $errors = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|password',
            'display_name' => 'required|string|regex:/^[A-Za-z0-9_]{3,20}$/',
        ]);
        if ($errors !== []) {
            throw ApiException::validation();
        }

        $data = $this->auth->register(
            (string) $input['email'],
            (string) $input['password'],
            (string) $input['display_name'],
            $request->ip(),
        );

        return JsonResponse::success($data, 201);
    }

    public function login(Request $request): Response
    {
        $input = $request->json();
        $errors = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|string|max:1024',
        ]);
        if ($errors !== []) {
            // Malformed input must not be distinguishable from a valid request
            // with bad credentials (enumeration safety).
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        $data = $this->auth->login(
            (string) $input['email'],
            (string) $input['password'],
            $request->ip(),
        );

        return JsonResponse::success($data, 200);
    }

    public function refresh(Request $request): Response
    {
        $input = $request->json();
        $errors = Validator::make($input, [
            'refresh_token' => 'required|string|max:128',
        ]);
        if ($errors !== []) {
            throw ApiException::validation();
        }

        $data = $this->auth->refresh((string) $input['refresh_token'], $request->ip());
        return JsonResponse::success($data, 200);
    }

    /**
     * Idempotent (docs/API.md §1): a retry whose access token already died
     * with the session family still answers 200 — revocation is driven by the
     * refresh token in the body, and unknown tokens are a no-op.
     */
    public function logout(Request $request): Response
    {
        $input = $request->json();
        $refreshToken = isset($input['refresh_token']) && is_string($input['refresh_token'])
            ? $input['refresh_token']
            : null;

        $this->auth->logout(
            isset($request->attributes['user_id']) ? (int) $request->attributes['user_id'] : null,
            isset($request->attributes['family_id']) ? (string) $request->attributes['family_id'] : null,
            $refreshToken,
        );

        return JsonResponse::success(['ok' => true], 200);
    }

    public function me(Request $request): Response
    {
        $data = $this->auth->me((int) $request->attributes['user_id']);
        return JsonResponse::success($data, 200);
    }

    public function verifyEmail(Request $request): Response
    {
        $input = $request->json();
        $errors = Validator::make($input, [
            'token' => 'required|string|max:128',
        ]);
        if ($errors !== []) {
            // Malformed body: same TOKEN_INVALID shape as an unknown token.
            throw new ApiException('TOKEN_INVALID', 'This verification link is invalid or has already been used.', 400);
        }

        $this->auth->verifyEmail((string) $input['token']);
        return JsonResponse::success(['ok' => true], 200);
    }

    public function resendVerification(Request $request): Response
    {
        $input = $request->json();
        $authenticatedUserId = $request->attributes['user_id'] ?? null;
        $email = null;

        if ($authenticatedUserId === null) {
            $errors = Validator::make($input, ['email' => 'required|email']);
            if ($errors !== []) {
                throw ApiException::validation();
            }
            $email = (string) $input['email'];
        }

        $data = $this->auth->resendVerification(
            $email,
            $authenticatedUserId === null ? null : (int) $authenticatedUserId,
        );

        return JsonResponse::success($data, 200);
    }

    public function forgotPassword(Request $request): Response
    {
        $input = $request->json();
        $errors = Validator::make($input, ['email' => 'required|email']);
        if ($errors !== []) {
            // Generic validation error, identical for existing/unknown e-mails.
            throw ApiException::validation();
        }

        $data = $this->auth->forgotPassword((string) $input['email']);
        return JsonResponse::success($data, 200);
    }

    public function resetPassword(Request $request): Response
    {
        $input = $request->json();
        $errors = Validator::make($input, [
            'token' => 'required|string|max:128',
            'password' => 'required|password',
        ]);
        if ($errors !== []) {
            if (!isset($input['token']) || !is_string($input['token']) || $input['token'] === '') {
                throw new ApiException('TOKEN_INVALID', 'This reset link is invalid or has already been used.', 400);
            }
            throw ApiException::validation();
        }

        $this->auth->resetPassword((string) $input['token'], (string) $input['password']);
        return JsonResponse::success(['ok' => true], 200);
    }
}
