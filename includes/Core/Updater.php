<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use Throwable;

use const TechzappCrm\VERSION;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updates from GitHub Releases.
 *
 * Uses the "Update URI" header mechanism WordPress added in 5.8: because the
 * header names github.com, core calls the update_plugins_github.com filter for
 * this plugin and leaves every other plugin alone. That is considerably safer
 * than rewriting the whole update_plugins transient, which is the older trick
 * and a common way to break unrelated plugins.
 *
 * It deliberately only offers an update when a release has a built ZIP asset
 * attached. GitHub's automatic source zipball would install a plugin with no
 * vendor directory — no AWS SDK, no HTMLPurifier — and the site would fatal on
 * the next send. A missing asset is reported as "no update", never installed.
 */
final class Updater
{
    public const REPOSITORY = 'appcoderikbal/crm-tech';

    /** The built asset produced by bin/build-release.sh. */
    public const ASSET_NAME = 'techzapp-crm.zip';

    private const CACHE_KEY = 'techzapp_crm_update_check';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public static function register(): void
    {
        // Fires only for plugins whose Update URI points at github.com.
        add_filter('update_plugins_github.com', [self::class, 'checkForUpdate'], 10, 3);

        // The "View details" modal.
        add_filter('plugins_api', [self::class, 'pluginInformation'], 10, 3);

        // A manual re-check link on the Plugins screen.
        add_filter('plugin_row_meta', [self::class, 'rowMeta'], 10, 2);
        add_action('admin_init', [self::class, 'handleManualCheck']);

        // GitHub names the extracted folder after the ZIP, which may not match
        // the installed directory; this keeps the plugin path stable.
        add_filter('upgrader_source_selection', [self::class, 'normaliseFolderName'], 10, 4);

        // A fresh check after any update, so the notice clears immediately.
        add_action('upgrader_process_complete', [self::class, 'flushCache'], 10, 0);
    }

    /**
     * Tells WordPress whether a newer release exists.
     *
     * @param array<string,mixed>|false $update
     * @param array<string,string>      $pluginData
     * @return array<string,mixed>|false
     */
    public static function checkForUpdate(array|false $update, array $pluginData, string $pluginFile): array|false
    {
        if ($pluginFile !== TECHZAPP_CRM_BASENAME) {
            return $update;
        }

        $release = self::latestRelease();
        if ($release === null) {
            return $update;
        }

        if (version_compare($release['version'], VERSION, '<=')) {
            return $update;
        }

        return [
            'slug'    => dirname(TECHZAPP_CRM_BASENAME),
            'version' => $release['version'],
            'url'     => $release['url'],
            'package' => $release['package'],
            'tested'  => $release['tested'],
            'requires_php' => $pluginData['RequiresPHP'] ?? '8.2',
        ];
    }

    /**
     * Fills the "View details" modal.
     *
     * @param object|array<string,mixed>|false $result
     * @param array<string,mixed>|object       $args
     */
    public static function pluginInformation(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $slug = is_object($args) ? ($args->slug ?? '') : ($args['slug'] ?? '');
        if ($slug !== dirname(TECHZAPP_CRM_BASENAME)) {
            return $result;
        }

        $release = self::latestRelease();
        if ($release === null) {
            return $result;
        }

        $information = new \stdClass();
        $information->name = 'Techzapp CRM';
        $information->slug = $slug;
        $information->version = $release['version'];
        $information->author = '<a href="https://github.com/' . self::REPOSITORY . '">Techzapp</a>';
        $information->homepage = 'https://github.com/' . self::REPOSITORY;
        $information->download_link = $release['package'];
        $information->requires = '6.0';
        $information->requires_php = '8.2';
        $information->tested = $release['tested'];
        $information->last_updated = $release['published_at'];
        $information->sections = [
            'description' => wp_kses_post(
                '<p>' . esc_html__('Email marketing CRM for WordPress: contacts, lists, campaigns, Amazon SES sending, tracking and deliverability monitoring.', 'techzapp-crm') . '</p>'
            ),
            'changelog'   => self::renderChangelog($release['notes']),
        ];

        return $information;
    }

