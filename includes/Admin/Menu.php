<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\Cleanup;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Logger;
use TechzappCrm\Services\AlertService;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin menu and screen dispatch.
 *
 * Each screen is a plain WordPress page using core markup — wrap, nav-tab,
 * WP_List_Table, the Settings API — so it looks and behaves like the rest of
 * wp-admin rather than like an app embedded in it.
 */
final class Menu
{
    public const SLUG = 'techzapp-crm';

    /** @var array<string,class-string<Screens\Screen>> */
    private const SCREENS = [
        'techzapp-crm'                => Screens\Dashboard::class,
        'techzapp-crm-contacts'       => Screens\Contacts::class,
        'techzapp-crm-contact'        => Screens\ContactEdit::class,
        'techzapp-crm-import'         => Screens\Import::class,
        'techzapp-crm-lists'          => Screens\Lists::class,
        'techzapp-crm-list'           => Screens\ListMembers::class,
        'techzapp-crm-campaigns'      => Screens\Campaigns::class,
        'techzapp-crm-campaign'       => Screens\CampaignEdit::class,
        'techzapp-crm-report'         => Screens\CampaignReport::class,
        'techzapp-crm-deliverability' => Screens\Deliverability::class,
        'techzapp-crm-suppressions'   => Screens\Suppressions::class,
        'techzapp-crm-settings'       => Screens\SettingsScreen::class,
        'techzapp-crm-audit'          => Screens\AuditLog::class,
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'handlePostActions']);
        Cleanup::register();
    }

    public static function addMenu(): void
    {
        if (!Capabilities::canView()) {
            return;
        }

        $capability = Capabilities::VIEW;
        $critical = AlertService::criticalCount();

        // A red bubble on the top-level item, the same affordance core uses
        // for pending updates and comments.
        $title = __('Techzapp CRM', 'techzapp-crm');
        if ($critical > 0) {
            $title .= sprintf(
                ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
                $critical
            );
        }

        add_menu_page(
            __('Techzapp CRM', 'techzapp-crm'),
            $title,
            $capability,
            self::SLUG,
            [self::class, 'render'],
            'dashicons-email-alt',
            26
        );

        $submenus = [
            ['techzapp-crm', __('Dashboard', 'techzapp-crm')],
            ['techzapp-crm-contacts', __('Contacts', 'techzapp-crm')],
            ['techzapp-crm-lists', __('Lists', 'techzapp-crm')],
            ['techzapp-crm-campaigns', __('Campaigns', 'techzapp-crm')],
            ['techzapp-crm-deliverability', __('Reports', 'techzapp-crm')],
            ['techzapp-crm-suppressions', __('Suppressions', 'techzapp-crm')],
            ['techzapp-crm-settings', __('Settings', 'techzapp-crm')],
        ];

        foreach ($submenus as [$slug, $label]) {
            // Only advertise a screen that exists. A menu item leading to a
            // fatal error is worse than no menu item.
            if (!self::screenExists($slug)) {
                continue;
            }

            add_submenu_page(
                self::SLUG,
                $label,
                $label,
                $capability,
                $slug,
                [self::class, 'render']
            );
        }

        // Reachable by URL but not shown as menu items.
        foreach (['techzapp-crm-contact', 'techzapp-crm-import', 'techzapp-crm-list',
                  'techzapp-crm-campaign', 'techzapp-crm-report', 'techzapp-crm-audit'] as $slug) {
            if (!self::screenExists($slug)) {
                continue;
            }

            add_submenu_page(
                '',
                '',
                '',
                $capability,
                $slug,
                [self::class, 'render']
            );
        }
    }

    /** True when the class backing a page slug is present in this build. */
    public static function screenExists(string $slug): bool
    {
        $class = self::SCREENS[$slug] ?? null;
        return $class !== null && class_exists($class);
    }

    /** Dispatches to the screen named by ?page=. */
    public static function render(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : self::SLUG;

        Capabilities::requireView();

        if (!self::screenExists($page)) {
            View::open(__('Techzapp CRM', 'techzapp-crm'));
            View::notice(
                __('That screen is not available in this build of the plugin.', 'techzapp-crm'),
                'error'
            );
            View::close();
            return;
        }

        $class = self::SCREENS[$page];

        try {
            (new $class())->render();
        } catch (CrmException $e) {
            View::open(__('Techzapp CRM', 'techzapp-crm'));
            View::notice($e->getMessage(), 'error');
            View::close();
        } catch (Throwable $e) {
            Logger::exception('admin.screen_failed', $e, ['page' => $page]);

            View::open(__('Techzapp CRM', 'techzapp-crm'));
            View::notice(
                __('Something went wrong rendering this screen. The details were written to the plugin log.', 'techzapp-crm'),
                'error'
            );
            if (\TechzappCrm\Core\Config::isDebug()) {
                echo '<pre class="techzapp-debug">' . esc_html($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
            }
            View::close();
        }
    }

    /**
     * Runs form submissions before any output, so a screen can redirect.
     *
     * Every handler re-checks both the capability and the nonce; being on an
     * admin page is never treated as authorisation on its own.
     */
    public static function handlePostActions(): void
    {
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash((string) $_REQUEST['page'])) : '';
        if (!str_starts_with($page, self::SLUG)) {
            return;
        }

        $action = isset($_REQUEST['crm_action']) ? sanitize_key(wp_unslash((string) $_REQUEST['crm_action'])) : '';
        if ($action === '') {
            return;
        }

        $class = self::SCREENS[$page] ?? null;
        if ($class === null || !method_exists($class, 'handle')) {
            return;
        }

        try {
            (new $class())->handle($action);
        } catch (CrmException $e) {
            Notices::queue($e->getMessage(), 'error');
        } catch (Throwable $e) {
            Logger::exception('admin.action_failed', $e, ['page' => $page, 'action' => $action]);
            Notices::queue(
                __('That action could not be completed. The details were written to the plugin log.', 'techzapp-crm'),
                'error'
            );
        }
    }

    /** Admin URL for a plugin screen. */
    public static function url(string $page, array $args = []): string
    {
        return add_query_arg(['page' => $page] + $args, admin_url('admin.php'));
    }
}
