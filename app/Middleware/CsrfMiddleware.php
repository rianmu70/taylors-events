<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Security\Csrf;

/** GLOBAL middleware, register it after SessionMiddleware. GET/HEAD/OPTIONS pass; everything that changes data is checked. */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $changesData = in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($changesData && (!Csrf::originOk($request) || !Csrf::verify($request))) {
            throw new HttpException(419);
        }
        return $next($request);
    }
}
