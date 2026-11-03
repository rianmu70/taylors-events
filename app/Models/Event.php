<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Reads events. Every query uses placeholders (?) so input is never pasted into SQL.
 */
final class Event
{
    /**
     * Published or ongoing events that have not ended yet, soonest first.
     * Uses idx_events_status_start (status, starts_at).
     *
     * @return list<array<string, mixed>>
     */
    public static function upcoming(int $limit = 6): array
    {
        $limit = max(1, min(50, $limit));

        return Database::fetchAll(
            "SELECT e.public_id, e.slug, e.title, e.summary, e.starts_at, e.ends_at,
                    e.capacity, e.confirmed_count, e.status,
                    c.name AS category_name, c.color_hex AS category_color,
                    v.name AS venue_name, v.building AS venue_building,
                    cl.name AS club_name
               FROM events e
               JOIN event_categories c ON c.id = e.category_id
               JOIN venues v ON v.id = e.venue_id
               JOIN clubs cl ON cl.id = e.club_id
              WHERE e.status IN ('published', 'ongoing')
                AND e.deleted_at IS NULL
                AND e.ends_at > NOW()
              ORDER BY e.starts_at
              LIMIT ?",
            [$limit],
        );
    }
}
