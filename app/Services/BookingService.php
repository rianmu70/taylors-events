<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\TicketSigner;
use DomainException;

final class BookingService
{
    /** @return array{registration_id:int, status:string} */
    public static function reserve(int $eventId, int $userId): array
    {
        return Database::transaction(static function () use ($eventId, $userId): array {
            $event = Database::fetchOne(
                'SELECT id, starts_at, registration_closes_at, capacity, confirmed_count, status
                   FROM events WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$eventId],
            );
            if ($event === null || $event['status'] !== 'published') {
                throw new DomainException('event_unavailable');
            }
            if (strtotime((string) ($event['registration_closes_at'] ?? $event['starts_at'])) <= time()) {
                throw new DomainException('registration_closed');
            }

            $registration = Database::fetchOne(
                'SELECT id, status FROM registrations WHERE event_id = ? AND user_id = ? FOR UPDATE',
                [$eventId, $userId],
            );
            if ($registration !== null && $registration['status'] !== 'cancelled') {
                throw new DomainException('already_registered');
            }

            $confirmed = (int) $event['confirmed_count'] < (int) $event['capacity'];
            $status = $confirmed ? 'confirmed' : 'waitlisted';
            $nonce = TicketSigner::newNonce();

            if ($confirmed) {
                Database::execute('UPDATE events SET confirmed_count = confirmed_count + 1 WHERE id = ?', [$eventId]);
            }

            if ($registration === null) {
                $registrationId = Database::insert(
                    'INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (?, ?, ?, ?)',
                    [$eventId, $userId, $status, $nonce],
                );
            } else {
                $registrationId = (int) $registration['id'];
                Database::execute(
                    'UPDATE registrations
                        SET status = ?, ticket_nonce = ?, queued_at = NOW(3), promoted_at = NULL,
                            cancelled_at = NULL, checked_in_at = NULL, checked_in_by = NULL,
                            certificate_code = NULL, idempotency_key = NULL
                      WHERE id = ?',
                    [$status, $nonce, $registrationId],
                );
            }

            return ['registration_id' => $registrationId, 'status' => $status];
        });
    }

    /** @return array{promoted_registration_id:?int} */
    public static function cancel(int $eventId, int $userId): array
    {
        return Database::transaction(static function () use ($eventId, $userId): array {
            $event = Database::fetchOne(
                     'SELECT id, starts_at, registration_closes_at, status, confirmed_count
                   FROM events WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$eventId],
            );
            if ($event === null) {
                throw new DomainException('event_unavailable');
            }
            if (strtotime((string) $event['starts_at']) <= time()) {
                throw new DomainException('event_started');
            }

            $registration = Database::fetchOne(
                'SELECT id, status, checked_in_at FROM registrations
                  WHERE event_id = ? AND user_id = ? FOR UPDATE',
                [$eventId, $userId],
            );
            if ($registration === null || $registration['status'] === 'cancelled') {
                throw new DomainException('registration_not_active');
            }
            if ($registration['checked_in_at'] !== null) {
                throw new DomainException('already_checked_in');
            }

            $wasConfirmed = $registration['status'] === 'confirmed';
            Database::execute(
                'UPDATE registrations SET status = \'cancelled\', cancelled_at = NOW() WHERE id = ?',
                [(int) $registration['id']],
            );

            if (!$wasConfirmed) {
                return ['promoted_registration_id' => null];
            }

            Database::execute(
                'UPDATE events SET confirmed_count = confirmed_count - 1 WHERE id = ? AND confirmed_count > 0',
                [$eventId],
            );

            $registrationOpen = $event['status'] === 'published'
                && strtotime((string) ($event['registration_closes_at'] ?? $event['starts_at'])) > time();
            if (!$registrationOpen) {
                return ['promoted_registration_id' => null];
            }

            $next = Database::fetchOne(
                "SELECT id FROM registrations
                  WHERE event_id = ? AND status = 'waitlisted'
                  ORDER BY queued_at, id LIMIT 1 FOR UPDATE",
                [$eventId],
            );
            if ($next === null) {
                return ['promoted_registration_id' => null];
            }

            Database::execute(
                "UPDATE registrations
                    SET status = 'confirmed', ticket_nonce = ?, promoted_at = NOW()
                  WHERE id = ? AND status = 'waitlisted'",
                [TicketSigner::newNonce(), (int) $next['id']],
            );
            Database::execute('UPDATE events SET confirmed_count = confirmed_count + 1 WHERE id = ?', [$eventId]);

            return ['promoted_registration_id' => (int) $next['id']];
        });
    }
}