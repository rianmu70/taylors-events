<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use SessionHandlerInterface;

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
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;

        // A request with a different User-Agent hash cannot overwrite an existing session row.
        Database::execute(
            'INSERT INTO user_sessions (id, user_id, user_agent_hash, payload, last_activity)
             VALUES (:id, :uid, :ua, :payload, NOW()) AS new
             ON DUPLICATE KEY UPDATE
               user_id       = IF(user_sessions.user_agent_hash = new.user_agent_hash, new.user_id, user_sessions.user_id),
               payload       = IF(user_sessions.user_agent_hash = new.user_agent_hash, new.payload, user_sessions.payload),
               last_activity = IF(user_sessions.user_agent_hash = new.user_agent_hash, NOW(), user_sessions.last_activity)',
            ['id' => $id, 'uid' => $uid, 'ua' => $this->uaHash, 'payload' => $data],
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::execute('DELETE FROM user_sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Database::execute(
            'DELETE FROM user_sessions WHERE last_activity < (NOW() - INTERVAL ? SECOND)',
            [$this->idleSeconds],
        );
    }
}
