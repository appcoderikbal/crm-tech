<?php

declare(strict_types=1);

namespace Techzapp\Crm\Http;

use DateTimeImmutable;
use DateTimeZone;
use Techzapp\Crm\Support\EmailAddress;
use Throwable;

/**
 * Server-side input validation. Every write endpoint runs its payload through
 * this before touching the database — the frontend's own checks are a
 * convenience, never a control.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $clean = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /** @param array<string,mixed> $data */
    public static function make(array $data): self
    {
        return new self($data);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function string(
        string $field,
        bool $required = false,
        int $max = 255,
        int $min = 0,
        ?string $default = null
    ): ?string {
        $value = $this->data[$field] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
                return null;
            }
            return $this->clean[$field] = $default;
        }
        if (!is_scalar($value)) {
            $this->errors[$field] = 'Must be a string.';
            return null;
        }

        $string = trim((string) $value);
        // Strip control characters; they have no legitimate use in these fields.
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string) ?? $string;

        if (mb_strlen($string) < $min) {
            $this->errors[$field] = "Must be at least {$min} characters.";
            return null;
        }
        if (mb_strlen($string) > $max) {
            $this->errors[$field] = "Must not exceed {$max} characters.";
            return null;
        }

        return $this->clean[$field] = $string;
    }

    /** Long-form text (HTML bodies); control characters other than CR/LF/TAB removed. */
    public function text(string $field, bool $required = false, int $max = 2_000_000): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
                return null;
            }
            return $this->clean[$field] = null;
        }
        if (!is_string($value)) {
            $this->errors[$field] = 'Must be text.';
            return null;
        }
        if (strlen($value) > $max) {
            $this->errors[$field] = 'Content is too large.';
            return null;
        }
        return $this->clean[$field] = $value;
    }

    public function email(string $field, bool $required = false): ?string
    {
        $value = $this->string($field, $required, EmailAddress::MAX_LENGTH);
        if ($value === null) {
            return null;
        }
        if (!EmailAddress::isValid($value)) {
            $this->errors[$field] = 'Enter a valid email address.';
            return null;
        }
        return $this->clean[$field] = $value;
    }

    public function int(string $field, bool $required = false, ?int $min = null, ?int $max = null, ?int $default = null): ?int
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
                return null;
            }
            return $this->clean[$field] = $default;
        }
        if (!is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            $this->errors[$field] = 'Must be a whole number.';
            return null;
        }
        $number = (int) $value;
        if ($min !== null && $number < $min) {
            $this->errors[$field] = "Must be at least {$min}.";
            return null;
        }
        if ($max !== null && $number > $max) {
            $this->errors[$field] = "Must not exceed {$max}.";
            return null;
        }
        return $this->clean[$field] = $number;
    }

    public function float(string $field, bool $required = false, ?float $min = null, ?float $max = null, ?float $default = null): ?float
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
                return null;
            }
            return $this->clean[$field] = $default;
        }
        if (!is_numeric($value)) {
            $this->errors[$field] = 'Must be a number.';
            return null;
        }
        $number = (float) $value;
        if ($min !== null && $number < $min) {
            $this->errors[$field] = "Must be at least {$min}.";
            return null;
        }
        if ($max !== null && $number > $max) {
            $this->errors[$field] = "Must not exceed {$max}.";
            return null;
        }
        return $this->clean[$field] = $number;
    }

    public function bool(string $field, bool $default = false): bool
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            return $this->clean[$field] = $default;
        }
        return $this->clean[$field] = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @param list<string> $allowed */
    public function enum(string $field, array $allowed, bool $required = false, ?string $default = null): ?string
    {
        $value = $this->string($field, $required, 100);
        if ($value === null) {
            return $this->clean[$field] = $default;
        }
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = 'Must be one of: ' . implode(', ', $allowed) . '.';
            return null;
        }
        return $this->clean[$field] = $value;
    }

    /** @return list<int>|null */
    public function intList(string $field, bool $required = false, int $maxItems = 10000): ?array
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === []) {
            if ($required) {
                $this->errors[$field] = 'Select at least one item.';
                return null;
            }
            return $this->clean[$field] = [];
        }
        if (!is_array($value)) {
            $this->errors[$field] = 'Must be a list.';
            return null;
        }
        if (count($value) > $maxItems) {
            $this->errors[$field] = "Too many items (maximum {$maxItems}).";
            return null;
        }

        $ids = [];
        foreach ($value as $item) {
            if (!is_numeric($item) || (int) $item <= 0) {
                $this->errors[$field] = 'Contains an invalid identifier.';
                return null;
            }
            $ids[] = (int) $item;
        }
        return $this->clean[$field] = array_values(array_unique($ids));
    }

    public function url(string $field, bool $required = false, int $max = 255): ?string
    {
        $value = $this->string($field, $required, $max);
        if ($value === null || $value === '') {
            return $value;
        }
        // Accept bare domains from imports; store them as-is but reject schemes
        // that could execute (javascript:, data:, file:).
        if (preg_match('#^(javascript|data|vbscript|file):#i', $value) === 1) {
            $this->errors[$field] = 'This URL scheme is not allowed.';
            return null;
        }
        return $this->clean[$field] = $value;
    }

    public function timezone(string $field, bool $required = false, string $default = 'UTC'): string
    {
        $value = $this->string($field, $required, 64, 0, $default) ?? $default;
        if (!in_array($value, timezone_identifiers_list(), true)) {
            $this->errors[$field] = 'Unknown timezone.';
            return $default;
        }
        return $this->clean[$field] = $value;
    }

    /**
     * Parses a local date/time in $timezone and returns it as a UTC
     * "Y-m-d H:i:s" string. Scheduling is stored in UTC everywhere.
     */
    public function dateTimeToUtc(string $field, string $timezone, bool $required = false): ?string
    {
        $value = $this->string($field, $required, 40);
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $zone = new DateTimeZone($timezone);
            $local = new DateTimeImmutable($value, $zone);
            return $this->clean[$field] = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $this->errors[$field] = 'Enter a valid date and time.';
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public function jsonObject(string $field, int $maxKeys = 50, int $maxValueLength = 1000): ?array
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '' || $value === []) {
            return $this->clean[$field] = null;
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            $this->errors[$field] = 'Must be an object.';
            return null;
        }
        if (count($value) > $maxKeys) {
            $this->errors[$field] = "Too many keys (maximum {$maxKeys}).";
            return null;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,40}$/', $key) !== 1) {
                $this->errors[$field] = 'Field names may use letters, numbers and underscores only.';
                return null;
            }
            if (!is_scalar($item) && $item !== null) {
                $this->errors[$field] = 'Values must be text or numbers.';
                return null;
            }
            $string = $item === null ? '' : (string) $item;
            if (mb_strlen($string) > $maxValueLength) {
                $this->errors[$field] = 'A value is too long.';
                return null;
            }
            $clean[strtolower($key)] = $string;
        }
        return $this->clean[$field] = $clean;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Throws a 422 when anything failed. */
    public function validate(): void
    {
        if ($this->errors !== []) {
            throw ApiException::validation($this->errors);
        }
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        $this->validate();
        return $this->clean;
    }
}
