<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Secrets and environment-level configuration.
 *
 * Order of precedence, strongest first:
 *
 *   1. A constant in wp-config.php          <- recommended for credentials
 *   2. An environment variable
 *   3. The settings table (options)         <- convenient, but readable by
 *                                              anything with database access
 *
 * The Settings screen says plainly which source is in use, so nobody has to
 * guess whether the key they are looking at is the one actually being used.
 */
final class Config
{
    /**
     * AWS access key id.
     *
     * wp-config.php:
     *   define('TECHZAPP_CRM_AWS_ACCESS_KEY_ID', 'AKIA…');
     */
    public static function awsAccessKeyId(): string
    {
        return self::resolve('TECHZAPP_CRM_AWS_ACCESS_KEY_ID', 'ses_access_key_id');
    }

    /**
     * AWS secret access key.
     *
     * wp-config.php:
     *   define('TECHZAPP_CRM_AWS_SECRET_ACCESS_KEY', '…');
     */
    public static function awsSecretAccessKey(): string
    {
        return self::resolve('TECHZAPP_CRM_AWS_SECRET_ACCESS_KEY', 'ses_secret_access_key');
    }

    /** Where the AWS credentials actually came from, for the Settings screen. */
    public static function credentialSource(): string
    {
        if (self::constant('TECHZAPP_CRM_AWS_ACCESS_KEY_ID') !== '') {
            return 'constant';
        }
        if (self::env('TECHZAPP_CRM_AWS_ACCESS_KEY_ID') !== '') {
            return 'environment';
        }
        if (Settings::string('ses_access_key_id') !== '') {
            return 'database';
        }
        return 'none';
    }

    public static function awsRegion(): string
    {
        $constant = self::constant('TECHZAPP_CRM_AWS_REGION');
        return $constant !== '' ? $constant : Settings::string('ses_region', 'us-east-1');
    }

    public static function mailTransport(): string
    {
        $constant = self::constant('TECHZAPP_CRM_MAIL_TRANSPORT');
        $value = $constant !== '' ? $constant : Settings::string('mail_transport', 'ses');

        return in_array($value, ['ses', 'log'], true) ? $value : 'ses';
    }

    /**
     * Signing key for tracking and unsubscribe URLs.
     *
     * Defaults to a WordPress salt, which already exists in wp-config.php and
     * is stable for the life of the install. Define TECHZAPP_CRM_SECRET if you
     * ever need to rotate the salts without invalidating links in email that
     * has already been delivered.
     */
    public static function secretKey(): string
    {
        $constant = self::constant('TECHZAPP_CRM_SECRET');
        if ($constant !== '') {
            $decoded = base64_decode($constant, true);
            return $decoded !== false && strlen($decoded) >= 16 ? $decoded : $constant;
        }

        return wp_salt('techzapp_crm_tracking');
    }

    /** True when links are signed with a WordPress salt rather than a dedicated secret. */
    public static function usingSaltDerivedSecret(): bool
    {
        return self::constant('TECHZAPP_CRM_SECRET') === '';
    }

    /** Base URL for tracking, unsubscribe and webhook endpoints. */
    public static function siteUrl(): string
    {
        $configured = Settings::string('tracking_base_url');
        if ($configured !== '') {
            return untrailingslashit($configured);
        }
        return untrailingslashit((string) home_url());
    }

    /** Absolute path to the plugin's private storage directory. */
    public static function storagePath(string $append = ''): string
    {
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'techzapp-crm';

        return $append === '' ? $base : $base . '/' . ltrim($append, '/');
    }

    public static function isDebug(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /** Hard wall-clock budget for one background run, under the host's limit. */
    public static function maxRuntimeSeconds(): int
    {
        $constant = self::constant('TECHZAPP_CRM_MAX_RUNTIME');
        $value = $constant !== '' ? (int) $constant : 50;

        return max(10, min(300, $value));
    }

    private static function resolve(string $constant, string $settingKey): string
    {
        $value = self::constant($constant);
        if ($value !== '') {
            return $value;
        }

        $value = self::env($constant);
        if ($value !== '') {
            return $value;
        }

        return Settings::string($settingKey);
    }

    private static function constant(string $name): string
    {
        return defined($name) && is_scalar(constant($name)) ? trim((string) constant($name)) : '';
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }
}
