<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Security\RateLimiter;

/** Route alias 'throttle': 'throttle:search,60,60' = at most 60 requests per 60 seconds per IP for the "search" group. */
final class ThrottleMiddleware implements MiddlewareInterface
{
    private int $max;
    private int $window;

    public function __construct(private string $name = 'default', string $max = '60', string $window = '60')
    {
        $this->max = max(1, (int) $max);
        $this->window = max(1, (int) $window);
    }

    public function handle(Request $request, callable $next): Response
    {
        $wait = RateLimiter::hit('route:' . $this->name . ':' . $request->ip(), $this->max, $this->window);
        if ($wait > 0) {
            throw new HttpException(429, '', ['Retry-After' => (string) $wait]);
        }
        return $next($request);
    }
}
