<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT u.*, f.name AS faculty_name FROM users u LEFT JOIN faculties f ON f.id = u.faculty_id
              WHERE u.id = ? AND u.deleted_at IS NULL',
            [$id],
        );
    }

    /** @return array<string,mixed>|null */
    public function findActiveByEmail(string $email): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM users WHERE email = ? AND deleted_at IS NULL AND is_active = 1',
            [mb_strtolower(trim($email))],
        );
    }

    /** @return list<array{id:int,code:string,name:string}> */
    public function faculties(): array
    {
        return Database::fetchAll('SELECT id, code, name FROM faculties ORDER BY name');
    }

    public function updateProfile(int $id, string $name, ?int $facultyId, ?string $bio): void
    {
        Database::execute('UPDATE users SET full_name = ?, faculty_id = ?, bio = ? WHERE id = ?', [$name, $facultyId, $bio, $id]);
    }

    public function setPassword(int $id, string $hash): void
    {
        Database::execute('UPDATE users SET password_hash = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?', [$hash, $id]);
    }

    public function setRole(int $id, string $role): void
    {
        Database::execute('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        Database::execute('UPDATE users SET is_active = ? WHERE id = ?', [$active, $id]);
    }

    /** Sign the account out everywhere, optionally keeping the session making the request. */
    public function endSessions(int $id, ?string $exceptSessionId = null): void
    {
        Database::execute('DELETE FROM user_sessions WHERE user_id = ? AND id <> ?', [$id, $exceptSessionId ?? '']);
    }

    /**
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function search(string $term, string $role, int $page, int $perPage): array
    {
        $where = ' WHERE u.deleted_at IS NULL';
        $params = [];
        if ($term !== '') {
            $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.student_number LIKE ?)';
            $like = '%' . addcslashes($term, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if ($role !== '') {
            $where .= ' AND u.role = ?';
            $params[] = $role;
        }
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM users u' . $where, $params);
        $rows = Database::fetchAll(
            "SELECT u.id, u.full_name, u.email, u.student_number, u.role, u.is_active, u.last_login_at,
                    f.code AS faculty_code,
                    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                       FROM club_members m JOIN clubs c ON c.id = m.club_id
                      WHERE m.user_id = u.id AND m.role IN ('advisor','president','committee')) AS clubs
               FROM users u LEFT JOIN faculties f ON f.id = u.faculty_id" . $where . '
              ORDER BY FIELD(u.role,\'admin\',\'organizer\',\'student\'), u.full_name LIMIT ? OFFSET ?',
            [...$params, $perPage, max(0, ($page - 1) * $perPage)],
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function activeAdminCount(): int
    {
        return (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL");
    }
}
