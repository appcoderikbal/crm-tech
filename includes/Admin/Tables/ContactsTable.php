<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Tables;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Services\ContactService;
use WP_List_Table;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(WP_List_Table::class)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Contacts table.
 *
 * Defaults to unassigned contacts, which is the product rule: assigning a
 * contact to a list takes it off this screen. The filter is applied in SQL,
 * not here, so it cannot be bypassed by crafting a request.
 */
final class ContactsTable extends WP_List_Table
{
    private int $total = 0;

    /** @var array<string,int> */
    public array $counts = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'contact',
            'plural'   => 'contacts',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        $columns = [
            'cb'           => '<input type="checkbox">',
            'email'        => __('Email', 'techzapp-crm'),
            'name'         => __('Name', 'techzapp-crm'),
            'company_name' => __('Company', 'techzapp-crm'),
            'country'      => __('Country', 'techzapp-crm'),
            'list'         => __('List', 'techzapp-crm'),
            'status'       => __('Status', 'techzapp-crm'),
            'created_at'   => __('Added', 'techzapp-crm'),
        ];

        if (!Capabilities::canManage()) {
            unset($columns['cb']);
        }

        return $columns;
    }

    protected function get_sortable_columns(): array
    {
        return [
            'email'        => ['email', false],
            'name'         => ['last_name', false],
            'company_name' => ['company_name', false],
            'country'      => ['country', false],
            'status'       => ['status', false],
            'created_at'   => ['created_at', true],
        ];
    }

    protected function get_bulk_actions(): array
    {
        if (!Capabilities::canManage()) {
            return [];
        }

        return [
            'assign' => __('Assign to list', 'techzapp-crm'),
            'delete' => __('Delete', 'techzapp-crm'),
        ];
    }

    public function prepare_items(): void
    {
        $perPage = $this->get_items_per_page('techzapp_crm_contacts_per_page', 25);
        $paged = max(1, (int) $this->get_pagenum());

        $filters = [
            'assignment'   => $this->request('assignment', 'unassigned'),
            'search'       => $this->request('s'),
            'status'       => $this->request('status'),
            'country'      => $this->request('country'),
            'suppressed'   => $this->request('suppressed'),
            'list_id'      => $this->request('list_id'),
            'unsubscribed' => $this->request('unsubscribed'),
            'sort'         => $this->request('orderby', 'created_at'),
            'direction'    => $this->request('order', 'desc') === 'asc' ? 'asc' : 'desc',
            'page'         => $paged,
            'per_page'     => $perPage,
        ];

        $result = ContactService::paginate($filters);

        $this->items = $result['items'];
        $this->total = $result['total'];
        $this->counts = ContactService::counts();

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => $perPage,
            'total_pages' => $result['pages'],
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'email'];
    }

    private function request(string $key, string $default = ''): string
    {
        // Read-only filters: nonce-free by design, like core's own list filters.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_REQUEST[$key]) ? sanitize_text_field(wp_unslash((string) $_REQUEST[$key])) : $default;
    }

    protected function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="contact_ids[]" value="%d">', (int) $item['id']);
    }

    protected function column_email($item): string
    {
        $url = Menu::url('techzapp-crm-contact', ['contact' => (int) $item['id']]);

        $actions = [
            'edit' => sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Edit', 'techzapp-crm')),
        ];

        if (Capabilities::canManage()) {
            $actions['delete'] = sprintf(
                '<a href="%s" class="submitdelete techzapp-confirm">%s</a>',
                esc_url(View::actionUrl(
                    'techzapp-crm-contacts',
                    'delete_contact',
                    'techzapp_crm_contact',
                    ['contact' => (int) $item['id']]
                )),
                esc_html__('Delete', 'techzapp-crm')
            );
        }

        return sprintf(
            '<strong><a href="%s">%s</a></strong>%s',
            esc_url($url),
            esc_html((string) $item['email']),
            $this->row_actions($actions)
        );
    }

    protected function column_name($item): string
    {
        $name = trim((string) $item['first_name'] . ' ' . (string) $item['last_name']);
        return $name !== '' ? esc_html($name) : '—';
    }

    protected function column_list($item): string
    {
        if (empty($item['list_id'])) {
            return '<span class="description">' . esc_html__('Unassigned', 'techzapp-crm') . '</span>';
        }

        return sprintf(
            '<a href="%s">%s</a>',
            esc_url(Menu::url('techzapp-crm-list', ['list' => (int) $item['list_id']])),
            esc_html((string) $item['list_name'])
        );
    }

    protected function column_status($item): string
    {
        if (!empty($item['is_suppressed'])) {
            return View::badge(str_replace('_', ' ', (string) $item['suppression_reason']), 'danger');
        }
        if (!empty($item['is_unsubscribed'])) {
            return View::badge(__('unsubscribed', 'techzapp-crm'), 'warn');
        }

        return View::badge((string) $item['status'], $item['status'] === 'active' ? 'ok' : 'neutral');
    }

    protected function column_created_at($item): string
    {
        return esc_html(View::dateTime((string) $item['created_at']));
    }

    protected function column_default($item, $column_name): string
    {
        $value = $item[$column_name] ?? '';
        return $value === '' ? '—' : esc_html((string) $value);
    }

    public function no_items(): void
    {
        $assignment = $this->request('assignment', 'unassigned');

        if ($assignment === 'unassigned') {
            esc_html_e('No unassigned contacts. Every contact is on a list, or none have been imported yet.', 'techzapp-crm');
            return;
        }

        esc_html_e('No contacts match these filters.', 'techzapp-crm');
    }

    /** The assignment filter and the per-view counts. */
    protected function get_views(): array
    {
        $current = $this->request('assignment', 'unassigned');
        $unsubscribed = $this->request('unsubscribed') === 'yes';

        $views = [
            'unassigned' => [__('Unassigned', 'techzapp-crm'), $this->counts['unassigned'] ?? 0],
            'assigned'   => [__('On a list', 'techzapp-crm'), $this->counts['assigned'] ?? 0],
            'any'        => [__('All', 'techzapp-crm'), $this->counts['total'] ?? 0],
        ];

        $links = [];
        foreach ($views as $key => [$label, $count]) {
            $links[$key] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url(Menu::url('techzapp-crm-contacts', ['assignment' => $key])),
                ($key === $current && !$unsubscribed) ? ' class="current"' : '',
                esc_html($label),
                esc_html(View::number($count))
            );
        }

        // Opting out removes the contact from every list, so they would
        // otherwise only be findable under "Unassigned" among everyone else
        // who simply has not been assigned yet. Who opted out is a question
        // worth one click.
        $links['unsubscribed'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
            esc_url(Menu::url('techzapp-crm-contacts', [
                'assignment'   => 'any',
                'unsubscribed' => 'yes',
            ])),
            $unsubscribed ? ' class="current"' : '',
            esc_html__('Unsubscribed', 'techzapp-crm'),
            esc_html(View::number($this->counts['unsubscribed'] ?? 0))
        );

        return $links;
    }

    /** Status, country and suppression filters. */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        $status = $this->request('status');
        $country = $this->request('country');
        $suppressed = $this->request('suppressed');

        echo '<div class="alignleft actions">';

        echo '<select name="status">';
        printf('<option value="">%s</option>', esc_html__('Any status', 'techzapp-crm'));
        foreach (['active', 'inactive', 'archived'] as $value) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($status, $value, false),
                esc_html(ucfirst($value))
            );
        }
        echo '</select>';

        $countries = ContactService::distinctValues('country');
        if ($countries !== []) {
            echo '<select name="country">';
            printf('<option value="">%s</option>', esc_html__('Any country', 'techzapp-crm'));
            foreach ($countries as $value) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($value),
                    selected($country, $value, false),
                    esc_html($value)
                );
            }
            echo '</select>';
        }

        echo '<select name="suppressed">';
        printf('<option value="">%s</option>', esc_html__('Any suppression', 'techzapp-crm'));
        printf('<option value="no"%s>%s</option>', selected($suppressed, 'no', false), esc_html__('Not suppressed', 'techzapp-crm'));
        printf('<option value="yes"%s>%s</option>', selected($suppressed, 'yes', false), esc_html__('Suppressed', 'techzapp-crm'));
        echo '</select>';

        submit_button(__('Filter', 'techzapp-crm'), '', 'filter_action', false);

        echo '</div>';
    }
}
