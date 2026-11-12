<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The two lifecycle moves that are caused by time rather than by a person.
 * Both statements use idx_events_status_start and touch only the rows that
 * are due, and both pass through the state machine trigger like any other
 * status change.
 */
final class LifecycleService
{
    /** @return array{started:int, completed:int} */
    public function tick(): array
    {
        $started = Database::execute(
            "UPDATE events SET status = 'ongoing'
              WHERE status = 'published' AND starts_at <= NOW() AND deleted_at IS NULL",
        );
        $completed = Database::execute(
            "UPDATE events SET status = 'completed'
              WHERE status = 'ongoing' AND ends_at <= NOW() AND deleted_at IS NULL",
        );
        return ['started' => $started, 'completed' => $completed];
    }
}
