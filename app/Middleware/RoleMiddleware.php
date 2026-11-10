<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use LogicException;

/**
 * Route alias 'role': 'role:admin' or 'role:organizer,admin'.
 * Roles are explicit, so admin is not allowed through an organizer-only route unless listed.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $roles;

    public function __construct(string ...$roles)
    {
        foreach ($roles as $role) {
            if (!in_array($role, Auth::ROLES, true)) {
                throw new LogicException("Unknown role \"$role\" in route middleware.");
            }
        }
        $this->roles = array_values($roles);
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            $back = $request->isMethod('GET') ? '?next=' . rawurlencode($request->path) : '';
            return redirect('/login' . $back);
        }
        if (!Auth::hasRole(...$this->roles)) {
            throw new HttpException(403);
        }
        return $next($request);
    }
}
