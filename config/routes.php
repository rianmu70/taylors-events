<?php

declare(strict_types=1);

use App\Controllers\Admin\ApprovalController;
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\UserController;
use App\Controllers\Admin\VenueController;
use App\Controllers\Api\HealthController;
use App\Controllers\Organiser\CheckInController;
use App\Controllers\Organiser\EventController as OrganiserEvents;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\BookingController;
use App\Controllers\Web\EventController;
use App\Controllers\Web\ProfileController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\LifecycleClockMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\SessionMiddleware;
use App\Middleware\ThrottleMiddleware;

/**
 * Every URL of the application, in one file.
 *
 * The third argument is the access rule, checked on the server before the
 * controller runs:
 *   'auth'             signed in, any role (guests are sent to the sign-in page)
 *   'role:admin'       that role only; anyone else signed in gets 403
 *   'throttle:n,max,s' at most "max" requests per "s" seconds per address
 *
 * Every POST also passes the CSRF check, which is global.
 */
return static function (Router $router): void {
    $router->alias('auth', AuthMiddleware::class);
    $router->alias('role', RoleMiddleware::class);
    $router->alias('throttle', ThrottleMiddleware::class);

    $router->middleware([
        SecurityHeadersMiddleware::class,
        LifecycleClockMiddleware::class,
        SessionMiddleware::class,
        CsrfMiddleware::class,
    ]);

    $student = ['auth', 'role:student'];
    $organiser = ['auth', 'role:organizer'];
    $admin = ['auth', 'role:admin'];

    // Public
    $router->get('/', [EventController::class, 'index']);
    $router->get('/events', [EventController::class, 'index']);
    $router->get('/events/{slug}', [EventController::class, 'show']);
    $router->get('/verify/{code:[A-Za-z0-9]+}', [BookingController::class, 'verify']);
    $router->get('/api/health', [HealthController::class, 'show']);

    // Accounts
    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login'], ['throttle:login,30,60']);
    $router->get('/register', [AuthController::class, 'showRegister']);
    $router->post('/register', [AuthController::class, 'register'], ['throttle:register,30,60']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth']);
    $router->get('/forgot-password', [AuthController::class, 'showForgot']);
    $router->post('/forgot-password', [AuthController::class, 'forgot'], ['throttle:forgot,30,60']);
    $router->get('/reset-password/{token}', [AuthController::class, 'showReset']);
    $router->post('/reset-password/{token}', [AuthController::class, 'reset'], ['throttle:reset,30,60']);

    // Any signed-in user
    $router->get('/profile', [ProfileController::class, 'show'], ['auth']);
    $router->post('/profile', [ProfileController::class, 'update'], ['auth']);
    $router->post('/profile/password', [ProfileController::class, 'password'], ['auth']);
    $router->get('/notifications', [ProfileController::class, 'notifications'], ['auth']);

    // Student
    $router->post('/events/{slug}/book', [BookingController::class, 'book'], $student);
    $router->post('/events/{slug}/rate', [EventController::class, 'rate'], $student);
    $router->get('/my/registrations', [BookingController::class, 'index'], $student);
    $router->post('/my/registrations/{id:\d+}/cancel', [BookingController::class, 'cancel'], $student);
    $router->get('/my/tickets/{id:\d+}', [BookingController::class, 'ticket'], $student);
    $router->get('/my/certificates/{id:\d+}', [BookingController::class, 'certificate'], $student);

    // Organiser
    $router->get('/organise/events', [OrganiserEvents::class, 'index'], $organiser);
    $router->get('/organise/events/new', [OrganiserEvents::class, 'create'], $organiser);
    $router->post('/organise/events', [OrganiserEvents::class, 'store'], $organiser);
    $router->get('/organise/events/{id:\d+}/edit', [OrganiserEvents::class, 'edit'], $organiser);
    $router->post('/organise/events/{id:\d+}', [OrganiserEvents::class, 'update'], $organiser);
    $router->post('/organise/events/{id:\d+}/submit', [OrganiserEvents::class, 'submit'], $organiser);
    $router->post('/organise/events/{id:\d+}/delete', [OrganiserEvents::class, 'delete'], $organiser);
    $router->post('/organise/events/{id:\d+}/archive', [OrganiserEvents::class, 'archive'], $organiser);
    $router->get('/organise/events/{id:\d+}/attendees.csv', [OrganiserEvents::class, 'export'], $organiser);
    $router->get('/organise/events/{id:\d+}/check-in', [CheckInController::class, 'show'], $organiser);
    $router->post('/organise/events/{id:\d+}/check-in', [CheckInController::class, 'scan'], [...$organiser, 'throttle:scan,120,60']);

    // Administrator
    $router->get('/admin', [DashboardController::class, 'index'], $admin);
    $router->get('/admin/approvals', [ApprovalController::class, 'index'], $admin);
    $router->post('/admin/approvals/{id:\d+}/approve', [ApprovalController::class, 'approve'], $admin);
    $router->post('/admin/approvals/{id:\d+}/reject', [ApprovalController::class, 'reject'], $admin);
    $router->get('/admin/venues', [VenueController::class, 'index'], $admin);
    $router->get('/admin/venues/new', [VenueController::class, 'create'], $admin);
    $router->post('/admin/venues', [VenueController::class, 'store'], $admin);
    $router->get('/admin/venues/{id:\d+}/edit', [VenueController::class, 'edit'], $admin);
    $router->post('/admin/venues/{id:\d+}', [VenueController::class, 'update'], $admin);
    $router->post('/admin/venues/{id:\d+}/toggle', [VenueController::class, 'toggle'], $admin);
    $router->get('/admin/users', [UserController::class, 'index'], $admin);
    $router->post('/admin/users/{id:\d+}/role', [UserController::class, 'role'], $admin);
    $router->post('/admin/users/{id:\d+}/toggle', [UserController::class, 'toggle'], $admin);
    $router->get('/admin/audit', [AuditController::class, 'index'], $admin);
    $router->get('/admin/outbox', [AuditController::class, 'outbox'], $admin);
};
