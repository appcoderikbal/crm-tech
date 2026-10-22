<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

use Throwable;

/**
 * Minimal file logger. Logs live outside public_html and are rotated by size.
 */
final class Logger
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private static ?string $directory = null;
    private static string $channel = 'app';

    public static function configure(string $directory, string $channel = 'app'): void
    {
        self::$directory = $directory;
        self::$channel = $channel;
    }

    public static function channel(string $channel): void
    {
        self::$channel = $channel;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function exception(string $message, Throwable $e, array $context = []): void
    {
        self::write('ERROR', $message, $context + [
            'exception' => $e::class,
            'error'     => $e->getMessage(),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $directory = self::$directory ?? dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }

        $file = $directory . '/' . self::$channel . '-' . gmdate('Y-m-d') . '.log';
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.' . time());
        }

        $line = sprintf(
            "[%s] %s %s%s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . self::encode($context)
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function encode(array $context): string
    {
        // Defensive: never let a secret reach the log file.
        foreach (['password', 'password_hash', 'secret', 'secret_access_key', 'token', 'csrf'] as $key) {
            if (isset($context[$key])) {
                $context[$key] = '[redacted]';
            }
        }
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '[unencodable context]' : $json;
    }
}
