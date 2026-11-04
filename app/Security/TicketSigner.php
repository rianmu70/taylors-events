<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Signs and verifies the text inside a QR ticket.
 *
 *   v1.<event id>.<registration id>.<32 hex nonce>.<signature>
 *
 * The signature is an HMAC-SHA256 over everything before it, so a student cannot change the event,
 * the registration or the nonce, and cannot invent a ticket without the server secret.
 * The signature is computed, never stored: a database leak cannot be used to forge tickets.
 *
 * verify() only proves the text is genuine. The scanner must still check the database
 * (registration exists, nonce matches registrations.ticket_nonce, status is confirmed,
 * not already checked in, and event_id is the event being scanned).
 */
final class TicketSigner
{
    private const VERSION = 'v1';
    private const PATTERN = '/^v1\.([1-9][0-9]{0,9})\.([1-9][0-9]{0,9})\.([0-9a-f]{32})\.([A-Za-z0-9_-]{43})\z/';

    private string $key;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The ticket secret must be at least 32 characters.');
        }
        // Derive a key used for tickets only, so the same secret can serve other purposes safely.
        $this->key = hash_hkdf('sha256', $secret, 32, 'taylors-events/ticket/v1');
    }

    /** Uses config('app.ticket_secret'), which comes from TICKET_SECRET in .env. */
    public static function fromConfig(): self
    {
        $secret = config('app.ticket_secret');
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('TICKET_SECRET is not set. Add a random value of 32+ characters to .env.');
        }
        return new self($secret);
    }

    /** 32 hex characters. Store it in registrations.ticket_nonce when the booking is confirmed. */
    public static function newNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function sign(int $eventId, int $registrationId, string $nonce): string
    {
        if ($eventId < 1 || $registrationId < 1 || preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            throw new InvalidArgumentException('Ticket needs positive ids and a 32-character hex nonce.');
        }
        $body = self::VERSION . '.' . $eventId . '.' . $registrationId . '.' . $nonce;
        return $body . '.' . $this->mac($body);
    }

    /**
     * @return array{valid: bool, reason: ?string, event_id?: int, registration_id?: int, nonce?: string}
     *         reason is 'malformed' or 'bad_signature' when valid is false.
     */
    public function verify(string $payload): array
    {
        if (strlen($payload) > 200 || preg_match(self::PATTERN, $payload, $m) !== 1) {
            return ['valid' => false, 'reason' => 'malformed'];
        }
        $body = substr($payload, 0, (int) strrpos($payload, '.'));
        if (!hash_equals($this->mac($body), $m[4])) {
            return ['valid' => false, 'reason' => 'bad_signature'];
        }
        return [
            'valid' => true,
            'reason' => null,
            'event_id' => (int) $m[1],
            'registration_id' => (int) $m[2],
            'nonce' => $m[3],
        ];
    }

    /** What to write in ticket_scans.payload_hash: evidence of what was scanned, without storing a usable ticket. */
    public static function payloadHash(string $payload): string
    {
        return hash('sha256', $payload);
    }

    private function mac(string $body): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->key, true)), '+/', '-_'), '=');
    }
}
