<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Auth\Session;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

/**
 * GLOBAL middleware, register it first. Starts the session, loads the signed-in user onto the
 * request and tells the audit triggers who is acting.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        Session::start();
        Auth::resolve($request);
        try {
            return $next($request);
        } finally {
            // Save and release the session row before the response is sent.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }
}
