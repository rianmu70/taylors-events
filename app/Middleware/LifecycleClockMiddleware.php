<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Logger;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Services\LifecycleService;
use Throwable;

/**
 * Moves events along with the clock: published events that have started
 * become ongoing, ongoing events that have ended become completed.
 *
 * There is no cron job to install. The first request after each interval
 * (config events.clock_seconds) does the work, which is two indexed UPDATEs.
 * It runs before the session is read, so the audit log records the change
 * as made by the system, not by whoever happened to load a page.
 */
final class LifecycleClockMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $stamp = BASE_PATH . '/storage/cache/lifecycle.clock';
        $interval = (int) config('events.clock_seconds', 20);
        if (!is_file($stamp) || time() - (int) @filemtime($stamp) >= $interval) {
            @touch($stamp);
            try {
                App::container()->get(LifecycleService::class)->tick();
            } catch (Throwable $e) {
                Logger::exception($e);   // never break a page because the clock failed
            }
        }
        return $next($request);
    }
}
