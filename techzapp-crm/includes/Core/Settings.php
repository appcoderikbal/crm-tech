<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Operational settings, stored in a single WordPress option.
 *
 * One option rather than thirty means one autoloaded row and one cache entry,
 * which matters on shared hosting. Secrets are NOT kept here — see Config.
 */
final class Settings
{
    public const OPTION = 'techzapp_crm_settings';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * Defaults, with the type each value is coerced to.
     *
     * @var array<string,array{0:mixed,1:string}>
     */
    private const DEFAULTS = [
        'sending_enabled'             => [true, 'bool'],
        'kill_switch_engaged'         => [false, 'bool'],
        'kill_switch_reason'          => ['', 'string'],
        'max_send_rate_per_minute'    => [120, 'int'],
        'max_sends_per_day'           => [50000, 'int'],
        'max_sends_per_cron_run'      => [400, 'int'],
        'bounce_rate_warning_pct'     => [2.0, 'float'],
        'bounce_rate_critical_pct'    => [5.0, 'float'],
        'complaint_rate_warning_pct'  => [0.05, 'float'],
        'complaint_rate_critical_pct' => [0.1, 'float'],
        'complaint_spike_auto_pause'  => [true, 'bool'],
        'alert_rolling_window_hours'  => [72, 'int'],
        'alert_min_sample_size'       => [200, 'int'],
        'cron_stale_minutes'          => [30, 'int'],
        'campaign_overdue_minutes'    => [20, 'int'],
        'monitoring_stale_minutes'    => [60, 'int'],
        'import_retention_hours'      => [48, 'int'],
        'import_max_file_mb'          => [64, 'int'],
        'import_batch_rows'           => [500, 'int'],
        'recipient_build_batch'       => [2000, 'int'],
        'soft_bounce_max_attempts'    => [3, 'int'],
        'reconcile_stale_minutes'     => [15, 'int'],
        'default_timezone'            => ['', 'string'],
        'tracking_base_url'           => ['', 'string'],
        'company_postal_address'      => ['', 'string'],
        'test_send_allowed_domains'   => [[], 'array'],
        'personalization_fallbacks'   => [
            [
                'first_name'   => 'there',
                'last_name'    => '',
                'company_name' => 'your company',
                'website'      => '',
                'email'        => '',
            ],
            'array',
        ],

        // Amazon SES. Credentials belong in wp-config.php constants; these are
        // the non-secret parts plus an explicitly-acknowledged fallback.
        'ses_region'                  => ['us-east-1', 'string'],
        'ses_configuration_set'       => ['', 'string'],
        'ses_verified_identities'     => [[], 'array'],
        'ses_access_key_id'           => ['', 'string'],
        'ses_secret_access_key'       => ['', 'string'],
        'mail_transport'              => ['ses', 'string'],
        'sns_allowed_topic_arns'      => [[], 'array'],
        'sns_verify_signature'        => [true, 'bool'],
    ];

    /** Keys that must never be rendered back to the browser. */
    public const SECRET_KEYS = ['ses_secret_access_key'];

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $merged = [];
        foreach (self::DEFAULTS as $key => [$default, $type]) {
            $merged[$key] = array_key_exists($key, $stored)
                ? self::coerce($stored[$key], $type)
                : $default;
        }

        self::$cache = $merged;
        return $merged;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key);
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : (bool) $value;
    }

    /** @return array<array-key,mixed> */
    public static function array(string $key, array $default = []): array
    {
        $value = self::get($key);
        return is_array($value) ? $value : $default;
    }

    /** Kept for parity with the standalone API: JSON settings are plain arrays here. */
    public static function json(string $key, array $default = []): array
    {
        return self::array($key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        self::update([$key => $value]);
    }

    /** @param array<string,mixed> $values */
    public static function update(array $values): void
    {
        $current = self::all();

        foreach ($values as $key => $value) {
            if (!isset(self::DEFAULTS[$key])) {
                continue; // unknown keys are ignored, never stored
            }
            $current[$key] = self::coerce($value, self::DEFAULTS[$key][1]);
        }

        update_option(self::OPTION, $current, false);
        self::$cache = null;
    }

    /** Installs defaults without clobbering anything an upgrade already set. */
    public static function installDefaults(): void
    {
        $stored = get_option(self::OPTION, null);

        if (!is_array($stored)) {
            $defaults = [];
            foreach (self::DEFAULTS as $key => [$default, $_type]) {
                $defaults[$key] = $default;
            }
            $defaults['default_timezone'] = wp_timezone_string() ?: 'UTC';

            add_option(self::OPTION, $defaults, '', false);
            self::$cache = null;
            return;
        }

        // Fill in keys added by a later version.
        $merged = self::all();
        update_option(self::OPTION, $merged, false);
        self::$cache = null;
    }

    /** @return array<string,mixed> Everything except the secrets. */
    public static function publicValues(): array
    {
        $all = self::all();
        foreach (self::SECRET_KEYS as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    /** Sending requires both the soft switch and the kill switch to allow it. */
    public static function sendingAllowed(): bool
    {
        return self::bool('sending_enabled', true) && !self::bool('kill_switch_engaged', false);
    }

    /** The timezone campaigns are scheduled in, falling back to the site's. */
    public static function timezone(): string
    {
        $configured = self::string('default_timezone');
        if ($configured !== '' && in_array($configured, timezone_identifiers_list(), true)) {
            return $configured;
        }
        return wp_timezone_string() ?: 'UTC';
    }

    private static function coerce(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool'  => is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOL) : (bool) $value,
            'int'   => (int) $value,
            'float' => (float) $value,
            'array' => self::coerceArray($value),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /** @return array<array-key,mixed> */
    private static function coerceArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            // A newline or comma separated list from a textarea.
            $parts = preg_split('/[\r\n,]+/', $value) ?: [];
            return array_values(array_filter(array_map('trim', $parts), static fn ($item): bool => $item !== ''));
        }
        return [];
    }
}
