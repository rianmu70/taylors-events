<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RegistrationRepository
{
    private const WITH_EVENT = "
        SELECT r.*, e.title, e.slug, e.starts_at, e.ends_at, e.status AS event_status, e.capacity,
               e.confirmed_count, e.club_id, v.name AS venue_name, v.building AS venue_building,
               cl.name AS club_name, u.full_name, u.student_number, u.email
          FROM registrations r
          JOIN events e ON e.id = r.event_id
          JOIN venues v ON v.id = e.venue_id
          JOIN clubs cl ON cl.id = e.club_id
          JOIN users u  ON u.id = r.user_id";

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne(self::WITH_EVENT . ' WHERE r.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function findByCertificate(string $code): ?array
    {
        return Database::fetchOne(self::WITH_EVENT . ' WHERE r.certificate_code = ?', [$code]);
    }

    /** @return array<string,mixed>|null */
    public function forUserAndEvent(int $userId, int $eventId, bool $lock = false): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM registrations WHERE user_id = ? AND event_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$userId, $eventId],
        );
    }

    /** @return array<string,mixed>|null */
    public function lock(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM registrations WHERE id = ? FOR UPDATE', [$id]);
    }

    /** @return list<array<string,mixed>> newest event first, with what the user rated */
    public function forUser(int $userId): array
    {
        return Database::fetchAll(
            self::WITH_EVENT . ' WHERE r.user_id = ? ORDER BY e.starts_at DESC',
            [$userId],
        );
    }

    /** @return array<int,int> event id => rating given by this user */
    public function ratingsBy(int $userId): array
    {
        $map = [];
        foreach (Database::fetchAll('SELECT event_id, rating FROM event_ratings WHERE user_id = ?', [$userId]) as $row) {
            $map[(int) $row['event_id']] = (int) $row['rating'];
        }
        return $map;
    }

    /** Place in the queue, 1 being next. */
    public function waitlistPosition(int $eventId, int $registrationId): int
    {
        return 1 + (int) Database::fetchValue(
            "SELECT COUNT(*) FROM registrations w JOIN registrations me ON me.id = ?
              WHERE w.event_id = ? AND w.status = 'waitlisted'
                AND (w.queued_at < me.queued_at OR (w.queued_at = me.queued_at AND w.id < me.id))",
            [$registrationId, $eventId],
        );
    }

    /** @return array<string,mixed>|null the longest waiting person, locked */
    public function nextWaiting(int $eventId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM registrations WHERE event_id = ? AND status = 'waitlisted'
              ORDER BY queued_at, id LIMIT 1 FOR UPDATE",
            [$eventId],
        );
    }

    public function insert(int $eventId, int $userId, string $status, string $nonce): int
    {
        return Database::insert(
            'INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (?, ?, ?, ?)',
            [$eventId, $userId, $status, $nonce],
        );
    }

    /** A cancelled row is reused when the same person books the same event again. */
    public function rebook(int $id, string $status, string $nonce): void
    {
        Database::execute(
            'UPDATE registrations SET status = ?, ticket_nonce = ?, queued_at = NOW(3),
                    cancelled_at = NULL, promoted_at = NULL WHERE id = ?',
            [$status, $nonce, $id],
        );
    }

    public function cancel(int $id, string $newNonce): void
    {
        Database::execute(
            "UPDATE registrations SET status = 'cancelled', cancelled_at = NOW(), ticket_nonce = ? WHERE id = ?",
            [$newNonce, $id],
        );
    }

    public function promote(int $id): void
    {
        Database::execute("UPDATE registrations SET status = 'confirmed', promoted_at = NOW() WHERE id = ?", [$id]);
    }

    public function checkIn(int $id, int $byUserId, string $certificateCode): void
    {
        Database::execute(
            'UPDATE registrations SET checked_in_at = NOW(), checked_in_by = ?, certificate_code = ? WHERE id = ?',
            [$byUserId, $certificateCode, $id],
        );
    }

    /** @return list<array<string,mixed>> everyone on the list of one event */
    public function attendees(int $eventId): array
    {
        return Database::fetchAll(
            "SELECT r.id, r.status, r.queued_at, r.checked_in_at, r.ticket_nonce,
                    u.full_name, u.email, u.student_number, f.code AS faculty_code
               FROM registrations r
               JOIN users u ON u.id = r.user_id
               LEFT JOIN faculties f ON f.id = u.faculty_id
              WHERE r.event_id = ? AND r.status <> 'cancelled'
              ORDER BY FIELD(r.status,'confirmed','waitlisted'), u.full_name",
            [$eventId],
        );
    }

    /** @return array<string,mixed>|null a confirmed or waitlisted ticket found by its short door code */
    public function findByDoorCode(int $eventId, string $code): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM registrations WHERE event_id = ? AND ticket_nonce LIKE ? AND status <> 'cancelled'",
            [$eventId, $code . '%'],
        );
    }

    /** @return array{confirmed:int, checked_in:int} */
    public function doorCounts(int $eventId): array
    {
        $row = Database::fetchOne(
            "SELECT COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
                    COALESCE(SUM(checked_in_at IS NOT NULL), 0) AS checked_in
               FROM registrations WHERE event_id = ?",
            [$eventId],
        );
        return ['confirmed' => (int) $row['confirmed'], 'checked_in' => (int) $row['checked_in']];
    }

    public function logScan(int $eventId, ?int $registrationId, int $scannedBy, string $result, string $payloadHash, ?string $ip): void
    {
        Database::execute(
            'INSERT INTO ticket_scans (event_id, registration_id, scanned_by, result, payload_hash, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$eventId, $registrationId, $scannedBy, $result, $payloadHash, $ip],
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentScans(int $eventId, int $limit = 8): array
    {
        return Database::fetchAll(
            'SELECT s.result, s.created_at, u.full_name
               FROM ticket_scans s
               LEFT JOIN registrations r ON r.id = s.registration_id
               LEFT JOIN users u ON u.id = r.user_id
              WHERE s.event_id = ? ORDER BY s.id DESC LIMIT ?',
            [$eventId, $limit],
        );
    }
}
