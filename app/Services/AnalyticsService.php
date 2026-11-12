<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Numbers for the administrator's dashboard. Read only.
 */
final class AnalyticsService
{
    /** @return array<string,int|float|null> */
    public function headline(): array
    {
        $events = Database::fetchOne(
            "SELECT COALESCE(SUM(status IN ('published','ongoing')), 0) AS live,
                    COALESCE(SUM(status = 'pending_approval'), 0) AS pending,
                    COALESCE(SUM(status IN ('completed','archived')), 0) AS finished,
                    COALESCE(SUM(IF(status IN ('published','ongoing'), capacity, 0)), 0) AS seats_offered,
                    COALESCE(SUM(IF(status IN ('published','ongoing'), confirmed_count, 0)), 0) AS seats_taken
               FROM events WHERE deleted_at IS NULL",
        );
        $attendance = Database::fetchOne(
            "SELECT COUNT(*) AS booked, COALESCE(SUM(r.checked_in_at IS NOT NULL), 0) AS attended
               FROM registrations r JOIN events e ON e.id = r.event_id
              WHERE r.status = 'confirmed' AND e.status IN ('completed','archived') AND e.deleted_at IS NULL",
        );
        $booked = (int) $attendance['booked'];
        return [
            'live' => (int) $events['live'],
            'pending' => (int) $events['pending'],
            'finished' => (int) $events['finished'],
            'seats_offered' => (int) $events['seats_offered'],
            'seats_taken' => (int) $events['seats_taken'],
            'waiting' => (int) Database::fetchValue("SELECT COUNT(*) FROM registrations WHERE status = 'waitlisted'"),
            'students' => (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NULL"),
            'show_up_rate' => $booked === 0 ? null : round(100 * (int) $attendance['attended'] / $booked, 1),
            'average_rating' => ($avg = Database::fetchValue('SELECT AVG(rating) FROM event_ratings')) === null ? null : round((float) $avg, 2),
        ];
    }

    /** @return list<array<string,mixed>> open events closest to selling out */
    public function scarcest(int $limit = 6): array
    {
        return Database::fetchAll(
            "SELECT s.*, cl.name AS club_name FROM v_event_stats s JOIN clubs cl ON cl.id = s.club_id
              WHERE s.status IN ('published','ongoing')
              ORDER BY (s.capacity - s.confirmed_count) = 0 DESC, s.waitlisted_count DESC, s.fill_rate_pct DESC LIMIT ?",
            [$limit],
        );
    }

    /** @return list<array{name:string, events:int, bookings:int}> */
    public function byCategory(): array
    {
        return Database::fetchAll(
            "SELECT c.name, COUNT(DISTINCT e.id) AS events, COALESCE(SUM(e.confirmed_count), 0) AS bookings
               FROM event_categories c
               LEFT JOIN events e ON e.category_id = c.id AND e.deleted_at IS NULL AND e.status <> 'draft'
              GROUP BY c.id, c.name ORDER BY bookings DESC, c.name",
        );
    }

    /** @return list<array<string,mixed>> */
    public function byFaculty(): array
    {
        return Database::fetchAll('SELECT * FROM v_faculty_engagement ORDER BY registrations DESC, name');
    }

    /** @return list<array{day:string, bookings:int}> one entry per day, oldest first, gaps filled with 0 */
    public function bookingsPerDay(int $days = 14): array
    {
        $rows = Database::fetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS bookings FROM registrations
              WHERE created_at >= CURDATE() - INTERVAL ? DAY GROUP BY DATE(created_at)",
            [$days - 1],
        );
        $byDay = array_column($rows, 'bookings', 'day');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i day"));
            $series[] = ['day' => $day, 'bookings' => (int) ($byDay[$day] ?? 0)];
        }
        return $series;
    }

    /** @return list<array<string,mixed>> */
    public function venueLoad(): array
    {
        return Database::fetchAll(
            "SELECT v.name, v.capacity, COUNT(e.id) AS events,
                    COALESCE(ROUND(SUM(TIMESTAMPDIFF(MINUTE, e.starts_at, e.ends_at)) / 60, 1), 0) AS hours
               FROM venues v
               LEFT JOIN events e ON e.venue_id = v.id AND e.deleted_at IS NULL
                    AND e.status IN ('published','ongoing','completed','archived')
              WHERE v.deleted_at IS NULL
              GROUP BY v.id, v.name, v.capacity ORDER BY hours DESC, v.name LIMIT 8",
        );
    }
}
