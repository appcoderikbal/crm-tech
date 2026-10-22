<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\View;
use TechzappCrm\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Who did what, and when.
 *
 * Read-only by design: an audit trail that can be edited from the interface it
 * audits is not an audit trail.
 */
final class AuditLog extends Screen
{
    private const PER_PAGE = 50;

    public function render(): void
    {
        $search = $this->input('s');
        $action = $this->input('action_filter');
        $page = max(1, $this->intInput('paged', 1));

        $clauses = ['1 = 1'];
        $params = [];

        if (trim($search) !== '') {
            $pattern = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($search)) . '%';
            // One placeholder per column: a named parameter cannot be reused.
            $clauses[] = '(actor_label LIKE :search_actor OR entity_id LIKE :search_entity OR details_json LIKE :search_details)';
            $params['search_actor'] = $pattern;
            $params['search_entity'] = $pattern;
            $params['search_details'] = $pattern;
        }

        if ($action !== '') {
            $clauses[] = 'action = :action';
            $params['action'] = $action;
        }

        $where = implode(' AND ', $clauses);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM {audit_logs} WHERE {$where}", $params);
        $pages = (int) ceil($total / self::PER_PAGE);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = Database::all(
            "SELECT id, user_id, actor_label, action, entity_type, entity_id,
                    ip_address, details_json, created_at
               FROM {audit_logs}
              WHERE {$where}
           ORDER BY created_at DESC, id DESC
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        );

        View::open(__('Audit log', 'techzapp-crm'));

        $this->renderFilters($search, $action, $total);

        if ($rows === []) {
            View::cardOpen(__('Nothing recorded', 'techzapp-crm'));
            echo '<p>' . esc_html__('No audit entries match that filter.', 'techzapp-crm') . '</p>';
            View::cardClose();
            View::close();
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th style="width:160px">' . esc_html__('When', 'techzapp-crm') . '</th>';
        echo '<th style="width:160px">' . esc_html__('Who', 'techzapp-crm') . '</th>';
        echo '<th style="width:180px">' . esc_html__('Action', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Target', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Details', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $target = (string) $row['entity_type'];
            if ((string) $row['entity_id'] !== '') {
                $target .= ' #' . (string) $row['entity_id'];
            }

            printf(
                '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td class="description">%s</td></tr>',
                esc_html(View::dateTime((string) $row['created_at'])),
                esc_html($this->actor($row)),
                esc_html((string) $row['action']),
                esc_html($target !== '' ? $target : '—'),
                esc_html($this->details($row['details_json'] ?? null))
            );
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            $links = paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $pages,
                'current'   => $page,
            ]);

            if (is_string($links) && $links !== '') {
                echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
            }
        }

        echo '<p class="description">'
            . esc_html__('Entries are written by the plugin and cannot be edited or deleted from here.', 'techzapp-crm')
            . '</p>';

        View::close();
    }

    private function renderFilters(string $search, string $action, int $total): void
    {
        $actions = Database::all(
            'SELECT DISTINCT action FROM {audit_logs} ORDER BY action LIMIT 100'
        );

        echo '<form method="get" class="techzapp-search-form">';
        echo '<input type="hidden" name="page" value="techzapp-crm-audit">';
        echo '<div class="techzapp-filters">';

        echo '<select name="action_filter"><option value="">' . esc_html__('All actions', 'techzapp-crm') . '</option>';
        foreach ($actions as $row) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $row['action']),
                selected($action, (string) $row['action'], false),
                esc_html((string) $row['action'])
            );
        }
        echo '</select>';

        printf(
            '<input type="search" name="s" value="%s" placeholder="%s">',
            esc_attr($search),
            esc_attr__('Search actor or target', 'techzapp-crm')
        );

        submit_button(__('Filter', 'techzapp-crm'), '', '', false);
        echo '</div></form>';

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %s: number of entries */
                _n('%s entry.', '%s entries.', $total, 'techzapp-crm'),
                View::number($total)
            ))
        );
    }

    /** @param array<string,mixed> $row */
    private function actor(array $row): string
    {
        $label = trim((string) $row['actor_label']);
        if ($label !== '') {
            return $label;
        }

        // No label and no user means the entry came from cron or a webhook,
        // which is worth distinguishing from an unnamed person.
        return $row['user_id'] === null
            ? __('system', 'techzapp-crm')
            : sprintf(
                /* translators: %d: WordPress user ID */
                __('user #%d', 'techzapp-crm'),
                (int) $row['user_id']
            );
    }

    private function details(mixed $json): string
    {
        if (!is_string($json) || $json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $value = wp_json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $parts[] = $key . ': ' . (string) $value;
        }

        $summary = implode(', ', $parts);

        return strlen($summary) > 200 ? substr($summary, 0, 197) . '…' : $summary;
    }
}
