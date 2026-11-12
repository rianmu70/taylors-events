<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The event lifecycle. The same table of moves is enforced by the trigger
 * trg_events_bu, so the two can never disagree for long: tests/DatabaseTest
 * walks every pair and compares.
 */
enum EventStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending_approval';
    case Published = 'published';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Archived = 'archived';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Published, self::Draft],
            self::Published => [self::Ongoing, self::Draft],
            self::Ongoing => [self::Completed],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canBecome(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /** Statuses in which the event holds its room on the venue calendar. */
    public const HOLDS_VENUE = ['pending_approval', 'published', 'ongoing'];

    /** Statuses a visitor can see. */
    public const PUBLIC = ['published', 'ongoing', 'completed'];
}
