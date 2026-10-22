<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

/**
 * Typed accessor over the system_settings table (non-secret operational values).
 */
final class Settings
{
    /** @var array<string,array{value:?string,type:string}>|null */
    private static ?array $cache = null;

    /** Defaults used when a key is missing (fresh install or new setting). */
    private const DEFAULTS = [
        'sending_enabled'             => ['1', 'bool'],
        'kill_switch_engaged'         => ['0', 'bool'],
        'kill_switch_reason'          => ['', 'string'],
        'max_send_rate_per_minute'    => ['120', 'int'],
        'max_sends_per_day'           => ['50000', 'int'],
        'max_sends_per_cron_run'      => ['400', 'int'],
        'bounce_rate_warning_pct'     => ['2.0', 'float'],
        'bounce_rate_critical_pct'    => ['5.0', 'float'],
        'complaint_rate_warning_pct'  => ['0.05', 'float'],
        'complaint_rate_critical_pct' => ['0.1', 'float'],
        'complaint_spike_auto_pause'  => ['1', 'bool'],
        'alert_rolling_window_hours'  => ['72', 'int'],
        'alert_min_sample_size'       => ['200', 'int'],
        'cron_stale_minutes'          => ['30', 'int'],
        'campaign_overdue_minutes'    => ['20', 'int'],
        'monitoring_stale_minutes'    => ['60', 'int'],
        'import_retention_hours'      => ['48', 'int'],
        'import_max_file_mb'          => ['64', 'int'],
        'import_batch_rows'           => ['500', 'int'],
        'recipient_build_batch'       => ['2000', 'int'],
        'soft_bounce_max_attempts'    => ['3', 'int'],
        'reconcile_stale_minutes'     => ['15', 'int'],
        'default_timezone'            => ['UTC', 'string'],
        'tracking_base_url'           => ['', 'string'],
        'company_postal_address'      => ['', 'string'],
        'test_send_allowed_domains'   => ['[]', 'json'],
        'personalization_fallbacks'   => ['{"first_name":"there","last_name":"","company_name":"your company","website":"","email":""}', 'json'],
    ];

    /** Keys a user may change through the settings API. */
    public const EDITABLE = [
        'sending_enabled', 'kill_switch_engaged', 'kill_switch_reason',
        'max_send_rate_per_minute', 'max_sends_per_day', 'max_sends_per_cron_run',
        'bounce_rate_warning_pct', 'bounce_rate_critical_pct',
        'complaint_rate_warning_pct', 'complaint_rate_critical_pct',
        'complaint_spike_auto_pause', 'alert_rolling_window_hours', 'alert_min_sample_size',
        'cron_stale_minutes', 'campaign_overdue_minutes', 'monitoring_stale_minutes',
        'import_retention_hours', 'import_max_file_mb', 'import_batch_rows',
        'recipient_build_batch', 'soft_bounce_max_attempts', 'reconcile_stale_minutes',
        'default_timezone', 'tracking_base_url', 'company_postal_address',
        'test_send_allowed_domains', 'personalization_fallbacks',
    ];

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,array{value:?string,type:string}> */
    private static function rows(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $cache = [];
        foreach (self::DEFAULTS as $key => [$value, $type]) {
            $cache[$key] = ['value' => $value, 'type' => $type];
        }

        foreach (Database::all('SELECT setting_key, setting_value, value_type FROM system_settings') as $row) {
            $cache[(string) $row['setting_key']] = [
                'value' => $row['setting_value'] === null ? null : (string) $row['setting_value'],
                'type'  => (string) $row['value_type'],
            ];
        }

        self::$cache = $cache;
        return $cache;
    }

    public static function raw(string $key): ?string
    {
        return self::rows()[$key]['value'] ?? null;
    }

    public static function type(string $key): string
    {
        return self::rows()[$key]['type'] ?? 'string';
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::raw($key);
        return $value === null || $value === '' ? $default : $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::raw($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::raw($key);
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::raw($key);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return array<array-key,mixed> */
    public static function json(string $key, array $default = []): array
    {
        $value = self::raw($key);
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        $type = self::type($key);
        $encoded = match ($type) {
            'bool'  => (is_bool($value) ? $value : filter_var((string) $value, FILTER_VALIDATE_BOOL)) ? '1' : '0',
            'int'   => (string) (int) $value,
            'float' => (string) (float) $value,
            'json'  => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };

        Database::execute(
            'INSERT INTO system_settings (setting_key, setting_value, value_type, updated_by)
             VALUES (:k, :v, :t, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            ['k' => $key, 'v' => $encoded, 't' => $type, 'u' => $userId]
        );

        self::$cache = null;
    }

    /** @return array<string,mixed> Typed snapshot for the settings API. */
    public static function allTyped(): array
    {
        $out = [];
        foreach (self::rows() as $key => $row) {
            $out[$key] = match ($row['type']) {
                'bool'  => filter_var((string) $row['value'], FILTER_VALIDATE_BOOL),
                'int'   => (int) $row['value'],
                'float' => (float) $row['value'],
                'json'  => json_decode((string) $row['value'], true) ?? [],
                default => (string) $row['value'],
            };
        }
        return $out;
    }

    /** Sending is allowed only when enabled AND the kill switch is released. */
    public static function sendingAllowed(): bool
    {
        return self::bool('sending_enabled', true) && !self::bool('kill_switch_engaged', false);
    }
}
