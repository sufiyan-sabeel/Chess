<?php

declare(strict_types=1);

namespace Checkmate\Controllers;

use Checkmate\Database\Connection;
use Checkmate\Http\JsonResponse;
use Checkmate\Http\Request;
use Checkmate\Http\Response;
use Checkmate\Support\Clock;

/**
 * docs/API.md §2 Health.
 *  /health — liveness, never touches the DB.
 *  /ready  — DB reachability, 200/503.
 */
final class HealthController
{
    public function health(Request $request): Response
    {
        return JsonResponse::success([
            'status' => 'ok',
            'time' => Clock::isoNow(),
            'version' => (string) config('app.version', '1.0.0'),
        ], 200);
    }

    public function ready(Request $request): Response
    {
        if (Connection::ping()) {
            return JsonResponse::success([
                'status' => 'ready',
                'time' => Clock::isoNow(),
            ], 200);
        }

        return JsonResponse::error('INTERNAL_ERROR', 'Service not ready.', 503);
    }
}
