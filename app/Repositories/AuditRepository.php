<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Reads the audit log. There is deliberately no method that changes it.
 */
final class AuditRepository
{
    /**
     * @param array{action?:string, entity?:string, actor?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];
        if (($filters['action'] ?? '') !== '') {
            $where .= ' AND a.action LIKE ?';
            $params[] = addcslashes($filters['action'], '%_\\') . '%';
        }
        if (($filters['entity'] ?? '') !== '') {
            $where .= ' AND a.entity_type = ?';
            $params[] = $filters['entity'];
        }
        if (($filters['actor'] ?? '') !== '') {
            $where .= ' AND a.actor_id IN (SELECT id FROM users WHERE full_name LIKE ? OR email LIKE ?)';
            $like = '%' . addcslashes($filters['actor'], '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs a' . $where, $params);
        // No foreign key ties actor_id to users, so the join is LEFT: a removed account still shows its history.
        $rows = Database::fetchAll(
            'SELECT a.*, u.full_name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id'
            . $where . ' ORDER BY a.id DESC LIMIT ? OFFSET ?',
            [...$params, $perPage, max(0, ($page - 1) * $perPage)],
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function outbox(int $limit = 50): array
    {
        return Database::fetchAll('SELECT * FROM mail_outbox ORDER BY id DESC LIMIT ?', [$limit]);
    }
}
