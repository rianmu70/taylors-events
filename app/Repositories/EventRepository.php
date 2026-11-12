<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * All SQL that reads or writes events. No business rules live here.
 */
final class EventRepository
{
    /** One event with the names a page needs, so templates never run queries. */
    private const SELECT = "
        SELECT e.*, c.name AS category_name, c.slug AS category_slug,
               v.name AS venue_name, v.building AS venue_building, v.floor_label AS venue_floor,
               v.capacity AS venue_capacity, v.is_accessible AS venue_accessible,
               cl.name AS club_name, u.full_name AS organizer_name,
               (SELECT COUNT(*) FROM registrations r
                 WHERE r.event_id = e.id AND r.status = 'waitlisted') AS waitlisted_count
          FROM events e
          JOIN event_categories c ON c.id = e.category_id
          JOIN venues v           ON v.id = e.venue_id
          JOIN clubs cl           ON cl.id = e.club_id
          JOIN users u            ON u.id = e.organizer_id
         WHERE e.deleted_at IS NULL";

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne(self::SELECT . ' AND e.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return Database::fetchOne(self::SELECT . ' AND e.slug = ?', [$slug]);
    }

    /**
     * Lock one event row until the transaction ends. Every booking,
     * cancellation and promotion for an event starts here, which is what
     * makes them happen one at a time.
     *
     * @return array<string,mixed>|null
     */
    public function lock(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
    }

    /**
     * The browse page: events open to the public, soonest first.
     *
     * @param array{q?:string,category?:string,when?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function browse(array $filters, int $page, int $perPage): array
    {
        $where = " AND e.status IN ('published','ongoing')";
        $params = [];

        // Every word typed must appear somewhere in the title, summary or description.
        // A FULLTEXT index was measured for this and was slower: see docs/PERFORMANCE.md.
        foreach (self::searchWords($filters['q'] ?? '') as $word) {
            $where .= ' AND (e.title LIKE ? OR e.summary LIKE ? OR e.description LIKE ?)';
            $like = '%' . addcslashes($word, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if (($filters['category'] ?? '') !== '') {
            $where .= ' AND c.slug = ?';
            $params[] = $filters['category'];
        }
        $where .= match ($filters['when'] ?? '') {
            'today' => ' AND e.starts_at < CURDATE() + INTERVAL 1 DAY AND e.ends_at >= CURDATE()',
            'week' => ' AND e.starts_at < CURDATE() + INTERVAL 7 DAY',
            'month' => ' AND e.starts_at < CURDATE() + INTERVAL 30 DAY',
            default => '',
        };

        $total = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM events e JOIN event_categories c ON c.id = e.category_id
              WHERE e.deleted_at IS NULL' . $where,
            $params,
        );
        $rows = Database::fetchAll(
            self::SELECT . $where . ' ORDER BY e.starts_at, e.id LIMIT ? OFFSET ?',
            [...$params, $perPage, max(0, ($page - 1) * $perPage)],
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> events of the clubs this user runs, newest first */
    public function hostedBy(int $userId): array
    {
        return Database::fetchAll(
            self::SELECT . " AND e.club_id IN (SELECT club_id FROM club_members
                                               WHERE user_id = ? AND role IN ('advisor','president','committee'))
             ORDER BY FIELD(e.status,'ongoing','draft','pending_approval','published','completed','archived'), e.starts_at DESC",
            [$userId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function pending(): array
    {
        return Database::fetchAll(self::SELECT . " AND e.status = 'pending_approval' ORDER BY e.updated_at");
    }

    /** @return list<array<string,mixed>> */
    public function recentlyDecided(int $limit): array
    {
        return Database::fetchAll(
            self::SELECT . ' AND e.approved_at IS NOT NULL ORDER BY e.approved_at DESC LIMIT ?',
            [$limit],
        );
    }

    /** @param array<string,mixed> $data */
    public function insertDraft(array $data): int
    {
        return Database::insert(
            "INSERT INTO events (organizer_id, club_id, category_id, venue_id, title, slug, summary, description,
                                 starts_at, ends_at, capacity, status)
             VALUES (:organizer_id, :club_id, :category_id, :venue_id, :title, :slug, :summary, :description,
                     :starts_at, :ends_at, :capacity, 'draft')",
            $data,
        );
    }

    /** @param array<string,mixed> $data */
    public function updateDetails(int $id, array $data): void
    {
        Database::execute(
            'UPDATE events SET club_id = :club_id, category_id = :category_id, venue_id = :venue_id, title = :title,
                    summary = :summary, description = :description, starts_at = :starts_at, ends_at = :ends_at,
                    capacity = :capacity
              WHERE id = :id',
            $data + ['id' => $id],
        );
    }

    public function setStatus(int $id, string $status): void
    {
        Database::execute('UPDATE events SET status = ? WHERE id = ?', [$status, $id]);
    }

    public function approve(int $id, int $adminId): void
    {
        Database::execute("UPDATE events SET status = 'published', approved_by = ? WHERE id = ?", [$adminId, $id]);
    }

    public function reject(int $id, string $reason): void
    {
        Database::execute("UPDATE events SET status = 'draft', rejection_reason = ? WHERE id = ?", [$reason, $id]);
    }

    public function softDelete(int $id): void
    {
        Database::execute('UPDATE events SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    public function slugExists(string $slug): bool
    {
        return Database::fetchValue('SELECT 1 FROM events WHERE slug = ?', [$slug]) !== null;
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function categories(): array
    {
        return Database::fetchAll('SELECT id, name, slug FROM event_categories ORDER BY name');
    }

    /** @return list<array<string,mixed>> */
    public function ratings(int $eventId, int $limit = 6): array
    {
        return Database::fetchAll(
            'SELECT er.rating, er.comment, er.created_at, u.full_name
               FROM event_ratings er JOIN users u ON u.id = er.user_id
              WHERE er.event_id = ? ORDER BY er.created_at DESC LIMIT ?',
            [$eventId, $limit],
        );
    }

    /** @return array{average: ?float, total: int} */
    public function ratingSummary(int $eventId): array
    {
        $row = Database::fetchOne('SELECT AVG(rating) AS average, COUNT(*) AS total FROM event_ratings WHERE event_id = ?', [$eventId]);
        return ['average' => $row['average'] === null ? null : round((float) $row['average'], 1), 'total' => (int) $row['total']];
    }

    public function addRating(int $eventId, int $userId, int $rating, ?string $comment): void
    {
        Database::execute(
            'INSERT INTO event_ratings (event_id, user_id, rating, comment) VALUES (?, ?, ?, ?)',
            [$eventId, $userId, $rating, $comment],
        );
    }

    public function hasRated(int $eventId, int $userId): bool
    {
        return Database::fetchValue('SELECT 1 FROM event_ratings WHERE event_id = ? AND user_id = ?', [$eventId, $userId]) !== null;
    }

    /** @return list<string> up to six distinct words of two letters or more */
    private static function searchWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $matches);
        return array_slice(array_values(array_unique($matches[0])), 0, 6);
    }
}
