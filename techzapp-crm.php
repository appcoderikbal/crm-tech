<?php
/**
 * Plugin Name:       Techzapp CRM
 * Plugin URI:        https://github.com/appcoderikbal/crm-tech
 * Description:       Email marketing CRM for WordPress — contacts, lists, campaigns, scheduled sending through Amazon SES, delivery and engagement tracking, suppression management and deliverability monitoring.
 * Version:           1.3.4
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Techzapp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       techzapp-crm
 * Domain Path:       /languages
 *
 * Update URI:        https://github.com/appcoderikbal/crm-tech
 *
 * That Update URI header is what makes self-hosted updates work: WordPress
 * 5.8+ routes update checks for this plugin to the update_plugins_github.com
 * filter instead of wordpress.org, and Core\Updater answers it from the
 * repository's GitHub Releases.
 *
 * @package TechzappCrm
 */

declare(strict_types=1);

namespace TechzappCrm;

// No direct access.
if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '1.3.4';
const DB_VERSION = '1.0.0';
const MINIMUM_PHP = '8.2';

define('TECHZAPP_CRM_FILE', __FILE__);
define('TECHZAPP_CRM_DIR', plugin_dir_path(__FILE__));
define('TECHZAPP_CRM_URL', plugin_dir_url(__FILE__));
define('TECHZAPP_CRM_BASENAME', plugin_basename(__FILE__));

/**
 * The plugin needs PHP 8.2 (typed properties, enums in match, readonly).
 * Fail loudly in the admin rather than fataling on activation.
 */
if (version_compare(PHP_VERSION, MINIMUM_PHP, '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __('Techzapp CRM requires PHP %1$s or newer. This server runs PHP %2$s, so the plugin is inactive.', 'techzapp-crm'),
                MINIMUM_PHP,
                PHP_VERSION
            ))
        );
    });
    return;
}

/**
 * PSR-4 style autoloader for the plugin's own classes.
 *
 * TechzappCrm\Services\CampaignSender -> includes/Services/CampaignSender.php
 */
spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = TECHZAPP_CRM_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

/**
 * Composer dependencies (AWS SDK, HTMLPurifier, the SNS validator).
 *
 * They are bundled with the plugin because shared hosting rarely allows
 * running Composer. Their absence is a configuration problem, not a crash:
 * the admin is told, and email sending is disabled until it is fixed.
 */
if (is_file(TECHZAPP_CRM_DIR . 'vendor/autoload.php')) {
    require_once TECHZAPP_CRM_DIR . 'vendor/autoload.php';
} else {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Techzapp CRM:', 'techzapp-crm'),
            esc_html__('bundled libraries are missing. Re-install the plugin from the released ZIP, or run "composer install" inside the plugin directory.', 'techzapp-crm')
        );
    });
}

// ---------------------------------------------------------------- lifecycle

register_activation_hook(__FILE__, [Core\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [Core\Installer::class, 'deactivate']);

/**
 * Boots the plugin once WordPress has loaded enough for options and users.
 */
function boot(): void
{
    Core\Installer::maybeUpgrade();
    Core\Cron::register();
    Core\Updater::register();
    Rest\Routes::register();

    if (is_admin()) {
        Admin\Menu::register();
        Admin\Notices::register();
        Admin\Assets::register();
        Admin\Ajax::register();
        add_action('admin_notices', [Core\Updater::class, 'renderCheckNotice']);
    }

    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('techzapp-crm', Cli\Commands::class);
    }
}
add_action('plugins_loaded', __NAMESPACE__ . '\\boot');

/**
 * Settings link on the Plugins screen.
 */
add_filter('plugin_action_links_' . TECHZAPP_CRM_BASENAME, static function (array $links): array {
    $settings = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=techzapp-crm-settings')),
        esc_html__('Settings', 'techzapp-crm')
    );
    array_unshift($links, $settings);
    return $links;
});
