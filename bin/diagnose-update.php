<?php
/**
 * Techzapp CRM — update diagnostic.
 *
 * Reports why the Plugins screen is not offering an update. Read-only: it
 * checks state and makes one outbound request to GitHub. It changes nothing
 * except clearing this plugin's own cached check.
 *
 * Run with WP-CLI from the WordPress root:
 *     wp eval-file diagnose-update.php
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this through WP-CLI: wp eval-file diagnose-update.php\n");
    return;
}

$line = static fn (string $k, string $v) => printf("%-26s %s\n", $k . ':', $v);
$problems = [];

echo "================ Techzapp CRM update diagnostic ================\n\n";

// ---------------------------------------------------------------- environment
$line('WordPress', get_bloginfo('version'));
$line('PHP', PHP_VERSION);

if (version_compare(get_bloginfo('version'), '5.8', '<')) {
    $problems[] = 'WordPress is older than 5.8, which is when the "Update URI" mechanism was added. This plugin cannot self-update on this version.';
}

$line('WP_HTTP_BLOCK_EXTERNAL', defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL ? 'ON' : 'off');
if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
    $accessible = defined('WP_ACCESSIBLE_HOSTS') ? (string) WP_ACCESSIBLE_HOSTS : '';
    $line('WP_ACCESSIBLE_HOSTS', $accessible !== '' ? $accessible : '(none)');
    if (!str_contains($accessible, 'api.github.com')) {
        $problems[] = 'WP_HTTP_BLOCK_EXTERNAL is on and api.github.com is not in WP_ACCESSIBLE_HOSTS, so WordPress is forbidden from contacting GitHub.';
    }
}

$line('token configured', defined('TECHZAPP_CRM_GITHUB_TOKEN') && TECHZAPP_CRM_GITHUB_TOKEN !== '' ? 'yes' : 'no (fine for a public repo)');

// --------------------------------------------------------------- plugin state
echo "\n---- installed plugin ----\n";

if (!defined('TECHZAPP_CRM_BASENAME')) {
    $problems[] = 'TECHZAPP_CRM_BASENAME is not defined, so the plugin is not active.';
    echo "plugin not active - stopping\n";
    goto report;
}

$basename = TECHZAPP_CRM_BASENAME;
$line('basename', $basename);

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$data = get_plugin_data(WP_PLUGIN_DIR . '/' . $basename, false, false);

$line('version (header)', $data['Version'] ?: '(none)');
$line('version (constant)', defined('TechzappCrm\VERSION') ? \TechzappCrm\VERSION : '(none)');

// This is the header core keys the whole mechanism off.
$updateUri = $data['UpdateURI'] ?? '';
$line('Update URI header', $updateUri !== '' ? $updateUri : '*** MISSING ***');

if ($updateUri === '') {
    $problems[] = 'The installed plugin has no "Update URI" header, so WordPress never asks it about updates. The build on this server predates the updater - install the latest ZIP manually once.';
} else {
    $host = wp_parse_url($updateUri, PHP_URL_HOST);
    $line('filter core will call', "update_plugins_{$host}");

    global $wp_filter;
    $hooked = isset($wp_filter["update_plugins_{$host}"]) && !empty($wp_filter["update_plugins_{$host}"]->callbacks);
    $line('plugin listening?', $hooked ? 'yes' : '*** NO ***');
    if (!$hooked) {
        $problems[] = "Nothing is hooked to update_plugins_{$host}; the Updater did not register.";
    }
}

// ------------------------------------------------------------ outbound to API
echo "\n---- reaching GitHub ----\n";

$endpoint = 'https://api.github.com/repos/appcoderikbal/crm-tech/releases/latest';
$headers  = [
    'Accept'     => 'application/vnd.github+json',
    'User-Agent' => 'TechzappCRM-diagnostic; ' . home_url('/'),
];
if (defined('TECHZAPP_CRM_GITHUB_TOKEN') && TECHZAPP_CRM_GITHUB_TOKEN !== '') {
    $headers['Authorization'] = 'Bearer ' . TECHZAPP_CRM_GITHUB_TOKEN;
}

$started  = microtime(true);
$response = wp_remote_get($endpoint, ['timeout' => 15, 'headers' => $headers]);
$elapsed  = round((microtime(true) - $started) * 1000);

if (is_wp_error($response)) {
    $line('request', 'FAILED after ' . $elapsed . 'ms');
    $line('error', $response->get_error_message());
    $problems[] = 'This server cannot reach api.github.com. That is a hosting or firewall matter: ' . $response->get_error_message();
} else {
    $status = (int) wp_remote_retrieve_response_code($response);
    $line('HTTP status', (string) $status . '  (' . $elapsed . 'ms)');

    $remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
    if ($remaining !== '') {
        $line('rate limit left', (string) $remaining);
        if ((int) $remaining === 0) {
            $problems[] = 'This server has exhausted GitHub\'s anonymous rate limit (60/hour, shared across every site on the same IP). Add TECHZAPP_CRM_GITHUB_TOKEN to wp-config.php to lift it to 5,000/hour.';
        }
    }

    if ($status === 404) {
        $problems[] = 'GitHub returned 404. Either the repository is private to this server, or no release is published.';
    } elseif ($status === 403) {
        $problems[] = 'GitHub returned 403 - almost always the anonymous rate limit. Add a token to wp-config.php.';
    } elseif ($status === 200) {
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $line('latest tag', (string) ($body['tag_name'] ?? '?'));
        $line('draft/prerelease', (!empty($body['draft']) ? 'draft ' : '') . (!empty($body['prerelease']) ? 'prerelease' : 'neither'));

        $assets = array_column($body['assets'] ?? [], 'name');
        $line('assets', $assets ? implode(', ', $assets) : '*** none ***');

        if (!in_array('techzapp-crm.zip', $assets, true)) {
            $problems[] = 'The latest release has no techzapp-crm.zip asset. The updater refuses such a release on purpose, because the source archive has no vendor/ directory and would break the site.';
        }
    }
}

// ------------------------------------------------------------ the plugin's own view
echo "\n---- what the updater concludes ----\n";

if (class_exists('TechzappCrm\Core\Updater')) {
    $u = 'TechzappCrm\Core\Updater';

    if (method_exists($u, 'flushCache')) { $u::flushCache(); }
    delete_site_transient('update_plugins');

    $release = $u::latestRelease(true);

    if ($release === null) {
        $line('latestRelease()', 'null - no usable release found');
    } else {
        $line('release version', $release['version']);
        $line('package', $release['package']);

        $installed = defined('TechzappCrm\VERSION') ? \TechzappCrm\VERSION : '0';
        $newer = version_compare($release['version'], $installed, '>');
        $line('newer than installed?', $newer ? 'yes' : 'no (' . $release['version'] . ' vs ' . $installed . ')');

        if (!$newer) {
            $problems[] = 'The published release is not newer than what is installed, so correctly nothing is offered.';
        }
    }

    $offer = $u::checkForUpdate(false, $data ?? [], $basename);
    $line('offer to WordPress', $offer === false ? 'false - no update' : 'version ' . ($offer['version'] ?? '?'));
} else {
    $problems[] = 'TechzappCrm\Core\Updater does not exist in the installed build.';
}

// ------------------------------------------------------- core's own transient
echo "\n---- core update transient ----\n";
wp_update_plugins();
$t = get_site_transient('update_plugins');
$inResponse = isset($t->response[$basename]);
$line('listed as updatable', $inResponse ? 'YES' : 'no');
if ($inResponse) {
    $line('offered version', $t->response[$basename]->new_version ?? '?');
}
if (isset($t->no_update[$basename])) {
    $line('listed as up to date', 'yes');
}

report:
echo "\n================================ verdict ================================\n";
if ($problems === []) {
    echo "No blocking problem found.\n";
    echo "If the Plugins screen still shows nothing, reload it once - the list is\n";
    echo "rendered from the transient this script has just refreshed.\n";
} else {
    foreach ($problems as $i => $p) {
        printf("%d. %s\n\n", $i + 1, wordwrap($p, 72, "\n   "));
    }
}
echo "=========================================================================\n";
