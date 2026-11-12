<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\EventRepository;
use App\Repositories\RegistrationRepository;
use App\Security\TicketSigner;

/**
 * Decides whether the ticket in front of the scanner gets in.
 *
 * A ticket is the text  v1.<event>.<registration>.<nonce>.<signature>
 * The signature is HMAC-SHA256 over the first four parts with a key that
 * exists only in .env. It is recomputed on every scan and never stored, so
 * nobody can make a ticket from a copy of the database.
 *
 * The nonce makes a ticket single use: admitting it stamps checked_in_at
 * under a row lock, and a second scan of the same code, for example from a
 * screenshot sent to a friend, is answered "already used". Cancelling a
 * booking replaces the nonce, so an old screenshot of that stops working too.
 *
 * Every scan, admitted or refused, is written to ticket_scans.
 */
final class CheckInService
{
    public const ADMITTED = 'admitted';

    public function __construct(
        private readonly EventRepository $events,
        private readonly RegistrationRepository $registrations,
        private readonly TicketSigner $signer,
        private readonly Notifier $notifier,
    ) {
    }

    /** The text encoded in the QR code of a ticket. */
    public function payloadFor(array $registration): string
    {
        return $this->signer->sign((int) $registration['event_id'], (int) $registration['id'], (string) $registration['ticket_nonce']);
    }

    /** The short code printed under the QR code, typed in when the camera cannot read it. */
    public static function doorCode(string $nonce): string
    {
        return strtoupper(substr($nonce, 0, 4) . '-' . substr($nonce, 4, 4));
    }

    /**
     * @return array{result:string, name:?string}
     *         result is admitted, duplicate, invalid_signature, wrong_event,
     *         not_confirmed or event_not_live
     */
    public function scan(int $eventId, string $payload, int $scannerId, ?string $ip): array
    {
        $payload = trim($payload);
        $ticket = $this->signer->verify($payload);
        if (!$ticket['valid']) {
            return $this->finish($eventId, null, $scannerId, 'invalid_signature', $payload, $ip);
        }
        if ($ticket['event_id'] !== $eventId) {
            return $this->finish($eventId, null, $scannerId, 'wrong_event', $payload, $ip);
        }
        return $this->admit($eventId, $ticket['registration_id'], $ticket['nonce'], $scannerId, $payload, $ip);
    }

    /** @return array{result:string, name:?string} */
    public function scanDoorCode(int $eventId, string $code, int $scannerId, ?string $ip): array
    {
        $clean = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $code));
        $registration = strlen($clean) === 8 ? $this->registrations->findByDoorCode($eventId, $clean) : null;
        if ($registration === null) {
            return $this->finish($eventId, null, $scannerId, 'invalid_signature', 'code:' . $clean, $ip);
        }
        return $this->admit($eventId, (int) $registration['id'], (string) $registration['ticket_nonce'], $scannerId, 'code:' . $clean, $ip);
    }

    /** True while the door is open: from shortly before the start until the event ends. */
    public function doorIsOpen(array $event): bool
    {
        if ($event['status'] === 'ongoing') {
            return true;
        }
        $opens = strtotime((string) $event['starts_at']) - (int) config('tickets.checkin_opens_minutes', 60) * 60;
        return $event['status'] === 'published' && time() >= $opens;
    }

    /** @return array{result:string, name:?string} */
    private function admit(int $eventId, int $registrationId, string $nonce, int $scannerId, string $payload, ?string $ip): array
    {
        return Database::transaction(function () use ($eventId, $registrationId, $nonce, $scannerId, $payload, $ip): array {
            $event = $this->events->find($eventId);
            if ($event === null || !$this->doorIsOpen($event)) {
                return $this->finish($eventId, null, $scannerId, 'event_not_live', $payload, $ip);
            }

            // Lock the ticket: two doors scanning the same code cannot both admit it.
            $registration = $this->registrations->lock($registrationId);
            if ($registration === null || (int) $registration['event_id'] !== $eventId
                || !hash_equals((string) $registration['ticket_nonce'], $nonce)) {
                return $this->finish($eventId, null, $scannerId, 'invalid_signature', $payload, $ip);
            }

            $name = (string) Database::fetchValue('SELECT full_name FROM users WHERE id = ?', [$registration['user_id']]);
            if ($registration['status'] !== 'confirmed') {
                return $this->finish($eventId, $registrationId, $scannerId, 'not_confirmed', $payload, $ip, $name);
            }
            if ($registration['checked_in_at'] !== null) {
                return $this->finish($eventId, $registrationId, $scannerId, 'duplicate', $payload, $ip, $name);
            }

            $this->registrations->checkIn($registrationId, $scannerId, strtoupper(bin2hex(random_bytes(6))));
            $this->notifier->notify((int) $registration['user_id'], 'checked_in', ['event' => (string) $event['title']], '/my/registrations');
            return $this->finish($eventId, $registrationId, $scannerId, self::ADMITTED, $payload, $ip, $name);
        });
    }

    /** @return array{result:string, name:?string} */
    private function finish(int $eventId, ?int $registrationId, int $scannerId, string $result, string $payload, ?string $ip, ?string $name = null): array
    {
        $this->registrations->logScan($eventId, $registrationId, $scannerId, $result, TicketSigner::payloadHash($payload), $ip);
        return ['result' => $result, 'name' => $name];
    }
}
