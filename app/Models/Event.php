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

    /** @return list<array<string, mixed>> */
    public static function browse(): array
    {
        return Database::fetchAll(
            "SELECT e.public_id, e.slug, e.title, e.summary, e.starts_at, e.ends_at,
                    e.capacity, e.confirmed_count, e.status,
                    c.name AS category_name, c.color_hex AS category_color,
                    v.name AS venue_name, v.building AS venue_building, cl.name AS club_name
               FROM events e
               JOIN event_categories c ON c.id = e.category_id
               JOIN venues v ON v.id = e.venue_id
               JOIN clubs cl ON cl.id = e.club_id
              WHERE e.status IN ('published', 'ongoing') AND e.deleted_at IS NULL AND e.ends_at > NOW()
              ORDER BY e.starts_at, e.id",
        );
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::fetchOne(
            "SELECT e.*, c.name AS category_name, c.color_hex AS category_color,
                    v.name AS venue_name, v.building AS venue_building, cl.name AS club_name
               FROM events e
               JOIN event_categories c ON c.id = e.category_id
               JOIN venues v ON v.id = e.venue_id
               JOIN clubs cl ON cl.id = e.club_id
                            WHERE e.slug = ? AND e.deleted_at IS NULL
                                AND e.status IN ('published', 'ongoing')",
            [$slug],
        );
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT id, public_id, slug, title, organizer_id, club_id, category_id, venue_id,
                    summary, description, learning_outcomes, starts_at, ends_at,
                    registration_closes_at, capacity, confirmed_count, status
               FROM events WHERE id = ? AND deleted_at IS NULL',
            [$id],
        );
    }

    /** @return array<string, mixed>|null */
    public static function registration(int $registrationId): ?array
    {
        return Database::fetchOne(
            'SELECT r.*, e.title, e.slug, e.starts_at, e.ends_at, e.venue_id,
                    v.name AS venue_name, v.building AS venue_building
               FROM registrations r JOIN events e ON e.id = r.event_id
               JOIN venues v ON v.id = e.venue_id WHERE r.id = ?',
            [$registrationId],
        );
    }

    /** @return list<array<string, mixed>> */
    public static function registrationsForUser(int $userId): array
    {
        return Database::fetchAll(
            'SELECT r.id AS registration_id, r.status AS registration_status, r.queued_at,
                    r.promoted_at, r.cancelled_at, r.checked_in_at, e.id AS event_id,
                    e.title, e.slug, e.starts_at, e.ends_at, e.status AS event_status,
                    e.venue_id, v.name AS venue_name, v.building AS venue_building
               FROM registrations r JOIN events e ON e.id = r.event_id
               JOIN venues v ON v.id = e.venue_id WHERE r.user_id = ?
              ORDER BY e.starts_at DESC, r.id DESC',
            [$userId],
        );
    }

    /** @return list<array<string, mixed>> */
    public static function manageableBy(int $userId, string $role): array
    {
        if ($role === 'admin') {
            return Database::fetchAll(
                'SELECT e.id, e.title, e.slug, e.status, e.starts_at, e.confirmed_count,
                        e.capacity, e.organizer_id, e.club_id, cl.name AS club_name
                   FROM events e JOIN clubs cl ON cl.id = e.club_id
                  WHERE e.deleted_at IS NULL ORDER BY e.updated_at DESC',
            );
        }
        return Database::fetchAll(
            "SELECT DISTINCT e.id, e.title, e.slug, e.status, e.starts_at, e.confirmed_count,
                    e.capacity, e.organizer_id, e.club_id, cl.name AS club_name
               FROM events e JOIN clubs cl ON cl.id = e.club_id
               LEFT JOIN club_members cm ON cm.club_id = e.club_id AND cm.user_id = ?
              WHERE e.deleted_at IS NULL AND (e.organizer_id = ?
                    OR cm.role IN ('advisor','president','committee'))
              ORDER BY e.updated_at DESC",
            [$userId, $userId],
        );
    }
}

