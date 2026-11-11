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
use App\Controllers\Web\ManagementController;
use App\Controllers\Web\HomeController;
use App\Controllers\Web\RegistrationController;
use App\Controllers\Web\EventController;
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
    $router->get('/events', [EventController::class, 'index']);
    $router->get('/events/{slug}', [EventController::class, 'show']);

    // Student booking and ticket pages
    $router->post('/events/{slug}/register', [RegistrationController::class, 'store'], ['auth', 'role:student']);
    $router->get('/my-registrations', [RegistrationController::class, 'index'], ['auth', 'role:student']);
    $router->get('/registrations/{id:\\d+}/confirmation', [RegistrationController::class, 'confirmation'], ['auth', 'role:student']);
    $router->get('/registrations/{id:\\d+}/ticket', [RegistrationController::class, 'ticket'], ['auth', 'role:student']);
    $router->post('/registrations/{id:\\d+}/cancel', [RegistrationController::class, 'cancel'], ['auth', 'role:student']);

    // Organizer and administrator event lifecycle
    $router->get('/organize/events', [ManagementController::class, 'index'], ['auth', 'role:organizer,admin']);
    $router->get('/admin/events/pending', [ManagementController::class, 'index'], ['auth', 'role:admin']);
    $router->get('/organize/events/create', [ManagementController::class, 'createForm'], ['auth', 'role:organizer,admin']);
    $router->post('/organize/events/create', [ManagementController::class, 'create'], ['auth', 'role:organizer,admin']);
    $router->get('/organize/events/{id:\\d+}/edit', [ManagementController::class, 'editForm'], ['auth', 'role:organizer,admin']);
    $router->post('/organize/events/{id:\\d+}/edit', [ManagementController::class, 'update'], ['auth', 'role:organizer,admin']);
    $router->post('/organize/events/{id:\\d+}/transition', [ManagementController::class, 'transition'], ['auth', 'role:organizer,admin']);
    $router->get('/organize/events/{id:\\d+}/check-in', [ManagementController::class, 'checkInForm'], ['auth', 'role:organizer,admin']);
    $router->post('/organize/events/{id:\\d+}/check-in', [ManagementController::class, 'checkIn'], ['auth', 'role:organizer,admin']);

    // Accounts
    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login'], ['throttle:auth,20,60']);
    $router->get('/register', [AuthController::class, 'showRegister']);
    $router->post('/register', [AuthController::class, 'register'], ['throttle:auth,20,60']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth']);

    // API: JSON {success, data, error, meta}
    $router->get('/api/health', [HealthController::class, 'show']);
};
