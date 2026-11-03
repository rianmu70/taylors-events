<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * GET /api/health: quick check that PHP, the database connection and the
 * schema are all working. Use it after setting up a new machine.
 */
final class HealthController
{
    public function show(Request $request): Response
    {
        $database = 'ok';
        $detail = null;
        try {
            // Proves the connection works, the schema is loaded and the
            // app user can read it.
            Database::fetchValue('SELECT 1 FROM events LIMIT 1');
        } catch (Throwable $e) {
            Logger::exception($e);
            $database = 'unavailable';
            $detail = $e->getMessage();
        }

        $data = [
            'app' => 'ok',
            'database' => $database,
            'php_version' => PHP_VERSION,
            'time' => date(DATE_ATOM),
        ];
        if (config('app.debug') === true) {
            $data['database_error'] = $detail;
            if ($database === 'ok') {
                $data['database_time'] = (string) Database::fetchValue('SELECT NOW()');
            }
        } else {
            unset($data['php_version']);
        }

        if ($database !== 'ok') {
            return Response::apiError('Database unavailable', 503, 'DB_UNAVAILABLE', $data);
        }
        return Response::apiSuccess($data);
    }
}
