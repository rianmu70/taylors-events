<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Code that runs before (and optionally after) a controller.
 *
 * Example: an auth check returns a redirect to /login instead of calling
 * $next, so the controller never runs.
 *
 *     public function handle(Request $request, callable $next): Response
 *     {
 *         if (!signed_in) {
 *             return redirect('/login');
 *         }
 *         return $next($request);
 *     }
 *
 * Constructor arguments come from the route definition: 'role:admin,organizer'
 * creates new RoleMiddleware('admin', 'organizer').
 */
interface MiddlewareInterface
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next): Response;
}
