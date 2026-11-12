<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Response;
use Throwable;

/** GET /api/health : is the application up and can it reach its database? Used by CI and the smoke tests. */
final class HealthController
{
    public function show(): Response
    {
        try {
            $ok = (int) Database::fetchValue('SELECT 1') === 1;
        } catch (Throwable) {
            $ok = false;
        }
        return Response::json(['status' => $ok ? 'ok' : 'degraded', 'database' => $ok, 'php' => PHP_VERSION], $ok ? 200 : 503);
    }
}
