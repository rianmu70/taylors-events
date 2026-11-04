<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

/** Route alias 'auth'. Guests are sent to /login (pages) or get a 401 (API). */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }
        if ($request->wantsJson()) {
            throw new HttpException(401);
        }
        $back = $request->isMethod('GET') ? '?next=' . rawurlencode($request->path) : '';
        return redirect('/login' . $back);
    }
}
