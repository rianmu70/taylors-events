<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Security\Audit;

/**
 * Forgotten passwords.
 *
 * The link carries a random 256 bit token. Only its SHA-256 is stored, so
 * the table is useless to anyone who reads it. A token works once and for
 * 30 minutes (config security.reset_minutes). Asking for a link gives the
 * same answer whether or not the address has an account.
 */
final class PasswordResetService
{
    public function __construct(private readonly UserRepository $users, private readonly Notifier $notifier)
    {
    }

    public function request(string $email, string $ip): void
    {
        $user = $this->users->findActiveByEmail($email);
        if ($user === null) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $minutes = (int) config('security.reset_minutes', 30);
        Database::execute(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
             VALUES (?, ?, NOW() + INTERVAL ? MINUTE, ?)',
            [$user['id'], hash('sha256', $token), $minutes, $ip],
        );
        $this->notifier->email((int) $user['id'], 'password_reset', [
            'link' => absolute_url('/reset-password/' . $token),
            'minutes' => $minutes,
        ]);
        Audit::record('auth.reset_requested', 'user', (int) $user['id']);
    }

    /** Is this token still good? Used to decide whether to show the form at all. */
    public function isUsable(string $token): bool
    {
        return $this->row($token, false) !== null;
    }

    /**
     * @return string|null a message when the password was not changed, null on success
     */
    public function reset(string $token, string $newPassword): ?string
    {
        return Database::transaction(function () use ($token, $newPassword): ?string {
            $row = $this->row($token, true);
            if ($row === null) {
                return t('auth.reset.invalid');
            }
            if ($problem = Auth::passwordProblem($newPassword, (string) $row['email'])) {
                return $problem;
            }
            $this->users->setPassword((int) $row['user_id'], Auth::hash($newPassword));
            // Burn this token and every other outstanding one, and sign the account out everywhere.
            Database::execute('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$row['user_id']]);
            $this->users->endSessions((int) $row['user_id']);
            Audit::record('auth.password_reset', 'user', (int) $row['user_id'], (int) $row['user_id']);
            return null;
        });
    }

    /** @return array<string,mixed>|null */
    private function row(string $token, bool $lock): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        return Database::fetchOne(
            'SELECT p.id, p.user_id, u.email FROM password_resets p JOIN users u ON u.id = p.user_id
              WHERE p.token_hash = ? AND p.used_at IS NULL AND p.expires_at > NOW()
                AND u.deleted_at IS NULL AND u.is_active = 1' . ($lock ? ' FOR UPDATE' : ''),
            [hash('sha256', $token)],
        );
    }
}
