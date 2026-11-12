<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\RuleViolation;
use App\Repositories\ClubRepository;
use App\Repositories\EventRepository;
use App\Repositories\VenueRepository;

/**
 * The administrator's decision on an event that is waiting for approval.
 *
 * An organiser must never approve their own event. This class refuses it,
 * and so does trg_events_bu (rule SELF_APPROVAL), which also refuses any
 * approver who is not an administrator.
 */
final class ApprovalService
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly VenueRepository $venues,
        private readonly ClubRepository $clubs,
        private readonly EventService $eventService,
        private readonly Notifier $notifier,
    ) {
    }

    public function approve(int $eventId, int $adminId): void
    {
        $event = RuleViolation::guard(fn (): array => Database::transaction(function () use ($eventId, $adminId): array {
            $event = $this->events->lock($eventId) ?? throw new RuleViolation('NOT_FOUND');
            if ($event['status'] !== 'pending_approval') {
                throw new RuleViolation('NOT_PENDING');
            }
            if ((int) $event['organizer_id'] === $adminId || $this->clubs->manages($adminId, (int) $event['club_id'])) {
                throw new RuleViolation('SELF_APPROVAL');
            }
            $this->venues->lock((int) $event['venue_id']);
            $this->eventService->assertVenueFree($event);
            $this->events->approve($eventId, $adminId);
            return $event;
        }));

        $this->notifier->notify((int) $event['organizer_id'], 'event_approved', ['event' => (string) $event['title']], '/events/' . $event['slug'], email: true);
    }

    public function reject(int $eventId, int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuleViolation('REJECTION_REASON');
        }
        $event = RuleViolation::guard(fn (): array => Database::transaction(function () use ($eventId, $reason): array {
            $event = $this->events->lock($eventId) ?? throw new RuleViolation('NOT_FOUND');
            if ($event['status'] !== 'pending_approval') {
                throw new RuleViolation('NOT_PENDING');
            }
            $this->events->reject($eventId, $reason);
            return $event;
        }));

        $this->notifier->notify(
            (int) $event['organizer_id'],
            'event_rejected',
            ['event' => (string) $event['title'], 'reason' => $reason],
            '/organise/events/' . $eventId . '/edit',
            email: true,
        );
    }
}
