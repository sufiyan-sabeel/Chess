<?php

declare(strict_types=1);

namespace Checkmate\Http;

use Checkmate\Support\Json;

/**
 * JSON envelope helpers: { success, data, error } exactly as docs/API.md.
 */
final class JsonResponse
{
    public static function success(mixed $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, Json::encode([
            'success' => true,
            'data' => $data,
            'error' => null,
        ]), $headers);
    }

    public static function error(string $code, string $message, int $status = 400, array $headers = [], array $extra = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }

        $response = new Response($status, Json::encode([
            'success' => false,
            'data' => null,
            'error' => $error,
        ]), $headers);
        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }
        return $response;
    }

    public static function fromException(ApiException $e): Response
    {
        return self::error($e->errorCode, $e->getMessage(), $e->status, $e->headers, $e->extra);
    }
}
