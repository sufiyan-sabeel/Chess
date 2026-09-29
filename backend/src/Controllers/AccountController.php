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
 * docs/API.md §1 DELETE /account — hard delete with match orphaning.
 */
final class AccountController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function destroy(Request $request): Response
    {
        $input = $request->json();
        $errors = Validator::make($input, [
            'password' => 'required|string|max:1024',
        ]);
        if ($errors !== []) {
            throw ApiException::validation();
        }

        $this->auth->deleteAccount(
            (int) $request->attributes['user_id'],
            (string) $input['password'],
        );

        return JsonResponse::success(['ok' => true], 200);
    }
}
