<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Private file storage inside wp-content/uploads.
 *
 * A plugin cannot put files outside the web root the way the standalone app
 * did, so each directory gets three layers instead:
 *
 *   1. .htaccess denying everything (Apache / LiteSpeed, which is what
 *      Hostinger runs)
 *   2. an index.php, so a server with directory listing on reveals nothing
 *   3. filenames carrying a per-install random suffix, so a host that ignores
 *      .htaccess entirely still does not serve guessable URLs
 *
 * Nginx ignores .htaccess; the Settings screen checks for that and says so.
 */
final class Storage
{
    private const SUFFIX_OPTION = 'techzapp_crm_storage_suffix';

    /** Per-install random suffix that makes stored paths unguessable. */
    public static function suffix(): string
    {
        $suffix = get_option(self::SUFFIX_OPTION, '');

        if (!is_string($suffix) || !preg_match('/^[a-f0-9]{16}$/', $suffix)) {
            $suffix = bin2hex(random_bytes(8));
            update_option(self::SUFFIX_OPTION, $suffix, false);
        }

        return $suffix;
    }

    /** Creates a directory and drops the protection files into it. */
    public static function ensureProtectedDirectory(string $path): void
    {
        if (!is_dir($path) && !wp_mkdir_p($path)) {
            throw new RuntimeException('Unable to create storage directory: ' . $path);
        }

        $htaccess = $path . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, implode("\n", [
                '# Files here are private to the Techzapp CRM plugin.',
                '<IfModule mod_authz_core.c>',
                '    Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                '    Order allow,deny',
                '    Deny from all',
                '</IfModule>',
                '',
            ]));
        }

        $index = $path . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    /** Creates every directory the plugin writes to. Called on activation. */
    public static function install(): void
    {
        self::suffix();

        foreach (['', 'logs', 'imports', 'tmp'] as $sub) {
            self::ensureProtectedDirectory(Config::storagePath($sub));
        }
    }

    /**
     * A filename that cannot be guessed from the outside.
     * Example: import-20261022-143012-a1b2c3d4-<install suffix>.csv
     */
    public static function privateFilename(string $prefix, string $extension): string
    {
        return sprintf(
            '%s-%s-%s-%s.%s',
            $prefix,
            gmdate('Ymd-His'),
            bin2hex(random_bytes(4)),
            self::suffix(),
            ltrim($extension, '.')
        );
    }

    /**
     * Whether .htaccess is likely to be honoured.
     *
     * Used by the Settings screen to warn on nginx, where the deny rule does
     * nothing and the random filename is the only thing protecting uploads.
     */
    public static function htaccessLikelyHonoured(): bool
    {
        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        if ($software === '') {
            return true; // cannot tell; do not cry wolf
        }

        return str_contains($software, 'apache') || str_contains($software, 'litespeed');
    }

    /** Deletes every file the plugin stored. Used by the uninstaller. */
    public static function deleteAll(): void
    {
        $base = Config::storagePath();
        if (!is_dir($base)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($base);
    }
}
