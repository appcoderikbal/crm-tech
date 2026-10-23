<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Services\CampaignService;
use TechzappCrm\Services\ListService;
use TechzappCrm\Services\Mail\Mailer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign index: everything drafted, scheduled or sent.
 *
 * Lifecycle buttons only appear for transitions the state machine actually
 * permits, so the screen cannot offer an action the service will refuse.
 */
final class Campaigns extends Screen
{
    private const TONES = [
        'draft'     => 'neutral',
        'scheduled' => 'brand',
        'queued'    => 'brand',
        'sending'   => 'brand',
        'paused'    => 'warn',
        'completed' => 'ok',
        'failed'    => 'danger',
        'cancelled' => 'neutral',
    ];

    public function render(): void
    {
        $status = $this->input('status');
        $listId = $this->intInput('list_id');
        $search = $this->input('s');

        $result = CampaignService::paginate([
            'page'    => max(1, $this->intInput('paged', 1)),
            'status'  => $status !== '' ? $status : null,
            'list_id' => $listId > 0 ? $listId : null,
            'search'  => $search,
        ]);

        View::open(
            __('Campaigns', 'techzapp-crm'),
            Capabilities::canManage() ? Menu::url('techzapp-crm-campaign') : null,
            __('New campaign', 'techzapp-crm')
        );

        $this->renderFilters($status, $listId, $search, $result['total']);

        if ($result['items'] === []) {
            $this->renderEmptyState($status, $listId, $search);
            View::close();
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Campaign', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Status', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('List', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Sent', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Opens', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Clicks', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('When', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($result['items'] as $row) {
            $this->renderRow($row);
        }

        echo '</tbody></table>';

        echo '<p class="description">'
            . esc_html__('Open and click rates are measured against messages the provider accepted, not against the size of the list.', 'techzapp-crm')
            . '</p>';

        if (Mailer::transport()->name() === 'log') {
            View::notice(
                __('The mail transport is set to "log": these campaigns were written to the plugin log, not sent to anyone.', 'techzapp-crm'),
                'warning'
            );
        }

        $this->renderPagination($result);
        View::close();
    }

    private function renderEmptyState(string $status, int $listId, string $search): void
    {
        $filtered = $status !== '' || $listId > 0 || $search !== '';

        View::cardOpen(
            $filtered
                ? __('No campaigns match that filter', 'techzapp-crm')
                : __('No campaigns yet', 'techzapp-crm')
        );

        if ($filtered) {
            printf(
                '<p><a href="%s">%s</a></p>',
                esc_url(Menu::url('techzapp-crm-campaigns')),
                esc_html__('Clear the filters', 'techzapp-crm')
            );
        } else {
            echo '<p>' . esc_html__('A campaign sends one email to every eligible contact on a list. Suppressed and unsubscribed contacts are skipped automatically.', 'techzapp-crm') . '</p>';

            if (Capabilities::canManage()) {
                printf(
                    '<p><a href="%s" class="button button-primary">%s</a></p>',
                    esc_url(Menu::url('techzapp-crm-campaign')),
                    esc_html__('Create your first campaign', 'techzapp-crm')
                );
            }
        }

        View::cardClose();
    }

    /** @param array<string,mixed> $row */
    private function renderRow(array $row): void
    {
        $id = (int) $row['id'];
        $status = (string) $row['status'];
        $hasReport = !in_array($status, ['draft', 'scheduled'], true);

        $primaryUrl = $hasReport
            ? Menu::url('techzapp-crm-report', ['campaign' => $id])
            : Menu::url('techzapp-crm-campaign', ['campaign' => $id]);

        $actions = [];
        if ($hasReport) {
            $actions[] = $this->link(Menu::url('techzapp-crm-report', ['campaign' => $id]), __('Report', 'techzapp-crm'));
        }
        if (in_array($status, ['draft', 'scheduled', 'failed'], true)) {
            $actions[] = $this->link(Menu::url('techzapp-crm-campaign', ['campaign' => $id]), __('Edit', 'techzapp-crm'));
        }

        if (Capabilities::canManage()) {
            foreach ($this->lifecycleActions($id, $status) as $lifecycle) {
                $actions[] = $lifecycle;
            }

            $actions[] = $this->postLink($id, 'duplicate', __('Duplicate', 'techzapp-crm'));

            // Mirrors CampaignService::delete exactly. Offering Delete on a
            // scheduled campaign would present a button the service refuses;
            // omitting it on a cancelled one would hide a legitimate action.
            if (in_array($status, ['draft', 'cancelled', 'failed'], true)) {
                $actions[] = $this->postLink(
                    $id,
                    'delete',
                    __('Delete', 'techzapp-crm'),
                    $status === 'draft'
                        ? __('Delete this draft campaign permanently?', 'techzapp-crm')
                        : __('Delete this campaign permanently? Its report and per-recipient history are removed too.', 'techzapp-crm'),
                    'submitdelete'
                );
            }
        }

        $accepted = (int) ($row['accepted'] ?? 0);
        $pending = (int) ($row['pending'] ?? 0);

        $statusCell = View::badge($this->statusLabel($status), self::TONES[$status] ?? 'neutral');
        if ($pending > 0) {
            $statusCell .= '<br><span class="description">' . esc_html(sprintf(
                /* translators: %s: number of recipients still queued */
                __('%s remaining', 'techzapp-crm'),
                View::number($pending)
            )) . '</span>';
        }
        if ($status === 'failed' && !empty($row['last_error'])) {
            $statusCell .= '<br><span class="description tone-danger">' . esc_html((string) $row['last_error']) . '</span>';
        }

        $listCell = $row['list_name'] !== null
            ? sprintf(
                '<a href="%s">%s</a>',
                esc_url(Menu::url('techzapp-crm-list', ['list_id' => (int) $row['list_id'], 'assignment' => 'any'])),
                esc_html((string) $row['list_name'])
            )
            : '<span class="description">' . esc_html__('None', 'techzapp-crm') . '</span>';

        printf(
            '<tr><td><strong><a href="%s">%s</a></strong>%s<div class="row-actions">%s</div></td>'
            . '<td>%s</td><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td>%s</td></tr>',
            esc_url($primaryUrl),
            esc_html((string) $row['name']),
            $row['subject'] !== null && $row['subject'] !== ''
                ? '<br><span class="description">' . esc_html((string) $row['subject']) . '</span>'
                : '',
            implode(' | ', $actions),
            $statusCell,
            $listCell,
            esc_html(View::number($accepted)),
            esc_html($this->rate((int) ($row['unique_opens'] ?? 0), $accepted)),
            esc_html($this->rate((int) ($row['unique_clicks'] ?? 0), $accepted)),
            esc_html($this->whenLabel($row))
        );
    }

    /** @return list<string> */
    private function lifecycleActions(int $id, string $status): array
    {
        $actions = [];

        if (CampaignService::canTransition($status, 'paused')) {
            $actions[] = $this->postLink($id, 'pause', __('Pause', 'techzapp-crm'));
        }
        if ($status === 'paused') {
            $actions[] = $this->postLink($id, 'resume', __('Resume', 'techzapp-crm'));
        }
        if (CampaignService::canTransition($status, 'cancelled')) {
            $actions[] = $this->postLink(
                $id,
                'cancel',
                __('Cancel', 'techzapp-crm'),
                __('Cancel this campaign? Recipients not yet sent to will be skipped. This cannot be undone.', 'techzapp-crm'),
                'submitdelete'
            );
        }

        return $actions;
    }

    private function renderFilters(string $status, int $listId, string $search, int $total): void
    {
        echo '<form method="get" class="techzapp-search-form">';
        echo '<input type="hidden" name="page" value="techzapp-crm-campaigns">';
        echo '<div class="techzapp-filters">';

        echo '<select name="status"><option value="">' . esc_html__('All statuses', 'techzapp-crm') . '</option>';
        foreach (CampaignService::STATUSES as $candidate) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($candidate),
                selected($status, $candidate, false),
                esc_html($this->statusLabel($candidate))
            );
        }
        echo '</select>';

