<?php

declare(strict_types=1);

namespace Checkmate\Http\Controllers;

use Checkmate\Database\Connection;
use Checkmate\Http\ApiException;
use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Support\Clock;

/**
 * docs/API.md §2 Health.
 *  - /health never touches the database (liveness)
 *  - /ready pings the database (readiness), 503 when unreachable
 */
final class HealthController
{
    public function health(Request $request): Response
    {
        return JsonResponse::success([
            'status' => 'ok',
            'time' => Clock::isoNow(),
            'version' => (string) config('app.version', '1.0.0'),
        ]);
    }

    public function ready(Request $request): Response
    {
        if (!Connection::ping()) {
            throw new ApiException('SERVICE_UNAVAILABLE', 'Database is not reachable.', 503);
        }
        return JsonResponse::success([
            'status' => 'ready',
            'time' => Clock::isoNow(),
            'version' => (string) config('app.version', '1.0.0'),
        ]);
    }
}
