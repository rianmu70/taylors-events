<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDOException;

final class EventManagementService
{
    /** @param array<string, mixed> $data */
    public static function createDraft(int $userId, string $role, array $data): int
    {
        $event = self::validatedData($data);
        self::assertClubAccess($userId, $role, (int) $event['club_id']);

        return Database::transaction(static function () use ($userId, $event): int {
            $venue = Database::fetchOne('SELECT capacity, is_active FROM venues WHERE id = ? FOR UPDATE', [$event['venue_id']]);
            if ($venue === null || (int) $venue['is_active'] !== 1) {
                throw new DomainException('venue_unavailable');
            }
            if ((int) $event['capacity'] > (int) $venue['capacity']) {
                throw new DomainException('venue_capacity');
            }

            $publicId = self::uuid();
            $slug = self::slug((string) $event['title']) . '-' . bin2hex(random_bytes(4));
            return Database::insert(
                "INSERT INTO events
                    (public_id, organizer_id, club_id, category_id, venue_id, title, slug,
                     summary, description, learning_outcomes, starts_at, ends_at,
                     registration_closes_at, capacity, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
                [$publicId, $userId, $event['club_id'], $event['category_id'], $event['venue_id'],
                    $event['title'], $slug, $event['summary'], $event['description'], $event['learning_outcomes'],
                    $event['starts_at'], $event['ends_at'], $event['registration_closes_at'], $event['capacity']],
            );
        });
    }

    /** @param array<string, mixed> $data */
    public static function updateDraft(int $eventId, int $userId, string $role, array $data): void
    {
        $eventData = self::validatedData($data);

        Database::transaction(static function () use ($eventId, $userId, $role, $eventData): void {
            $event = Database::fetchOne('SELECT organizer_id, club_id, status FROM events WHERE id = ? FOR UPDATE', [$eventId]);
            if ($event === null || $event['status'] !== 'draft') {
                throw new DomainException('draft_only');
            }
            self::assertCanManageEvent($userId, $role, (int) $event['organizer_id'], (int) $eventData['club_id']);
            self::assertClubAccess($userId, $role, (int) $eventData['club_id']);

            $venue = Database::fetchOne('SELECT capacity, is_active FROM venues WHERE id = ? FOR UPDATE', [$eventData['venue_id']]);
            if ($venue === null || (int) $venue['is_active'] !== 1) {
                throw new DomainException('venue_unavailable');
            }
            if ((int) $eventData['capacity'] > (int) $venue['capacity']) {
                throw new DomainException('venue_capacity');
            }

            Database::execute(
                'UPDATE events SET club_id = ?, category_id = ?, venue_id = ?, title = ?, summary = ?,
                    description = ?, learning_outcomes = ?, starts_at = ?, ends_at = ?,
                    registration_closes_at = ?, capacity = ? WHERE id = ?',
                [$eventData['club_id'], $eventData['category_id'], $eventData['venue_id'], $eventData['title'],
                    $eventData['summary'], $eventData['description'], $eventData['learning_outcomes'],
                    $eventData['starts_at'], $eventData['ends_at'], $eventData['registration_closes_at'],
                    $eventData['capacity'], $eventId],
            );
        });
    }

    public static function transition(int $eventId, int $userId, string $role, string $target, ?string $reason = null): void
    {
        Database::transaction(static function () use ($eventId, $userId, $role, $target, $reason): void {
            $event = Database::fetchOne(
                     'SELECT organizer_id, club_id, venue_id, starts_at, ends_at, status
                         FROM events WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$eventId],
            );
            if ($event === null) {
                throw new DomainException('event_unavailable');
            }
            self::assertCanManageEvent($userId, $role, (int) $event['organizer_id'], (int) $event['club_id']);

            $allowed = match ($role) {
                'admin' => true,
                'organizer' => ($event['status'] === 'draft' && $target === 'pending_approval')
                    || ($event['status'] === 'published' && $target === 'draft'),
                default => false,
            };
            if (!$allowed) {
                throw new DomainException('transition_forbidden');
            }
            if ($event['status'] === 'pending_approval' && $target === 'draft'
                && mb_strlen(trim((string) $reason)) < 5) {
                throw new DomainException('rejection_reason_required');
            }

                        $reservesVenue = in_array($target, ['pending_approval', 'published', 'ongoing'], true);
                        $currentlyReservesVenue = in_array($event['status'], ['pending_approval', 'published', 'ongoing'], true);
                        if ($reservesVenue || $currentlyReservesVenue) {
                $venueLock = Database::fetchOne('SELECT id FROM venues WHERE id = ? FOR UPDATE', [(int) $event['venue_id']]);
                if ($venueLock === null) {
                    throw new DomainException('venue_unavailable');
                }
                                if ($reservesVenue) {
                                        $clash = Database::fetchOne(
                                                "SELECT id FROM events
                                                    WHERE venue_id = ? AND id <> ? AND deleted_at IS NULL
                                                        AND status IN ('pending_approval','published','ongoing')
                                                        AND starts_at < ? AND ends_at > ?
                                                    LIMIT 1 FOR UPDATE",
                                                [(int) $event['venue_id'], $eventId, $event['ends_at'], $event['starts_at']],
                                        );
                                        if ($clash !== null) {
                                                throw new DomainException('venue_clash');
                                        }
                                }
            }

            try {
                Database::execute(
                    'UPDATE events SET status = ?, rejection_reason = ?,
                        approved_by = CASE WHEN ? = \'published\' THEN ? ELSE approved_by END
                      WHERE id = ?',
                    [$target, $target === 'draft' ? trim((string) $reason) : null, $target, $userId, $eventId],
                );
            } catch (PDOException $e) {
                $rule = Database::violatedRule($e);
                if ($rule === 'VENUE_CLASH') {
                    throw new DomainException('venue_clash', 0, $e);
                }
                if ($rule === 'LIFECYCLE') {
                    throw new DomainException('invalid_transition', 0, $e);
                }
                throw $e;
            }
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function validatedData(array $data): array
    {
        $title = trim(self::stringValue($data['title'] ?? null));
        $summary = trim(self::stringValue($data['summary'] ?? null));
        $description = trim(self::stringValue($data['description'] ?? null));
        $startsAt = self::date(self::stringValue($data['starts_at'] ?? null));
        $endsAt = self::date(self::stringValue($data['ends_at'] ?? null));
        $closesAt = trim(self::stringValue($data['registration_closes_at'] ?? null));
        $registrationClosesAt = $closesAt === '' ? null : self::date($closesAt);
        $clubId = filter_var($data['club_id'] ?? null, FILTER_VALIDATE_INT);
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
        $venueId = filter_var($data['venue_id'] ?? null, FILTER_VALIDATE_INT);
        $capacity = filter_var($data['capacity'] ?? null, FILTER_VALIDATE_INT);

        if ($title === '' || mb_strlen($title) > 150 || $summary === '' || mb_strlen($summary) > 300
            || $description === '' || $startsAt === null || $endsAt === null || $endsAt <= $startsAt
            || ($closesAt !== '' && $registrationClosesAt === null)
            || ($registrationClosesAt !== null && $registrationClosesAt > $startsAt)
            || !is_int($clubId) || !is_int($categoryId) || !is_int($venueId)
            || !is_int($capacity) || $capacity < 1 || $capacity > 65535) {
            throw new DomainException('invalid_event_data');
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'description' => $description,
            'learning_outcomes' => trim(self::stringValue($data['learning_outcomes'] ?? null)) ?: null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'registration_closes_at' => $registrationClosesAt,
            'club_id' => $clubId,
            'category_id' => $categoryId,
            'venue_id' => $venueId,
            'capacity' => $capacity,
        ];
    }

    private static function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $formats = [
            ['!Y-m-d\\TH:i', '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/'],
            ['!Y-m-d H:i:s', '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'],
        ];
        foreach ($formats as [$format, $pattern]) {
            if (preg_match($pattern, $value) !== 1) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function assertClubAccess(int $userId, string $role, int $clubId): void
    {
        if ($role === 'admin') {
            return;
        }
        if ($role !== 'organizer' || Database::fetchValue(
            "SELECT 1 FROM club_members WHERE club_id = ? AND user_id = ? AND role IN ('advisor','president','committee')",
            [$clubId, $userId],
        ) === null) {
            throw new DomainException('club_access_denied');
        }
    }

    private static function assertCanManageEvent(int $userId, string $role, int $organizerId, int $clubId): void
    {
        if ($role === 'admin') {
            return;
        }
        if ($role !== 'organizer' || ($organizerId !== $userId && Database::fetchValue(
            "SELECT 1 FROM club_members WHERE club_id = ? AND user_id = ? AND role IN ('advisor','president','committee')",
            [$clubId, $userId],
        ) === null)) {
            throw new DomainException('event_access_denied');
        }
    }

    private static function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '', '-'));
        return substr($slug === '' ? 'event' : $slug, 0, 150);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}