<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;
use PDOException;
use RuntimeException;

/**
 * A business rule said no.
 *
 * $rule is a stable name such as VENUE_CLASH or EVENT_FULL. The same names
 * are raised by the PHP services and by the database triggers, and each one
 * has a sentence under "rule.<NAME>" in app/Views/lang/en.php. Controllers
 * catch this exception and show that sentence; they never show SQL errors.
 */
final class RuleViolation extends RuntimeException
{
    /** Names of database constraints, translated to the rule they protect. */
    private const CONSTRAINTS = [
        'chk_events_no_overbook' => 'EVENT_FULL',
        'chk_events_time' => 'EVENT_TIME',
        'chk_events_capacity' => 'EVENT_CAPACITY',
        'chk_registrations_checkin' => 'NOT_CONFIRMED',
        'chk_ratings_range' => 'RATING',
    ];

    /** @param array<string, int|string> $context values for the message placeholders */
    public function __construct(public readonly string $rule, public readonly array $context = [])
    {
        parent::__construct($rule);
    }

    /** The sentence to show the user. */
    public function text(): string
    {
        return t('rule.' . $this->rule, $this->context);
    }

    /** Turn a refusal by the database into the rule it enforces, or null if it is some other error. */
    public static function fromDatabase(PDOException $e): ?self
    {
        $name = Database::violatedRule($e);
        if ($name === null) {
            return null;
        }
        return new self(self::CONSTRAINTS[$name] ?? $name);
    }

    /**
     * Run $work and rethrow any database refusal as a RuleViolation.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (PDOException $e) {
            throw self::fromDatabase($e) ?? $e;
        }
    }
}
