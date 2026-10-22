<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File logging into a protected directory under wp-content/uploads.
 *
 * Uploads is the only reliably writable location on shared hosting, so the
 * directory is created with an .htaccess deny rule and an index.php, and the
 * filenames carry a random suffix fixed per install. Even if the host ignores
 * .htaccess, the paths are not guessable.
 */
final class Logger
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private static string $channel = 'app';
    private static ?string $directory = null;

    public static function channel(string $channel): void
    {
        self::$channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?: 'app';
    }

    public static function debug(string $message, array $context = []): void
    {
        if (Config::isDebug()) {
            self::write('DEBUG', $message, $context);
        }
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

    /** Absolute path to the log directory, created and protected on first use. */
    public static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $directory = Config::storagePath('logs');
        Storage::ensureProtectedDirectory($directory);

        self::$directory = $directory;
        return $directory;
    }

    /** @return list<array{name:string,path:string,size:int,modified:int}> */
    public static function files(): array
    {
        $files = [];
        foreach (glob(self::directory() . '/*.log') ?: [] as $path) {
            $files[] = [
                'name'     => basename($path),
                'path'     => $path,
                'size'     => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($files, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return $files;
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            $directory = self::directory();
        } catch (Throwable) {
            return; // logging must never break the request
        }

        $file = sprintf('%s/%s-%s.log', $directory, self::$channel, gmdate('Y-m-d'));

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
        // Defensive: a secret must never reach a log file, whatever the caller passed.
        foreach (['password', 'password_hash', 'secret', 'secret_access_key', 'access_key_id', 'token', 'nonce'] as $key) {
            if (isset($context[$key])) {
                $context[$key] = '[redacted]';
            }
        }

        $json = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return is_string($json) ? $json : '[unencodable context]';
    }
}
