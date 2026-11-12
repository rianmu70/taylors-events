<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Collects validation errors as language keys, one per field.
 * Usage: $v = new Validator($input); $v->required('title')->max('title', 150);
 */
final class Validator
{
    /** @var array<string, string> field => language key */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function value(string $field): string
    {
        $value = $this->data[$field] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function required(string $field): self
    {
        return $this->check($field, $this->value($field) !== '', 'validation.required');
    }

    public function length(string $field, int $min, int $max): self
    {
        $length = mb_strlen($this->value($field));
        return $this->check($field, $length >= $min && $length <= $max, 'validation.length', ['min' => $min, 'max' => $max]);
    }

    public function integer(string $field, int $min, int $max): self
    {
        $value = $this->value($field);
        $ok = preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value >= $min && (int) $value <= $max;
        return $this->check($field, $ok, 'validation.integer', ['min' => $min, 'max' => $max]);
    }

    public function dateTime(string $field): self
    {
        return $this->check($field, self::parseDateTime($this->value($field)) !== null, 'validation.datetime');
    }

    public function email(string $field): self
    {
        $value = $this->value($field);
        return $this->check($field, filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 190, 'validation.email');
    }

    /** @param list<string> $allowed */
    public function in(string $field, array $allowed): self
    {
        return $this->check($field, in_array($this->value($field), $allowed, true), 'validation.choice');
    }

    /** Record a rule that the caller evaluated itself. */
    public function rule(string $field, bool $passes, string $key): self
    {
        return $this->check($field, $passes, $key);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> field => translated message */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Accepts the value of <input type="datetime-local"> and plain SQL datetimes. */
    public static function parseDateTime(string $value): ?DateTimeImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }
        return null;
    }

    /** @param array<string, int|string> $replace */
    private function check(string $field, bool $passes, string $key, array $replace = []): self
    {
        if (!$passes && !isset($this->errors[$field])) {
            $this->errors[$field] = t($key, $replace);
        }
        return $this;
    }
}
