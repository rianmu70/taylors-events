<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class VenueRepository
{
    public const TYPES = ['hall', 'lecture_theatre', 'theatre', 'classroom', 'lab', 'studio', 'lounge', 'breakout'];

    /** @return list<array<string,mixed>> venues an organiser can pick */
    public function bookable(): array
    {
        return Database::fetchAll('SELECT * FROM venues WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name');
    }

    /** @return list<array<string,mixed>> every venue, with how many upcoming events hold it */
    public function allWithLoad(): array
    {
        return Database::fetchAll(
            "SELECT v.*, (SELECT COUNT(*) FROM events e
                           WHERE e.venue_id = v.id AND e.deleted_at IS NULL AND e.ends_at > NOW()
                             AND e.status IN ('pending_approval','published','ongoing')) AS upcoming
               FROM venues v WHERE v.deleted_at IS NULL ORDER BY v.name",
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM venues WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** Lock the venue row: two organisers asking for the same room now wait for each other. */
    public function lock(int $id): void
    {
        Database::fetchOne('SELECT id FROM venues WHERE id = ? FOR UPDATE', [$id]);
    }

    /**
     * The first event that already holds this room during [start, end).
     * Two intervals overlap exactly when s1 < e2 AND e1 > s2. The trigger
     * trg_events_bu runs the same test, so this query only exists to give
     * the organiser a helpful message before the database has to refuse.
     * FOR SHARE reads the latest committed rows rather than a snapshot.
     *
     * @return array<string,mixed>|null
     */
    public function clash(int $venueId, string $start, string $end, ?int $exceptEventId): ?array
    {
        return Database::fetchOne(
            "SELECT e.id, e.title, e.starts_at, e.ends_at, cl.name AS club_name
               FROM events e JOIN clubs cl ON cl.id = e.club_id
              WHERE e.venue_id = ?
                AND e.status IN ('pending_approval','published','ongoing')
                AND e.deleted_at IS NULL
                AND e.starts_at < ? AND e.ends_at > ?
                AND e.id <> ?
              ORDER BY e.starts_at LIMIT 1 FOR SHARE",
            [$venueId, $end, $start, $exceptEventId ?? 0],
        );
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): int
    {
        return Database::insert(
            'INSERT INTO venues (code, name, building, floor_label, capacity, venue_type, has_projector, is_accessible)
             VALUES (:code, :name, :building, :floor_label, :capacity, :venue_type, :has_projector, :is_accessible)',
            $data,
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE venues SET code = :code, name = :name, building = :building, floor_label = :floor_label,
                    capacity = :capacity, venue_type = :venue_type, has_projector = :has_projector,
                    is_accessible = :is_accessible WHERE id = :id',
            $data + ['id' => $id],
        );
    }

    public function setActive(int $id, bool $active): void
    {
        Database::execute('UPDATE venues SET is_active = ? WHERE id = ?', [$active, $id]);
    }

    /** The largest event that currently holds this room, so capacity is never cut below it. */
    public function largestBooking(int $id): int
    {
        return (int) Database::fetchValue(
            "SELECT COALESCE(MAX(capacity), 0) FROM events
              WHERE venue_id = ? AND deleted_at IS NULL AND ends_at > NOW()
                AND status IN ('draft','pending_approval','published','ongoing')",
            [$id],
        );
    }
}
