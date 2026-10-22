<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authorization, expressed as WordPress capabilities.
 *
 * The standalone app had its own users table and admin/operator/viewer roles.
 * In WordPress the user is whoever is logged in, and these three capabilities
 * replace the roles — so an existing Editor can be given CRM access without a
 * second account, and any membership plugin can grant them.
 *
 *   techzapp_crm_view      read contacts, campaigns and reports
 *   techzapp_crm_manage    create and edit, import, send, suppress
 *   techzapp_crm_settings  change settings, release suppressions, view the audit log
 */
final class Capabilities
{
    public const VIEW = 'techzapp_crm_view';
    public const MANAGE = 'techzapp_crm_manage';
    public const SETTINGS = 'techzapp_crm_settings';

    public const ALL = [self::VIEW, self::MANAGE, self::SETTINGS];

    /** Grants every capability to Administrator. Called on activation. */
    public static function install(): void
    {
        $administrator = get_role('administrator');
        if ($administrator === null) {
            return;
        }

        foreach (self::ALL as $capability) {
            $administrator->add_cap($capability);
        }
    }

    /** Removes the capabilities from every role. Called on uninstall. */
    public static function remove(): void
    {
        foreach (wp_roles()->role_objects as $role) {
            foreach (self::ALL as $capability) {
                $role->remove_cap($capability);
            }
        }
    }

    public static function canView(): bool
    {
        return current_user_can(self::VIEW) || current_user_can(self::MANAGE) || current_user_can(self::SETTINGS);
    }

    public static function canManage(): bool
    {
        return current_user_can(self::MANAGE) || current_user_can(self::SETTINGS);
    }

    public static function canManageSettings(): bool
    {
        return current_user_can(self::SETTINGS);
    }

    /** Stops the request unless the user may read CRM data. */
    public static function requireView(): void
    {
        if (!self::canView()) {
            wp_die(
                esc_html__('You do not have permission to view the CRM.', 'techzapp-crm'),
                esc_html__('Permission denied', 'techzapp-crm'),
                ['response' => 403]
            );
        }
    }

    /** Stops the request unless the user may change CRM data. */
    public static function requireManage(): void
    {
        if (!self::canManage()) {
            wp_die(
                esc_html__('You do not have permission to change CRM data.', 'techzapp-crm'),
                esc_html__('Permission denied', 'techzapp-crm'),
                ['response' => 403]
            );
        }
    }

    /** Stops the request unless the user may change settings. */
    public static function requireSettings(): void
    {
        if (!self::canManageSettings()) {
            wp_die(
                esc_html__('Only administrators can change these settings.', 'techzapp-crm'),
                esc_html__('Permission denied', 'techzapp-crm'),
                ['response' => 403]
            );
        }
    }

    /**
     * Verifies a nonce for a state-changing admin request.
     *
     * Every form and every row action in the plugin passes through here, which
     * is what replaces the standalone app's CSRF token.
     */
    public static function checkNonce(string $action, string $field = '_wpnonce'): void
    {
        if (!isset($_REQUEST[$field]) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_REQUEST[$field])), $action)) {
            wp_die(
                esc_html__('That link or form has expired. Go back, reload the page and try again.', 'techzapp-crm'),
                esc_html__('Security check failed', 'techzapp-crm'),
                ['response' => 403]
            );
        }
    }

    /** The current user id, or null for cron and CLI runs. */
    public static function currentUserId(): ?int
    {
        $id = get_current_user_id();
        return $id > 0 ? $id : null;
    }
}
