<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Unit tests need nothing but the autoloader. Integration tests need a MySQL
 * database they are allowed to TRUNCATE; point them at one with:
 *
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 \
 *   TEST_DB_NAME=techzapp_crm_test TEST_DB_USER=root TEST_DB_PASSWORD=secret \
 *   vendor/bin/phpunit
 *
 * Without TEST_DB_NAME every integration test is skipped rather than failing,
 * so `composer test` still works on a machine with no database.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Techzapp\Crm\Core\Config;

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$storage = dirname(__DIR__) . '/storage';
foreach (['tmp', 'logs', 'imports', 'sessions'] as $directory) {
    if (!is_dir($storage . '/' . $directory)) {
        @mkdir($storage . '/' . $directory, 0750, true);
    }
}

$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : $value;
};

$databaseName = $env('TEST_DB_NAME');

// A fixed, well-known key: tests assert on signatures, which must be stable.
$config = Config::fromArray([
    'app' => [
        'name'     => 'Techzapp CRM (test)',
        'url'      => 'https://crm.test',
        'env'      => 'testing',
        'debug'    => true,
        'secret'   => base64_encode(str_repeat('techzapp-test-key', 2)),
        'timezone' => 'UTC',
    ],
    'database' => [
        'host'     => $env('TEST_DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('TEST_DB_PORT', '3306'),
        'name'     => $databaseName,
        'user'     => $env('TEST_DB_USER', 'root'),
        'password' => $env('TEST_DB_PASSWORD'),
        'charset'  => 'utf8mb4',
        'timeout'  => 5,
    ],
    'session' => [
        'name'          => 'techzapp_test_session',
        'save_path'     => $storage . '/sessions',
        'lifetime'      => 3600,
        'idle_timeout'  => 3600,
        'secure_cookie' => false,
        'same_site'     => 'Lax',
        'cookie_path'   => '/',
        'cookie_domain' => '',
    ],
    'security' => [
        'login_max_attempts'    => 5,
        'login_window_seconds'  => 900,
        'login_lockout_seconds' => 900,
        'login_ip_max_attempts' => 20,
        'password_min_length'   => 12,
        'cors_allowed_origins'  => [],
        'public_rate_limit'     => ['hits' => 1000, 'window' => 60],
        'max_request_bytes'     => 2097152,
    ],
    'ses' => [
        'region'                      => 'eu-west-1',
        'access_key_id'               => '',
        'secret_access_key'           => '',
        'configuration_set'           => 'test-set',
        'verified_identities'         => ['sender@example.com', 'techzapp.test'],
        'enforce_verified_identities' => true,
        'http_timeout'                => 5,
        'connect_timeout'             => 2,
        'transport'                   => 'log',
    ],
    'sns' => [
        'allowed_topic_arns'        => [],
        'verify_signature'          => false,
        'auto_confirm_subscription' => false,
    ],
    'storage' => [
        'imports' => $storage . '/imports',
        'logs'    => $storage . '/logs',
        'tmp'     => $storage . '/tmp',
    ],
    'cron' => ['http_token' => 'test-token', 'max_runtime_seconds' => 20],
]);

Config::setInstance($config);

define('CRM_TEST_DATABASE_CONFIGURED', $databaseName !== '');

if (!CRM_TEST_DATABASE_CONFIGURED) {
    fwrite(STDERR, "TEST_DB_NAME is not set — integration tests will be skipped.\n");
    return;
}

// Apply migrations once per run so a fresh database is usable immediately.
try {
    (new \Techzapp\Crm\Core\Migrator(dirname(__DIR__) . '/migrations'))->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not prepare the test database: ' . $e->getMessage() . "\n");
    throw $e;
}
