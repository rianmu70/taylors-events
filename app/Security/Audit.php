<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\App;
use App\Core\Database;

/**
 * Writes the audit entries that no trigger can see: sign-ins, failed
 * sign-ins, password resets. Changes to events, registrations and user roles
 * are written by the database triggers instead.
 */
final class Audit
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(string $action, string $entityType, ?int $entityId, ?int $actorId = null, ?array $before = null, ?array $after = null): void
    {
        Database::execute(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $actorId,
                $action,
                $entityType,
                $entityId,
                $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
                App::request()?->ip(),
            ],
        );
    }
}