    /**
     * Looks up the newest release, cached.
     *
     * @return array{version:string,package:string,url:string,notes:string,published_at:string,tested:string}|null
     */
    public static function latestRelease(bool $force = false): ?array
    {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached['release'] ?? null;
            }
        }

        $release = self::fetchLatestRelease();

        // Cache the miss too, so a rate-limited or offline check does not
        // retry on every admin page load.
        set_site_transient(self::CACHE_KEY, ['release' => $release, 'at' => time()], self::CACHE_TTL);

        return $release;
    }

    /** @return array{version:string,package:string,url:string,notes:string,published_at:string,tested:string}|null */
    private static function fetchLatestRelease(): ?array
    {
        $endpoint = sprintf('https://api.github.com/repos/%s/releases/latest', self::REPOSITORY);

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'TechzappCRM/' . VERSION . '; ' . home_url('/'),
        ];

        // Optional, for a private repository or to lift the 60/hour anonymous
        // rate limit: define('TECHZAPP_CRM_GITHUB_TOKEN', 'ghp_…') in wp-config.
        if (defined('TECHZAPP_CRM_GITHUB_TOKEN') && TECHZAPP_CRM_GITHUB_TOKEN !== '') {
            $headers['Authorization'] = 'Bearer ' . TECHZAPP_CRM_GITHUB_TOKEN;
        }

        try {
            $response = wp_remote_get($endpoint, ['timeout' => 10, 'headers' => $headers]);

            if (is_wp_error($response)) {
                Logger::warning('updater.request_failed', ['error' => $response->get_error_message()]);
                return null;
            }

            $status = (int) wp_remote_retrieve_response_code($response);

            if ($status === 404) {
                // No releases published yet. Normal on a fresh repository.
                return null;
            }

            if ($status !== 200) {
                Logger::warning('updater.unexpected_status', ['status' => $status]);
                return null;
            }

            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($body)) {
                return null;
            }

            if (!empty($body['draft']) || !empty($body['prerelease'])) {
                return null;
            }

            $version = ltrim((string) ($body['tag_name'] ?? ''), 'vV');
            if ($version === '' || !preg_match('/^\d+(\.\d+)*/', $version)) {
                return null;
            }

            $package = self::findAsset($body['assets'] ?? []);
            if ($package === null) {
                // Refusing rather than falling back to the source zipball: that
                // archive has no vendor directory and would break the site.
                Logger::warning('updater.no_asset', [
                    'version' => $version,
                    'expected' => self::ASSET_NAME,
                ]);
                return null;
            }

            return [
                'version'      => $version,
                'package'      => $package,
                'url'          => (string) ($body['html_url'] ?? ''),
                'notes'        => (string) ($body['body'] ?? ''),
                'published_at' => (string) ($body['published_at'] ?? ''),
                'tested'       => self::testedVersion((string) ($body['body'] ?? '')),
            ];
        } catch (Throwable $e) {
            Logger::exception('updater.failed', $e);
            return null;
        }
    }

    /** @param array<int,array<string,mixed>> $assets */
    private static function findAsset(array $assets): ?string
    {
        foreach ($assets as $asset) {
            if (($asset['name'] ?? '') === self::ASSET_NAME) {
                $url = (string) ($asset['browser_download_url'] ?? '');
                return $url !== '' ? $url : null;
            }
        }

        return null;
    }

    /** Reads "Tested up to: 6.8" from the release notes, if present. */
    private static function testedVersion(string $notes): string
    {
        if (preg_match('/Tested up to:\s*([\d.]+)/i', $notes, $matches) === 1) {
            return $matches[1];
        }

        return get_bloginfo('version');
    }

    private static function renderChangelog(string $notes): string
    {
        if (trim($notes) === '') {
            return '<p>' . esc_html__('No release notes were provided.', 'techzapp-crm') . '</p>';
        }

        // Release notes are Markdown; this renders the small subset that
        // actually appears in them rather than pulling in a parser.
        $escaped = esc_html($notes);
        $escaped = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^[*-] (.+)$/m', '<li>$1</li>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $escaped) ?? $escaped;
        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;

        return wp_kses_post(wpautop($escaped));
    }

    /**
     * Renames the extracted folder to match the installed plugin directory.
     *
     * A release ZIP built by bin/build-release.sh already contains a
     * techzapp-crm/ folder, so normally this is a no-op — but it costs nothing
     * and prevents a duplicate plugin directory if the archive is ever built
     * by hand with a different name.
     */
    public static function normaliseFolderName(mixed $source, mixed $remoteSource, mixed $upgrader, mixed $args): mixed
    {
        global $wp_filesystem;

        if (!is_string($source) || !is_array($args)) {
            return $source;
        }
        if (($args['plugin'] ?? '') !== TECHZAPP_CRM_BASENAME) {
            return $source;
        }

        $expected = dirname(TECHZAPP_CRM_BASENAME);
        if (basename(untrailingslashit($source)) === $expected) {
            return $source;
        }

        $corrected = trailingslashit((string) $remoteSource) . $expected;

        if ($wp_filesystem !== null && $wp_filesystem->move($source, $corrected)) {
            return trailingslashit($corrected);
        }

        return $source;
    }

    public static function flushCache(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    /** Adds a "Check for updates" link under the plugin row. */
    public static function rowMeta(array $links, string $pluginFile): array
    {
        if ($pluginFile !== TECHZAPP_CRM_BASENAME || !current_user_can('update_plugins')) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(wp_nonce_url(
                add_query_arg('techzapp_crm_check_update', '1', admin_url('plugins.php')),
                'techzapp_crm_check_update'
            )),
            esc_html__('Check for updates', 'techzapp-crm')
        );

        return $links;
    }

    public static function handleManualCheck(): void
    {
        if (!isset($_GET['techzapp_crm_check_update'])) {
            return;
        }
        if (!current_user_can('update_plugins')) {
            return;
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'techzapp_crm_check_update')) {
            return;
        }

        self::flushCache();
        $release = self::latestRelease(true);

        // Make core re-evaluate immediately rather than on its own schedule.
        delete_site_transient('update_plugins');
        wp_update_plugins();

        $message = $release === null
            ? __('No published release with an installable ZIP was found. Builds are attached to GitHub releases by bin/build-release.sh.', 'techzapp-crm')
            : (version_compare($release['version'], VERSION, '>')
                ? sprintf(
                    /* translators: %s: version number */
                    __('Version %s is available.', 'techzapp-crm'),
                    $release['version']
                )
                : sprintf(
                    /* translators: %s: version number */
                    __('You are on the latest version (%s).', 'techzapp-crm'),
                    VERSION
                ));

        set_transient('techzapp_crm_update_notice', $message, 60);

        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    /** Shows the result of a manual check. Hooked by the admin bootstrap. */
    public static function renderCheckNotice(): void
    {
        $message = get_transient('techzapp_crm_update_notice');
        if (!is_string($message) || $message === '') {
            return;
        }

        delete_transient('techzapp_crm_update_notice');

        printf(
            '<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Techzapp CRM:', 'techzapp-crm'),
            esc_html($message)
        );
    }

    /** Current state, for the Settings screen. */
    public static function status(): array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        $release = is_array($cached) ? ($cached['release'] ?? null) : null;

        return [
            'installed'     => VERSION,
            'latest'        => $release['version'] ?? null,
            'update_ready'  => $release !== null && version_compare($release['version'], VERSION, '>'),
            'release_url'   => $release['url'] ?? null,
            'checked_at'    => is_array($cached) ? ($cached['at'] ?? null) : null,
            'repository'    => self::REPOSITORY,
        ];
    }
}
