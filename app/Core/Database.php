<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * The single database connection (PDO) for the whole request.
 *
 * Every query goes through a prepared statement, so user input is never
 * pasted into SQL:
 *
 *     $event = Database::fetchOne('SELECT * FROM events WHERE slug = ?', [$slug]);
 *     $rows  = Database::fetchAll('SELECT * FROM venues WHERE capacity >= :min', ['min' => 50]);
 *
 * The connection opens on the first query, so pages that never touch the
 * database never connect.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $queryCount = 0;
    private static ?int $auditUserId = null;
    private static ?string $auditIp = null;

    /** @var array{user:string,pass:string}|null */
    private static ?array $override = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
            self::applyAuditContext();
        }
        return self::$pdo;
    }

    /** Runs a statement with bound parameters and returns it. */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $isList = array_is_list($params);

        foreach ($params as $key => $value) {
            $name = $isList ? $key + 1 : ':' . ltrim((string) $key, ':');
            [$value, $type] = self::bindable($value, (string) $key);
            $statement->bindValue($name, $value, $type);
        }

        $statement->execute();
        self::$queryCount++;
        return $statement;
    }

    /** @return list<array<string, mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null The first row, or null if there is none. */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** The first column of the first row, or null. Handy for COUNT(*). */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Runs an INSERT/UPDATE/DELETE and returns the number of affected rows. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** Runs an INSERT and returns the new row's id. */
    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) self::connection()->lastInsertId();
    }

    /**
     * Runs $callback inside a transaction. Commits if it returns, rolls back
     * and re-throws if it throws. A nested call joins the outer transaction.
     *
     *     Database::transaction(function () use ($eventId) {
     *         $event = Database::fetchOne('SELECT ... FROM events WHERE id = ? FOR UPDATE', [$eventId]);
     *         ...
     *     });
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Tells the database triggers who is acting, so audit_logs records the
     * right actor_id and ip_address. Call it after sign-in is resolved.
     */
    public static function setAuditContext(?int $userId, ?string $ip): void
    {
        self::$auditUserId = $userId;
        self::$auditIp = $ip;
        if (self::$pdo !== null) {
            self::applyAuditContext();
        }
    }

    /**
     * The business rule a database trigger or CHECK constraint rejected, or
     * null for any other error. Lets controllers show a clear message:
     *
     *     catch (PDOException $e) {
     *         $rule = Database::violatedRule($e);   // "VENUE_CLASH", "CAPACITY", ...
     *         if ($rule === null) throw $e;
     *         $error = t('db.' . $rule);
     *     }
     *
     * Trigger rules: LIFECYCLE, CAPACITY, VENUE_CLASH, RATING, AUDIT, SCANS.
     * CHECK constraints return their name, e.g. chk_events_no_overbook.
     * Duplicate keys return DUPLICATE.
     */
    public static function violatedRule(PDOException $e): ?string
    {
        $info = $e->errorInfo ?? [];
        $sqlState = (string) ($info[0] ?? $e->getCode());
        $driverCode = (int) ($info[1] ?? 0);
        $message = (string) ($info[2] ?? $e->getMessage());

        if ($sqlState === '45000' && preg_match('/^([A-Z_]+):/', $message, $m) === 1) {
            return $m[1];
        }
        if ($driverCode === 3819 && preg_match("/constraint '([A-Za-z0-9_]+)'/", $message, $m) === 1) {
            return $m[1];
        }
        if ($driverCode === 1062) {
            return 'DUPLICATE';
        }
        return null;
    }

    /** Number of queries run in this request (for the developer toolbar). */
    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /** Closes the connection. Used by tests; normal code never needs it. */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    /**
     * Connect as a different account from now on. Used by the test suites,
     * which need a privileged account to build fixtures and the application
     * account to prove what the application is refused.
     *
     * @param array{user:string,pass:string}|null $credentials null restores .env
     */
    public static function useCredentials(?array $credentials): void
    {
        self::$override = $credentials;
        self::$pdo = null;
    }

    private static function connect(): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('The pdo_mysql PHP extension is not enabled. Enable "extension=pdo_mysql" in php.ini and restart the server.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) config('db.host', '127.0.0.1'),
            (int) config('db.port', 3306),
            (string) config('db.name', 'taylors_events'),
        );

        $user = self::$override['user'] ?? (string) config('db.user', '');
        $pass = self::$override['pass'] ?? (string) config('db.pass', '');
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Keep MySQL's NOW() in the same time zone as PHP's date().
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    }

    private static function applyAuditContext(): void
    {
        $statement = self::$pdo?->prepare('SET @app_user_id = ?, @app_ip = ?');
        if ($statement === null) {
            return;
        }
        $statement->bindValue(1, self::$auditUserId, self::$auditUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(2, self::$auditIp, self::$auditIp === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }

    /** @return array{0: mixed, 1: int} */
    private static function bindable(mixed $value, string $key): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_bool($value) => [(int) $value, PDO::PARAM_INT],
            is_float($value), is_string($value) => [(string) $value, PDO::PARAM_STR],
            $value instanceof DateTimeInterface => [$value->format('Y-m-d H:i:s'), PDO::PARAM_STR],
            default => throw new InvalidArgumentException("Parameter \"$key\" must be a scalar, null or DateTime, got " . get_debug_type($value)),
        };
    }
}
