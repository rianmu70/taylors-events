<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Keeps PHP sessions in the user_sessions table.
 * A session only loads for the same User-Agent hash it was created with,
 * and only while it is inside the idle window.
 *
 * Implements SessionUpdateTimestampHandlerInterface because session.use_strict_mode needs validateId(),
 * and because PHP calls updateTimestamp() instead of write() when the data did not change.
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** A guest session holding only its creation time is not worth a database row (stops crawlers filling the table). */
    private const EMPTY_GUEST = '/\A_born\|i:\d+;\z/';

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

    /** Strict mode: an ID that is unknown, expired or from another browser is replaced by a new random ID. */
    public function validateId(string $id): bool
    {
        return Database::fetchValue(
            'SELECT 1 FROM user_sessions
              WHERE id = ? AND user_agent_hash = ?
                AND last_activity > (NOW() - INTERVAL ? SECOND)',
            [$id, $this->uaHash, $this->idleSeconds],
        ) !== null;
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
        if ($uid === null && preg_match(self::EMPTY_GUEST, $data) === 1) {
            return true;
        }
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
        return true;
    }

    /** Called instead of write() when nothing in the session changed: only refresh the idle clock. */
    public function updateTimestamp(string $id, string $data): bool
    {
        Database::execute(
            'UPDATE user_sessions SET last_activity = NOW() WHERE id = ? AND user_agent_hash = ?',
            [$id, $this->uaHash],
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
