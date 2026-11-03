<?php

declare(strict_types=1);

/*
 * Every URL in the app is listed here.
 *
 *   $router->get('/path', [Controller::class, 'method']);
 *   $router->post('/events/{slug}/register', [BookingController::class, 'store'], ['auth']);
 *
 * Third argument = middleware for that route (see app/Core/MiddlewareInterface.php).
 * Register short names first:  $router->alias('auth', AuthMiddleware::class);
 */

use App\Controllers\Api\HealthController;
use App\Controllers\Web\HomeController;
use App\Core\Router;

return static function (Router $router): void {
    // Middleware aliases (Phase 2)
    // $router->alias('auth', \App\Middleware\AuthMiddleware::class);
    // $router->alias('role', \App\Middleware\RoleMiddleware::class);
    // $router->alias('csrf', \App\Middleware\CsrfMiddleware::class);

    // Middleware for every request (Phase 2)
    // $router->middleware([\App\Middleware\SecurityHeadersMiddleware::class]);

    // Pages
    $router->get('/', [HomeController::class, 'index']);

    // API: JSON {success, data, error, meta}
    $router->get('/api/health', [HealthController::class, 'show']);
};
