<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Logger;
use SessionHandlerInterface;
use Throwable;

/**
 * Keeps PHP sessions in the user_sessions table.
 * A session only loads for the same User-Agent hash it was created with,
 * and only while it is inside the idle window.
 */
final class DbSessionHandler implements SessionHandlerInterface
{
    private string $uaHash;

    public function __construct(private readonly int $idleSeconds)
    {
        $this->uaHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        // The database may be unreachable. A visitor should still get a page,
        // and /api/health should still be able to report the real status, so
        // fall back to an empty session instead of letting the error escape
        // session_start() as a 500.
        try {
            return $this->readRow($id);
        } catch (Throwable $e) {
            Logger::error('Session store unavailable on read: ' . $e->getMessage());
            return '';
        }
    }

    private function readRow(string $id): string
    {
        $payload = Database::fetchValue(
            'SELECT payload FROM user_sessions
              WHERE id = ? AND user_agent_hash = ?
                AND last_activity > (NOW() - INTERVAL ? SECOND)',
            [$id, $this->uaHash, $this->idleSeconds],
        );
        return $payload === null ? '' : (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->writeRow($id, $data);
        } catch (Throwable $e) {
            Logger::error('Session store unavailable on write: ' . $e->getMessage());
        }
        return true;
    }

    private function writeRow(string $id, string $data): void
    {
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0'; // column is NOT NULL

        // A request with a different User-Agent hash cannot overwrite an existing session row.
        Database::execute(
            'INSERT INTO user_sessions (id, user_id, ip_address, user_agent_hash, payload, last_activity)
             VALUES (:id, :uid, :ip, :ua, :payload, NOW()) AS new
             ON DUPLICATE KEY UPDATE
               user_id       = IF(user_sessions.user_agent_hash = new.user_agent_hash, new.user_id, user_sessions.user_id),
               ip_address    = IF(user_sessions.user_agent_hash = new.user_agent_hash, new.ip_address, user_sessions.ip_address),
               payload       = IF(user_sessions.user_agent_hash = new.user_agent_hash, new.payload, user_sessions.payload),
               last_activity = IF(user_sessions.user_agent_hash = new.user_agent_hash, NOW(), user_sessions.last_activity)',
            ['id' => $id, 'uid' => $uid, 'ip' => $ip, 'ua' => $this->uaHash, 'payload' => $data],
        );
    }

    public function destroy(string $id): bool
    {
        try {
            Database::execute('DELETE FROM user_sessions WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            Logger::error('Session store unavailable on destroy: ' . $e->getMessage());
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return Database::execute(
                'DELETE FROM user_sessions WHERE last_activity < (NOW() - INTERVAL ? SECOND)',
                [$this->idleSeconds],
            );
        } catch (Throwable $e) {
            Logger::error('Session store unavailable on gc: ' . $e->getMessage());
            return 0;
        }
    }
}
