<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\TicketSigner;
use DomainException;

final class TicketService
{
    /** @return array{result:string, attendee:?string} */
    public static function checkIn(int $eventId, int $actorId, string $role, string $payload, string $ip): array
    {
        $signer = TicketSigner::fromConfig();
        $verified = $signer->verify($payload);

        return Database::transaction(static function () use ($eventId, $actorId, $role, $payload, $ip, $verified): array {
            $event = Database::fetchOne(
                'SELECT id, organizer_id, club_id, status FROM events WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$eventId],
            );
            if ($event === null) {
                throw new DomainException('event_unavailable');
            }
            self::assertCanScan($event, $actorId, $role);

            $registrationId = null;
            $attendee = null;
            if (!$verified['valid']) {
                $result = 'invalid_signature';
            } elseif ((int) $verified['event_id'] !== $eventId) {
                $result = 'wrong_event';
                $candidateId = (int) $verified['registration_id'];
                $registrationId = Database::fetchValue('SELECT id FROM registrations WHERE id = ?', [$candidateId]) === null
                    ? null : $candidateId;
            } elseif ($event['status'] !== 'ongoing') {
                $result = 'event_not_live';
                $candidateId = (int) $verified['registration_id'];
                $registrationId = Database::fetchValue('SELECT id FROM registrations WHERE id = ?', [$candidateId]) === null
                    ? null : $candidateId;
            } else {
                $registration = Database::fetchOne(
                    'SELECT r.id, r.status, r.ticket_nonce, r.checked_in_at, u.full_name
                       FROM registrations r JOIN users u ON u.id = r.user_id
                      WHERE r.id = ? AND r.event_id = ? FOR UPDATE',
                    [(int) $verified['registration_id'], $eventId],
                );
                $registrationId = $registration === null ? null : (int) $registration['id'];

                if ($registration === null || $registration['status'] !== 'confirmed'
                    || !hash_equals((string) ($registration['ticket_nonce'] ?? ''), (string) $verified['nonce'])) {
                    $result = 'not_confirmed';
                } elseif ($registration['checked_in_at'] !== null) {
                    $result = 'duplicate';
                    $attendee = (string) $registration['full_name'];
                } else {
                    $certificate = strtoupper(bin2hex(random_bytes(6)));
                    Database::execute(
                        'UPDATE registrations SET checked_in_at = NOW(), checked_in_by = ?, certificate_code = ? WHERE id = ?',
                        [$actorId, $certificate, $registrationId],
                    );
                    $result = 'admitted';
                    $attendee = (string) $registration['full_name'];
                }
            }

            Database::execute(
                'INSERT INTO ticket_scans (event_id, registration_id, scanned_by, result, payload_hash, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$eventId, $registrationId, $actorId, $result, TicketSigner::payloadHash($payload), $ip],
            );

            return ['result' => $result, 'attendee' => $attendee];
        });
    }

    /** @param array<string, mixed> $event */
    private static function assertCanScan(array $event, int $actorId, string $role): void
    {
        if ($role === 'admin') {
            return;
        }
        if ($role !== 'organizer') {
            throw new DomainException('event_access_denied');
        }
        if ((int) $event['organizer_id'] === $actorId) {
            return;
        }
        if (Database::fetchValue(
            "SELECT 1 FROM club_members WHERE club_id = ? AND user_id = ? AND role IN ('advisor','president','committee')",
            [(int) $event['club_id'], $actorId],
        ) === null) {
            throw new DomainException('event_access_denied');
        }
    }
}