<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\RuleViolation;
use App\Repositories\EventRepository;
use App\Repositories\RegistrationRepository;
use App\Security\TicketSigner;

/**
 * Booking, cancelling and the waitlist.
 *
 * GUARANTEE 1, a seat cannot be sold twice, is enforced here and again in
 * the database:
 *
 *   PHP       Every operation starts by locking the event row with
 *             SELECT ... FOR UPDATE. Two students clicking at the same
 *             moment are therefore handled one after the other, and the
 *             second one sees the seat count the first one left behind.
 *   Database  events.confirmed_count is kept by triggers on registrations
 *             and guarded by CHECK (confirmed_count <= capacity). If this
 *             class had a bug, the INSERT would still be refused.
 */
final class BookingService
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly RegistrationRepository $registrations,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * Take a seat, or a place on the waitlist when the event is full.
     *
     * @param string|null $idempotencyKey sent by the booking form; a repeat of the same
     *                                    key returns the first outcome instead of booking twice
     * @return array{status:string, registration_id:int, replayed:bool}
     * @throws RuleViolation EVENT_CLOSED, ALREADY_BOOKED
     */
    public function book(int $eventId, int $userId, ?string $idempotencyKey = null): array
    {
        $key = $idempotencyKey !== null && preg_match('/^[0-9a-f-]{36}$/', $idempotencyKey) === 1 ? $idempotencyKey : null;

        return RuleViolation::guard(fn (): array => Database::transaction(function () use ($eventId, $userId, $key): array {
            // 1. Lock the event. From here until COMMIT nobody else can book it.
            $event = $this->events->lock($eventId) ?? throw new RuleViolation('EVENT_CLOSED');

            // 2. A repeated submission gets the answer the first one got.
            if ($key !== null) {
                $stored = Database::fetchOne('SELECT user_id, response_body FROM idempotency_keys WHERE idem_key = ?', [$key]);
                if ($stored !== null && (int) $stored['user_id'] === $userId) {
                    return ['replayed' => true] + json_decode((string) $stored['response_body'], true, 8, JSON_THROW_ON_ERROR);
                }
            }

            if ($event['status'] !== 'published' || strtotime((string) $event['starts_at']) <= time()) {
                throw new RuleViolation('EVENT_CLOSED');
            }

            $existing = $this->registrations->forUserAndEvent($userId, $eventId, lock: true);
            if ($existing !== null && $existing['status'] !== 'cancelled') {
                throw new RuleViolation('ALREADY_BOOKED');
            }

            // 3. Decide, using the count we hold the lock on.
            $status = (int) $event['confirmed_count'] < (int) $event['capacity'] ? 'confirmed' : 'waitlisted';
            $nonce = TicketSigner::newNonce();
            if ($existing === null) {
                $registrationId = $this->registrations->insert($eventId, $userId, $status, $nonce);
            } else {
                $registrationId = (int) $existing['id'];
                $this->registrations->rebook($registrationId, $status, $nonce);
            }

            $result = ['status' => $status, 'registration_id' => $registrationId];
            if ($key !== null) {
                Database::execute(
                    'INSERT INTO idempotency_keys (idem_key, user_id, endpoint, response_code, response_body) VALUES (?, ?, ?, 200, ?)',
                    [$key, $userId, 'events.book', json_encode($result, JSON_THROW_ON_ERROR)],
                );
            }

            $this->notifier->notify(
                $userId,
                $status === 'confirmed' ? 'booking_confirmed' : 'booking_waitlisted',
                ['event' => (string) $event['title'], 'when' => format_datetime((string) $event['starts_at'])],
                $status === 'confirmed' ? "/my/tickets/$registrationId" : '/my/registrations',
                email: true,
            );
            return ['replayed' => false] + $result;
        }));
    }

    /**
     * Give a seat or a waitlist place back. A freed seat goes to whoever has
     * waited longest, inside the same transaction, so it is never on open
     * sale in between.
     *
     * @throws RuleViolation NOT_YOURS, NOT_ACTIVE, ALREADY_CHECKED_IN, EVENT_STARTED
     */
    public function cancel(int $registrationId, int $userId): void
    {
        $registration = $this->registrations->find($registrationId);
        if ($registration === null || (int) $registration['user_id'] !== $userId) {
            throw new RuleViolation('NOT_YOURS');
        }

        RuleViolation::guard(fn () => Database::transaction(function () use ($registration, $registrationId): void {
            $event = $this->events->lock((int) $registration['event_id']) ?? throw new RuleViolation('EVENT_CLOSED');
            $current = $this->registrations->lock($registrationId);

            if ($current === null || $current['status'] === 'cancelled') {
                throw new RuleViolation('NOT_ACTIVE');
            }
            if ($current['checked_in_at'] !== null) {
                throw new RuleViolation('ALREADY_CHECKED_IN');
            }
            if ($event['status'] !== 'published') {
                throw new RuleViolation('EVENT_STARTED');
            }

            // A new nonce means any saved copy of the old QR code stops working.
            $this->registrations->cancel($registrationId, TicketSigner::newNonce());
            $this->notifier->notify((int) $current['user_id'], 'booking_cancelled', ['event' => (string) $event['title']], '/events/' . $event['slug']);

            if ($current['status'] === 'confirmed') {
                $this->promoteWaiting((int) $event['id']);
            }
        }));
    }

    /**
     * Move people from the waitlist into free seats, longest wait first.
     * The caller must already hold the lock on the event row.
     *
     * @return int how many people were promoted
     */
    public function promoteWaiting(int $eventId): int
    {
        $promoted = 0;
        while (true) {
            $event = $this->events->lock($eventId);
            if ($event === null || (int) $event['confirmed_count'] >= (int) $event['capacity']) {
                return $promoted;
            }
            $next = $this->registrations->nextWaiting($eventId);
            if ($next === null) {
                return $promoted;
            }
            $this->registrations->promote((int) $next['id']);
            $this->notifier->notify(
                (int) $next['user_id'],
                'booking_promoted',
                ['event' => (string) $event['title'], 'when' => format_datetime((string) $event['starts_at'])],
                '/my/tickets/' . $next['id'],
                email: true,
            );
            $promoted++;
        }
    }
}
