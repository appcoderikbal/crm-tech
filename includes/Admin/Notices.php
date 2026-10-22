<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\Config;
use TechzappCrm\Core\Cron;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Installer;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\AlertService;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin notices.
 *
 * Two kinds: messages queued by an action for the next page load, and standing
 * warnings about configuration that would make the plugin fail quietly —
 * a missing schema, no verified sender, sending stopped, cron not running.
 */
final class Notices
{
    private const TRANSIENT = 'techzapp_crm_notices_';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'renderGlobal']);
    }

    /** Stores a message for the current user, shown on the next page load. */
    public static function queue(string $message, string $type = 'success'): void
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }

        $queued = get_transient(self::TRANSIENT . $userId);
        $queued = is_array($queued) ? $queued : [];
        $queued[] = ['message' => $message, 'type' => $type];

        set_transient(self::TRANSIENT . $userId, $queued, 60);
    }

    /** Renders and clears the queue. Called at the top of every plugin screen. */
    public static function renderQueued(): void
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }

        $queued = get_transient(self::TRANSIENT . $userId);
        if (!is_array($queued) || $queued === []) {
            return;
        }

        delete_transient(self::TRANSIENT . $userId);

        foreach ($queued as $notice) {
            View::notice((string) $notice['message'], (string) $notice['type'], true);
        }
    }

    /**
     * Standing warnings, shown on the plugin's own screens only.
     *
     * Deliberately not shown site-wide: a CRM problem is not something every
     * admin on every screen needs shouted at them.
     */
    public static function renderGlobal(): void
    {
        if (!Capabilities::canView()) {
            return;
        }

        $screen = get_current_screen();
        if ($screen === null || !str_contains((string) $screen->id, 'techzapp-crm')) {
            return;
        }

        try {
            self::schemaNotice();
            self::sendingNotice();
            self::configurationNotice();
            self::cronNotice();
            self::criticalAlertNotice();
        } catch (Throwable) {
            // A broken notice must never block the screen behind it.
        }
    }

    private static function schemaNotice(): void
    {
        $missing = Installer::missingTables();
        if ($missing === []) {
            return;
        }

        View::notice(
            sprintf(
                /* translators: %s: comma-separated list of table names */
                __('Some Techzapp CRM database tables are missing (%s). Deactivate and reactivate the plugin to create them.', 'techzapp-crm'),
                '<code>' . esc_html(implode(', ', $missing)) . '</code>'
            ),
            'error'
        );
    }

    private static function sendingNotice(): void
    {
        if (Settings::sendingAllowed()) {
            return;
        }

        $reason = Settings::string('kill_switch_reason');

        View::notice(
            sprintf(
                '<strong>%s</strong> %s',
                esc_html__('Sending is stopped.', 'techzapp-crm'),
                $reason !== ''
                    ? esc_html($reason)
                    : esc_html__('No new messages will be sent until this is released in Settings.', 'techzapp-crm')
            ),
            'warning'
        );
    }

    private static function configurationNotice(): void
    {
        $problems = [];

        if (Config::mailTransport() === 'log') {
            $problems[] = __('the mail transport is set to "log", so nothing is actually delivered', 'techzapp-crm');
        } elseif (Config::credentialSource() === 'none') {
            $problems[] = __('no Amazon SES credentials are configured', 'techzapp-crm');
        }

        if (Settings::array('ses_verified_identities') === []) {
            $problems[] = __('no verified sender identity is configured, so every campaign will refuse to send', 'techzapp-crm');
        }

        if ($problems === []) {
            return;
        }

        View::notice(
            sprintf(
                /* translators: 1: list of configuration problems, 2: settings link */
                __('Techzapp CRM is not ready to send: %1$s. %2$s', 'techzapp-crm'),
                esc_html(implode('; ', $problems)),
                '<a href="' . esc_url(Menu::url('techzapp-crm-settings')) . '">' . esc_html__('Open settings', 'techzapp-crm') . '</a>'
            ),
            'warning'
        );
    }

    private static function cronNotice(): void
    {
        $staleMinutes = max(5, Settings::int('cron_stale_minutes', 30));

        $rows = Database::all('SELECT job_name, last_run_at FROM {cron_heartbeats}');
        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) $row['job_name']] = strtotime((string) $row['last_run_at'] . ' UTC');
        }

        $stale = [];
        foreach (Cron::CRITICAL_JOBS as $job) {
            if (!isset($seen[$job])) {
                $stale[] = $job;
                continue;
            }
            if ((time() - $seen[$job]) > $staleMinutes * MINUTE_IN_SECONDS) {
                $stale[] = $job;
            }
        }

        if ($stale === []) {
            return;
        }

        $message = sprintf(
            /* translators: %s: comma-separated job names */
            __('Background jobs are not running (%s). Scheduled campaigns will not send and delivery events will not be processed.', 'techzapp-crm'),
            '<code>' . esc_html(implode(', ', $stale)) . '</code>'
        );

        if (Cron::wpCronDisabled()) {
            $message .= ' ' . __('WP-Cron is disabled on this site, so a real system cron must be calling WP-CLI.', 'techzapp-crm');
        }

        $message .= ' <a href="' . esc_url(Menu::url('techzapp-crm-settings')) . '">'
            . esc_html__('Check background workers', 'techzapp-crm') . '</a>';

        View::notice($message, 'error');
    }

    private static function criticalAlertNotice(): void
    {
        $alerts = array_filter(
            AlertService::active(5),
            static fn (array $alert): bool => $alert['severity'] === 'critical'
        );

        if ($alerts === []) {
            return;
        }

        $items = '';
        foreach ($alerts as $alert) {
            $items .= '<li><strong>' . esc_html((string) $alert['title']) . '</strong> — '
                . esc_html((string) $alert['message']) . '</li>';
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong></p><ul class="techzapp-notice-list">%s</ul><p><a href="%s">%s</a></p></div>',
            esc_html(sprintf(
                /* translators: %d: number of alerts */
                _n('%d critical deliverability alert', '%d critical deliverability alerts', count($alerts), 'techzapp-crm'),
                count($alerts)
            )),
            wp_kses($items, ['li' => [], 'strong' => []]),
            esc_url(Menu::url('techzapp-crm-deliverability')),
            esc_html__('Review deliverability', 'techzapp-crm')
        );
    }
}
