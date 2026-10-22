<?php

declare(strict_types=1);

namespace Techzapp\Crm;

use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\Logger;

/**
 * Single entry point for every execution context: the API front controller,
 * the public tracking/unsubscribe endpoints, the SNS webhook and the cron CLI
 * scripts all boot through here.
 */
final class Bootstrap
{
    private static bool $booted = false;

    public static function init(string $channel = 'app'): Config
    {
        if (!self::$booted) {
            require_once dirname(__DIR__) . '/vendor/autoload.php';
        }

        $config = Config::load();

        if (!self::$booted) {
            date_default_timezone_set('UTC'); // all internal timestamps are UTC
            mb_internal_encoding('UTF-8');

            Logger::configure($config->string('storage.logs', dirname(__DIR__) . '/storage/logs'), $channel);

            // Errors go to the log, never to the response body.
            ini_set('display_errors', $config->isDebug() ? '1' : '0');
            ini_set('log_errors', '1');
            error_reporting(E_ALL);

            set_exception_handler(static function (\Throwable $e): void {
                Logger::exception('uncaught_exception', $e);

                if (PHP_SAPI === 'cli') {
                    // A CLI failure must be loud and must fail the exit code,
                    // or a broken cron job looks like a successful one.
                    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n"
                        . '  at ' . $e->getFile() . ':' . $e->getLine() . "\n");
                    exit(1);
                }

                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => ['code' => 'server_error', 'message' => 'An unexpected error occurred.']]);
                }
            });

            self::$booted = true;
        }

        Logger::channel($channel);
        return $config;
    }

    public static function basePath(string $append = ''): string
    {
        $base = dirname(__DIR__);
        return $append === '' ? $base : $base . '/' . ltrim($append, '/');
    }
}
