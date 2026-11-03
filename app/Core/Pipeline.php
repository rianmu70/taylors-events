<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runs middleware in order, then the core handler:
 * first(second(third(controller))).
 */
final class Pipeline
{
    /**
     * @param list<MiddlewareInterface> $middleware
     * @param callable(Request): Response $core
     */
    public static function run(array $middleware, Request $request, callable $core): Response
    {
        $next = $core;
        foreach (array_reverse($middleware) as $layer) {
            $next = static fn (Request $req): Response => $layer->handle($req, $next);
        }
        return $next($request);
    }
}
