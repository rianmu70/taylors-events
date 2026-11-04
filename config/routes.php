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
use App\Controllers\Web\AuthController;
use App\Controllers\Web\HomeController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SessionMiddleware;
use App\Middleware\ThrottleMiddleware;

return static function (Router $router): void {
    // Middleware aliases
    $router->alias('auth', AuthMiddleware::class);
    $router->alias('role', RoleMiddleware::class);          // 'role:admin' or 'role:organizer,admin'
    $router->alias('throttle', ThrottleMiddleware::class);  // 'throttle:name,max,seconds'

    // Middleware for every request. Order matters: the session must exist before the CSRF check.
    $router->middleware([SessionMiddleware::class, CsrfMiddleware::class]);
    // $router->middleware([\App\Middleware\SecurityHeadersMiddleware::class]);

    // Pages
    $router->get('/', [HomeController::class, 'index']);

    // Accounts
    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login'], ['throttle:auth,20,60']);
    $router->get('/register', [AuthController::class, 'showRegister']);
    $router->post('/register', [AuthController::class, 'register'], ['throttle:auth,20,60']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth']);

    // API: JSON {success, data, error, meta}
    $router->get('/api/health', [HealthController::class, 'show']);
};
