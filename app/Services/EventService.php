<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Domain\RuleViolation;
use App\Repositories\ClubRepository;
use App\Repositories\EventRepository;
use App\Repositories\VenueRepository;

/**
 * What an organiser can do with an event: write a draft, change it, send it
 * for approval, remove it, archive it.
 *
 * GUARANTEE 2, a venue cannot be double booked, is enforced in submit() by
 * locking the venue row and checking for an overlap, and again by the
 * trigger trg_events_bu.
 */
final class EventService
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly ClubRepository $clubs,
        private readonly VenueRepository $venues,
        private readonly BookingService $bookings,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * Check a submitted event form.
     *
     * @param array<string,mixed> $input
     * @return array{data: array<string,mixed>, errors: array<string,string>}
     */
    public function validate(array $input, int $userId): array
    {
        $v = new Validator($input);
        $v->required('title')->length('title', 5, 150);
        $v->required('summary')->length('summary', 20, 300);
        $v->required('description')->length('description', 40, 5000);
        $v->required('club_id')->integer('club_id', 1, 65535);
        $v->required('category_id')->integer('category_id', 1, 65535);
        $v->required('venue_id')->integer('venue_id', 1, 65535);
        $v->required('starts_at')->dateTime('starts_at');
        $v->required('ends_at')->dateTime('ends_at');
        $v->required('capacity')->integer('capacity', 1, 5000);

        $start = Validator::parseDateTime($v->value('starts_at'));
        $end = Validator::parseDateTime($v->value('ends_at'));
        if ($start !== null && $end !== null) {
            $v->rule('starts_at', $start->getTimestamp() > time(), 'event.error.past');
            $v->rule('ends_at', $end > $start, 'rule.EVENT_TIME');
            $v->rule('ends_at', $end->getTimestamp() - $start->getTimestamp() <= (int) config('events.max_hours') * 3600, 'event.error.too_long');
        }

        $venue = $this->venues->find((int) $v->value('venue_id'));
        $v->rule('venue_id', $venue !== null && (int) $venue['is_active'] === 1, 'validation.choice');
        if ($venue !== null) {
            $v->rule('capacity', (int) $v->value('capacity') <= (int) $venue['capacity'], 'rule.VENUE_CAPACITY');
        }
        $v->rule('club_id', $this->clubs->manages($userId, (int) $v->value('club_id')), 'rule.CLUB_SCOPE');
        $v->rule('category_id', in_array((int) $v->value('category_id'), array_column($this->events->categories(), 'id'), true), 'validation.choice');

        return [
            'errors' => $v->errors(),
            'data' => [
                'title' => $v->value('title'),
                'summary' => $v->value('summary'),
                'description' => $v->value('description'),
                'club_id' => (int) $v->value('club_id'),
                'category_id' => (int) $v->value('category_id'),
                'venue_id' => (int) $v->value('venue_id'),
                'starts_at' => $start?->format('Y-m-d H:i:s'),
                'ends_at' => $end?->format('Y-m-d H:i:s'),
                'capacity' => (int) $v->value('capacity'),
            ],
        ];
    }

    /** @param array<string,mixed> $data validated by validate() */
    public function createDraft(array $data, int $userId): int
    {
        return RuleViolation::guard(fn (): int => $this->events->insertDraft($data + [
            'organizer_id' => $userId,
            'slug' => $this->uniqueSlug((string) $data['title']),
        ]));
    }

    /**
     * Only a draft can be edited. Once an event is waiting for approval or
     * published, its time and room are a promise to other people.
     *
     * @param array<string,mixed> $data validated by validate()
     */
    public function updateDraft(int $eventId, array $data, int $userId): void
    {
        $event = $this->manageable($eventId, $userId);
        if ($event['status'] !== 'draft') {
            throw new RuleViolation('NOT_DRAFT');
        }
        RuleViolation::guard(fn () => $this->events->updateDetails($eventId, $data));
    }

    /**
     * Send a draft for approval. From this moment the event holds its room.
     *
     * @throws RuleViolation VENUE_CLASH with the name of the event already there
     */
    public function submit(int $eventId, int $userId): void
    {
        $this->manageable($eventId, $userId);   // permission first, outside the transaction

        $title = RuleViolation::guard(fn (): string => Database::transaction(function () use ($eventId): string {
            $event = $this->events->lock($eventId) ?? throw new RuleViolation('NOT_FOUND');
            if ($event['status'] !== 'draft') {
                throw new RuleViolation('NOT_DRAFT');
            }
            if (strtotime((string) $event['starts_at']) <= time()) {
                throw new RuleViolation('EVENT_PAST');
            }
            // Lock the room, then look for an overlap. A second organiser asking
            // for the same room waits here until this transaction has finished.
            $this->venues->lock((int) $event['venue_id']);
            $this->assertVenueFree($event);
            $this->events->setStatus($eventId, 'pending_approval');
            return (string) $event['title'];
        }));

        $this->notifier->notifyAdmins('approval_needed', ['event' => $title], '/admin/approvals');
    }

    /** Remove a draft. The row stays, marked deleted, so the audit trail still points at something. */
    public function deleteDraft(int $eventId, int $userId): void
    {
        $event = $this->manageable($eventId, $userId);
        if ($event['status'] !== 'draft') {
            throw new RuleViolation('NOT_DRAFT');
        }
        $this->events->softDelete($eventId);
    }

    public function archive(int $eventId, int $userId): void
    {
        $this->manageable($eventId, $userId);
        RuleViolation::guard(fn () => $this->events->setStatus($eventId, 'archived'));
    }

    /**
     * The event, if this user is on the committee of the club that hosts it.
     *
     * @return array<string,mixed>
     * @throws RuleViolation NOT_FOUND, CLUB_SCOPE
     */
    public function manageable(int $eventId, int $userId): array
    {
        $event = $this->events->find($eventId) ?? throw new RuleViolation('NOT_FOUND');
        if (!$this->clubs->manages($userId, (int) $event['club_id'])) {
            throw new RuleViolation('CLUB_SCOPE');
        }
        return $event;
    }

    /**
     * @param array<string,mixed> $event
     * @throws RuleViolation VENUE_CLASH
     */
    public function assertVenueFree(array $event): void
    {
        $clash = $this->venues->clash((int) $event['venue_id'], (string) $event['starts_at'], (string) $event['ends_at'], (int) $event['id']);
        if ($clash !== null) {
            throw new RuleViolation('VENUE_CLASH_WITH', [
                'event' => (string) $clash['title'],
                'club' => (string) $clash['club_name'],
                'when' => format_range((string) $clash['starts_at'], (string) $clash['ends_at']),
            ]);
        }
    }

    /**
     * For the form: the booking this draft would collide with, if any.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null
     */
    public function clashFor(array $event): ?array
    {
        return $this->venues->clash((int) $event['venue_id'], (string) $event['starts_at'], (string) $event['ends_at'], (int) ($event['id'] ?? 0));
    }

    private function uniqueSlug(string $title): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        $base = substr($base === '' ? 'event' : $base, 0, 120);
        $slug = $base;
        while ($this->events->slugExists($slug)) {
            $slug = $base . '-' . bin2hex(random_bytes(2));
        }
        return $slug;
    }
}
