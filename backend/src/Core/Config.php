<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

use RuntimeException;

/**
 * Immutable application configuration loaded from backend/config/config.php.
 */
final class Config
{
    private static ?Config $instance = null;

    /** @param array<string,mixed> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(?string $path = null): self
    {
        if (self::$instance !== null && $path === null) {
            return self::$instance;
        }

        $path ??= dirname(__DIR__, 2) . '/config/config.php';
        if (!is_file($path)) {
            throw new RuntimeException(
                'Configuration file is missing. Copy backend/config/config.example.php to ' .
                'backend/config/config.php and fill in your values.'
            );
        }

        /** @var array<string,mixed> $values */
        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException('Configuration file must return an array.');
        }

        self::$instance = new self($values);
        return self::$instance;
    }

    /** Replaces the active instance. Tests only. */
    public static function setInstance(?self $config): void
    {
        self::$instance = $config;
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /** Dot-path lookup: $config->get('database.host'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return filter_var((string) $value, FILTER_VALIDATE_BOOL);
        }
        return $default;
    }

    /** @return array<int|string,mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        return is_array($value) ? $value : $default;
    }

    public function isProduction(): bool
    {
        return $this->string('app.env', 'production') === 'production';
    }

    public function isDebug(): bool
    {
        return $this->bool('app.debug', false) && !$this->isProduction();
    }

    /** Raw signing key derived from app.secret. */
    public function secretKey(): string
    {
        $secret = $this->string('app.secret');
        if ($secret === '' || str_starts_with($secret, 'CHANGE-ME')) {
            throw new RuntimeException('app.secret is not configured. Generate one with: php -r "echo base64_encode(random_bytes(32));"');
        }
        $decoded = base64_decode($secret, true);
        return $decoded !== false && strlen($decoded) >= 16 ? $decoded : $secret;
    }

    public function appUrl(): string
    {
        return rtrim($this->string('app.url'), '/');
    }
}
