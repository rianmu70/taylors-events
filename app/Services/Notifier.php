<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Tells a user that something happened: an entry in their notification list
 * and, for the important ones, an email.
 *
 * Emails are written to mail_outbox with the "log" transport. Nothing leaves
 * the machine, so the product can be shown with no network; an administrator
 * reads them on the Outbox page. Wording lives in the language file under
 * "notify.<type>" and "mail.<type>".
 */
final class Notifier
{
    /** @param array<string, int|string> $replace */
    public function notify(int $userId, string $type, array $replace = [], ?string $link = null, bool $email = false): void
    {
        Database::execute(
            'INSERT INTO notifications (user_id, type, title, body, link_url) VALUES (?, ?, ?, ?, ?)',
            [$userId, $type, t("notify.$type.title", $replace), t("notify.$type.body", $replace), $link],
        );
        if ($email) {
            $this->email($userId, $type, $replace + ['link' => $link === null ? '' : absolute_url($link)]);
        }
    }

    /** @param array<string, int|string> $replace */
    public function email(int $userId, string $template, array $replace = []): void
    {
        $user = Database::fetchOne('SELECT email, full_name FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return;
        }
        $replace += ['name' => $user['full_name'], 'app' => (string) config('app.name')];
        Database::execute(
            'INSERT INTO mail_outbox (to_email, related_user_id, template, subject, body_text) VALUES (?, ?, ?, ?, ?)',
            [$user['email'], $userId, $template, t("mail.$template.subject", $replace), t("mail.$template.body", $replace)],
        );
    }

    /** @param array<string, int|string> $replace */
    public function notifyAdmins(string $type, array $replace, string $link): void
    {
        foreach (Database::fetchAll("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL") as $admin) {
            $this->notify((int) $admin['id'], $type, $replace, $link);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listFor(int $userId, int $limit = 40): array
    {
        return Database::fetchAll('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ?', [$userId, $limit]);
    }

    public function unreadCount(int $userId): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public function markAllRead(int $userId): void
    {
        Database::execute('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}
