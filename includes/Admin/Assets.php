<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

use const TechzappCrm\VERSION;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin CSS and JS.
 *
 * Loaded only on the plugin's own screens. There is no framework and no build
 * step: a stylesheet that extends core admin styles, and a small script for
 * the import progress poll and the confirm prompts.
 */
final class Assets
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, 'techzapp-crm')) {
            return;
        }

        wp_enqueue_style(
            'techzapp-crm-admin',
            TECHZAPP_CRM_URL . 'assets/admin.css',
            [],
            VERSION
        );

        wp_enqueue_script(
            'techzapp-crm-admin',
            TECHZAPP_CRM_URL . 'assets/admin.js',
            ['jquery'],
            VERSION,
            true
        );

        wp_localize_script('techzapp-crm-admin', 'techzappCrm', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('techzapp_crm_ajax'),
            'i18n'    => [
                'confirmDelete'   => __('This cannot be undone. Continue?', 'techzapp-crm'),
                'confirmSend'     => __('This will start sending to every eligible recipient. Continue?', 'techzapp-crm'),
                'importFailed'    => __('The import stopped unexpectedly. Reload to see where it got to.', 'techzapp-crm'),
                'importFinished'  => __('Import finished.', 'techzapp-crm'),
            ],
        ]);
    }
}
