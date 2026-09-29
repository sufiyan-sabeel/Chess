<?php

/**
 * Route table for docs/API.md v1 (only §1 + §2 are implemented in this phase).
 *
 * Entry shape: [METHOD, path, [ControllerClass, method], meta]
 * meta:
 *   auth   => true (required) | 'optional' | 'idempotent' (header required,
 *             revoked token tolerated — logout) | false/absent (public)
 *   rate   => 'login'|'register'|'verify'|'resend'|'forgot'|'reset'
 *             | 'default' (120/min, keyed by method+path) | false (disabled)
 *
 * Future phases (§3–§9) append their routes here; the schema for them
 * already ships in migrations/.
 */

declare(strict_types=1);

use Checkmate\Controllers\AccountController;
use Checkmate\Controllers\AuthController;
use Checkmate\Controllers\HealthController;

return [
    // --- §2 Health ---------------------------------------------------------
    ['GET',  '/api/v1/health', [HealthController::class, 'health'], []],
    ['GET',  '/api/v1/ready',  [HealthController::class, 'ready'],  []],

    // --- §1 Authentication -------------------------------------------------
    ['POST', '/api/v1/auth/register',            [AuthController::class, 'register'],            ['rate' => 'register']],
    ['POST', '/api/v1/auth/login',               [AuthController::class, 'login'],               ['rate' => 'login']],
    ['POST', '/api/v1/auth/refresh',             [AuthController::class, 'refresh'],             []],
    ['POST', '/api/v1/auth/logout',              [AuthController::class, 'logout'],              ['auth' => 'idempotent']],
    ['GET',  '/api/v1/auth/me',                  [AuthController::class, 'me'],                  ['auth' => true]],
    ['POST', '/api/v1/auth/verify-email',        [AuthController::class, 'verifyEmail'],         ['rate' => 'verify']],
    ['POST', '/api/v1/auth/resend-verification', [AuthController::class, 'resendVerification'],  ['auth' => 'optional', 'rate' => 'resend']],
    ['POST', '/api/v1/auth/forgot-password',     [AuthController::class, 'forgotPassword'],      ['rate' => 'forgot']],
    ['POST', '/api/v1/auth/reset-password',      [AuthController::class, 'resetPassword'],       ['rate' => 'reset']],
    ['DELETE', '/api/v1/account',                [AccountController::class, 'destroy'],          ['auth' => true]],
];
