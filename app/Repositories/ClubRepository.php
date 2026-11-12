<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ClubRepository
{
    /** Club roles that may create and run events for the club. */
    public const COMMITTEE = ['advisor', 'president', 'committee'];

    /** @return list<array{id:int,name:string}> clubs this user may create events for */
    public function managedBy(int $userId): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.name FROM clubs c JOIN club_members m ON m.club_id = c.id
              WHERE m.user_id = ? AND m.role IN ('advisor','president','committee')
                AND c.is_active = 1 AND c.deleted_at IS NULL
              ORDER BY c.name",
            [$userId],
        );
    }

    public function manages(int $userId, int $clubId): bool
    {
        return Database::fetchValue(
            "SELECT 1 FROM club_members WHERE user_id = ? AND club_id = ? AND role IN ('advisor','president','committee')",
            [$userId, $clubId],
        ) !== null;
    }

    /** @return list<array{id:int,name:string}> */
    public function all(): array
    {
        return Database::fetchAll('SELECT id, name FROM clubs WHERE deleted_at IS NULL ORDER BY name');
    }

    public function exists(int $clubId): bool
    {
        return Database::fetchValue('SELECT 1 FROM clubs WHERE id = ? AND deleted_at IS NULL', [$clubId]) !== null;
    }

    public function addCommitteeMember(int $clubId, int $userId): void
    {
        Database::execute(
            "INSERT INTO club_members (club_id, user_id, role) VALUES (?, ?, 'committee')
             ON DUPLICATE KEY UPDATE role = IF(role = 'member', 'committee', role)",
            [$clubId, $userId],
        );
    }

    public function removeFromCommittees(int $userId): void
    {
        Database::execute("UPDATE club_members SET role = 'member' WHERE user_id = ?", [$userId]);
    }
}