        $lists = ListService::all();
        if ($lists !== []) {
            echo '<select name="list_id"><option value="">' . esc_html__('All lists', 'techzapp-crm') . '</option>';
            foreach ($lists as $list) {
                printf(
                    '<option value="%d"%s>%s</option>',
                    (int) $list['id'],
                    selected($listId, (int) $list['id'], false),
                    esc_html((string) $list['name'])
                );
            }
            echo '</select>';
        }

        printf(
            '<input type="search" name="s" value="%s" placeholder="%s">',
            esc_attr($search),
            esc_attr__('Search name or subject', 'techzapp-crm')
        );

        submit_button(__('Filter', 'techzapp-crm'), '', '', false);

        if ($status !== '' || $listId > 0 || $search !== '') {
            printf(
                ' <a href="%s" class="button-link">%s</a>',
                esc_url(Menu::url('techzapp-crm-campaigns')),
                esc_html__('Clear', 'techzapp-crm')
            );
        }

        echo '</div></form>';

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %s: number of campaigns */
                _n('%s campaign.', '%s campaigns.', $total, 'techzapp-crm'),
                View::number($total)
            ))
        );
    }

    /** @param array<string,mixed> $result */
    private function renderPagination(array $result): void
    {
        if ((int) $result['pages'] <= 1) {
            return;
        }

        $links = paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => (int) $result['pages'],
            'current'   => (int) $result['page'],
        ]);

        if (is_string($links) && $links !== '') {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
        }
    }

    private function link(string $url, string $label): string
    {
        return sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
    }

    private function postLink(
        int $id,
        string $action,
        string $label,
        string $confirm = '',
        string $extraClass = ''
    ): string {
        $classes = array_filter([$extraClass, $confirm !== '' ? 'techzapp-confirm' : '']);

        return sprintf(
            '<a href="%s"%s%s>%s</a>',
            esc_url(View::actionUrl(
                'techzapp-crm-campaigns',
                $action,
                'techzapp_crm_campaign_' . $action,
                ['campaign' => $id]
            )),
            $classes !== [] ? ' class="' . esc_attr(implode(' ', $classes)) . '"' : '',
            $confirm !== '' ? ' data-confirm="' . esc_attr($confirm) . '"' : '',
            esc_html($label)
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'     => __('Draft', 'techzapp-crm'),
            'scheduled' => __('Scheduled', 'techzapp-crm'),
            'queued'    => __('Queued', 'techzapp-crm'),
            'sending'   => __('Sending', 'techzapp-crm'),
            'paused'    => __('Paused', 'techzapp-crm'),
            'completed' => __('Completed', 'techzapp-crm'),
            'failed'    => __('Failed', 'techzapp-crm'),
            'cancelled' => __('Cancelled', 'techzapp-crm'),
            default     => $status,
        };
    }

    /**
     * Opens and clicks are expressed against accepted, not list size: a rate
     * over recipients SES never accepted would overstate engagement.
     */
    private function rate(int $value, int $denominator): string
    {
        if ($denominator <= 0) {
            return '—';
        }

        return View::number($value) . ' (' . View::percent($value / $denominator * 100, 1) . ')';
    }

    /** @param array<string,mixed> $row */
    private function whenLabel(array $row): string
    {
        foreach (['completed_at', 'started_at', 'scheduled_at_utc', 'created_at'] as $column) {
            if (!empty($row[$column])) {
                return View::dateTime((string) $row[$column]);
            }
        }

        return '—';
    }

    public function handle(string $action): void
    {
        if (!in_array($action, ['pause', 'resume', 'cancel', 'duplicate', 'delete'], true)) {
            return;
        }

        $this->authorise('techzapp_crm_campaign_' . $action);

        $id = $this->intInput('campaign');

        if (CampaignService::find($id) === null) {
            throw CrmException::notFound(__('That campaign no longer exists.', 'techzapp-crm'));
        }

        $userId = Capabilities::currentUserId();

        if ($action === 'duplicate') {
            $newId = CampaignService::duplicate($id, $userId);

            $this->redirect(
                'techzapp-crm-campaign',
                ['campaign' => $newId],
                __('Campaign duplicated as a new draft.', 'techzapp-crm')
            );
        }

        $message = match ($action) {
            'pause' => $this->run(
                static fn () => CampaignService::pause($id, $userId),
                __('Campaign paused. No further messages go out until you resume it.', 'techzapp-crm')
            ),
            'resume' => $this->run(
                static fn () => CampaignService::resume($id, $userId),
                __('Campaign resumed. Sending continues on the next worker run.', 'techzapp-crm')
            ),
            'cancel' => $this->run(
                static fn () => CampaignService::cancel($id, $userId),
                __('Campaign cancelled. Remaining recipients were skipped.', 'techzapp-crm')
            ),
            default => $this->run(
                static fn () => CampaignService::delete($id),
                __('Campaign deleted.', 'techzapp-crm')
            ),
        };

        $this->redirect('techzapp-crm-campaigns', [], $message);
    }

    private function run(callable $operation, string $message): string
    {
        $operation();
        return $message;
    }
}
