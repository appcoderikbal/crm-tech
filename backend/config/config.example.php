<?php
/**
 * Techzapp CRM — configuration template.
 *
 * Copy to config.php and fill in real values. config.php is git-ignored and MUST
 * live outside public_html. Never place secrets in the database, the frontend
 * bundle, or any file served by the web server.
 *
 *   cp backend/config/config.example.php backend/config/config.php
 *   chmod 600 backend/config/config.php
 *
 * Every value may also be supplied through an environment variable of the same
 * name (useful if your Hostinger plan exposes env vars); the file wins only when
 * the env var is absent.
 */

$env = static function (string $key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
};

return [
    // ---------------------------------------------------------------- general
    'app' => [
        'name'        => 'Techzapp CRM',
        // Public origin of the application, no trailing slash. Used to build
        // tracking, unsubscribe and SNS endpoint URLs.
        'url'         => $env('APP_URL', 'https://crm.example.com'),
        // "production" disables detailed error output. Never ship "development".
        'env'         => $env('APP_ENV', 'production'),
        'debug'       => filter_var($env('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL),
        // 32+ random bytes, base64. Generate with:
        //   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        // Rotating this invalidates existing tracking/unsubscribe signatures.
        'secret'      => $env('APP_SECRET', 'CHANGE-ME-generate-a-random-32-byte-base64-secret'),
        'timezone'    => $env('APP_TIMEZONE', 'UTC'),
    ],

    // --------------------------------------------------------------- database
    'database' => [
        'host'     => $env('DB_HOST', 'localhost'),
        'port'     => (int) $env('DB_PORT', 3306),
        'name'     => $env('DB_NAME', 'u000000000_techzapp_crm'),
        'user'     => $env('DB_USER', 'u000000000_crm'),
        'password' => $env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        // Shared hosting connections are cheap but finite; keep this low.
        'timeout'  => 5,
    ],

    // ---------------------------------------------------------------- session
    'session' => [
        'name'            => 'techzapp_crm_session',
        // Sessions are written outside public_html. Must be writable by PHP.
        'save_path'       => dirname(__DIR__) . '/storage/sessions',
        'lifetime'        => 60 * 60 * 8,   // 8 hours
        'idle_timeout'    => 60 * 60 * 2,   // re-auth after 2h of inactivity
        // Set false ONLY for local HTTP development.
        'secure_cookie'   => filter_var($env('SESSION_SECURE', '1'), FILTER_VALIDATE_BOOL),
        'same_site'       => 'Lax',
        'cookie_path'     => '/',
        'cookie_domain'   => $env('SESSION_DOMAIN', ''),
    ],

    // --------------------------------------------------------------- security
    'security' => [
        'login_max_attempts'      => 5,     // per identifier within the window
        'login_window_seconds'    => 900,   // 15 minutes
        'login_lockout_seconds'   => 900,
        'login_ip_max_attempts'   => 20,
        'password_min_length'     => 12,
        // Origins allowed to call the API with credentials. Same-origin
        // deployments (the normal case) can leave this empty.
        'cors_allowed_origins'    => array_filter(explode(',', (string) $env('CORS_ORIGINS', ''))),
        'public_rate_limit'       => ['hits' => 120, 'window' => 60], // tracking/unsubscribe per IP
        'max_request_bytes'       => 2 * 1024 * 1024,
    ],

    // -------------------------------------------------------------- amazon ses
    'ses' => [
        'region'          => $env('AWS_REGION', 'us-east-1'),
        // Prefer an IAM user restricted to ses:SendEmail / ses:GetAccount.
        // Leave both blank to use the default AWS credential chain
        // (env vars, ~/.aws/credentials, instance profile).
        'access_key_id'   => $env('AWS_ACCESS_KEY_ID', ''),
        'secret_access_key' => $env('AWS_SECRET_ACCESS_KEY', ''),
        // Optional SES v2 configuration set that publishes events to SNS.
        'configuration_set' => $env('SES_CONFIGURATION_SET', ''),
        // Verified identities usable as the From address. Checked before send.
        'verified_identities' => array_filter(array_map(
            'trim',
            explode(',', (string) $env('SES_VERIFIED_IDENTITIES', ''))
        )),
        // Refuse to send if the identity is not in the list above.
        'enforce_verified_identities' => true,
        'http_timeout'    => 15,
        'connect_timeout' => 5,
        // 'ses' (real delivery) or 'log' (writes to storage/logs/mail.log, sends nothing).
        'transport'       => $env('MAIL_TRANSPORT', 'ses'),
    ],

    // ----------------------------------------------------------- sns webhook
    'sns' => [
        // Only notifications from these topic ARNs are accepted.
        'allowed_topic_arns' => array_filter(array_map(
            'trim',
            explode(',', (string) $env('SNS_TOPIC_ARNS', ''))
        )),
        // Verifies the cryptographic signature on every SNS message.
        // Disable only when replaying captured payloads in a test environment.
        'verify_signature'   => filter_var($env('SNS_VERIFY_SIGNATURE', '1'), FILTER_VALIDATE_BOOL),
        'auto_confirm_subscription' => true,
    ],

    // -------------------------------------------------------------- storage
    'storage' => [
        'imports' => dirname(__DIR__) . '/storage/imports',
        'logs'    => dirname(__DIR__) . '/storage/logs',
        'tmp'     => dirname(__DIR__) . '/storage/tmp',
    ],

    // ------------------------------------------------------------------ cron
    'cron' => [
        // Shared secret required when a cron script is triggered over HTTP
        // (wget/curl cron). CLI invocations do not need it.
        'http_token'       => $env('CRON_HTTP_TOKEN', ''),
        // Hard wall-clock budget per cron run, below the host's process limit.
        'max_runtime_seconds' => (int) $env('CRON_MAX_RUNTIME', 50),
    ],
];
