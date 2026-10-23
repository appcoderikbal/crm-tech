<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\Cron;
use TechzappCrm\Services\CampaignService;
use TechzappCrm\Services\ReportService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Results for one campaign.
 *
 * Every rate names its own denominator. "Accepted" means SES took the message;
 * "delivered" means a Delivery event came back. They are different numbers and
 * are never conflated here — if event publishing is not configured, the screen
 * says the delivery figures are unknown rather than showing zero.
 */
final class CampaignReport extends Screen
{
    private const RECIPIENT_STATUSES = [
        'pending'    => 'neutral',
        'sending'    => 'brand',
        'accepted'   => 'brand',
        'delivered'  => 'ok',
        'bounced'    => 'danger',
        'complained' => 'danger',
        'failed'     => 'danger',
        'suppressed' => 'neutral',
        'cancelled'  => 'neutral',
    ];

    public function render(): void
    {
        $id = $this->intInput('campaign');
        $report = ReportService::campaignReport($id);

        $campaign = $report['campaign'];
        $metrics = $report['metrics'];
        $rates = $report['rates'];

        View::open(
            sprintf(
                /* translators: %s: campaign name */
                __('Report: %s', 'techzapp-crm'),
                (string) $campaign['name']
            ),
            Menu::url('techzapp-crm-campaigns'),
            __('All campaigns', 'techzapp-crm')
        );

        $this->renderHeader($campaign, $metrics);
        $this->renderSendControl($campaign, $metrics);

        foreach ($report['data_quality'] as $note) {
            View::notice((string) $note, 'warning');
        }

        $this->renderProgress($metrics);
        $this->renderRates($metrics, $rates);
        $this->renderLinks($report['links']);
        $this->renderRecipients($id);

        echo '<p class="description">' . esc_html((string) $report['tracking_note']) . '</p>';

        View::close();
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,int>   $metrics
     */
    private function renderHeader(array $campaign, array $metrics): void
    {
        View::cardOpen(__('Overview', 'techzapp-crm'));

        echo '<table class="widefat striped"><tbody>';

        $rows = [
            __('Status', 'techzapp-crm')  => (string) $campaign['status'],
            __('Subject', 'techzapp-crm') => (string) $campaign['subject'],
            __('From', 'techzapp-crm')    => trim((string) $campaign['from_name'] . ' <' . (string) $campaign['from_email'] . '>'),
            __('Started', 'techzapp-crm') => View::dateTime($campaign['started_at'] ?? null),
            __('Finished', 'techzapp-crm') => View::dateTime($campaign['completed_at'] ?? null),
        ];

        foreach ($rows as $label => $value) {
            printf(
                '<tr><th scope="row" style="width:180px">%s</th><td>%s</td></tr>',
                esc_html((string) $label),
                esc_html($value !== '' ? $value : '—')
            );
        }

        if (!empty($campaign['last_error'])) {
            printf(
                '<tr><th scope="row">%s</th><td class="tone-danger">%s</td></tr>',
                esc_html__('Last error', 'techzapp-crm'),
                esc_html((string) $campaign['last_error'])
            );
        }

        echo '</tbody></table>';
        View::cardClose();
    }

    /**
     * The live send panel.
     *
     * Shown whenever the campaign still owns unsent recipients. In manual mode
     * this loop is the only thing that will send them, so the panel says so
     * plainly rather than leaving the operator waiting for a worker that is
     * never going to run.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,int>   $metrics
     */
    private function renderSendControl(array $campaign, array $metrics): void
    {
        $status = (string) $campaign['status'];

        if (!in_array($status, ['queued', 'sending', 'paused'], true)) {
            return;
        }

        if (!Capabilities::canManage()) {
            return;
        }

        $id = (int) $campaign['id'];
        $manual = Cron::isManual();

        // Arriving straight from "Send now" starts without a second click.
        $autostart = $this->input('sending') === '1' && $status !== 'paused';

        View::cardOpen(
            __('Sending', 'techzapp-crm'),
            $manual
                ? __('Scheduled background jobs are turned off, so this tab does the sending. Keep it open until it finishes.', 'techzapp-crm')
                : __('A background worker is also processing this campaign. You can drive it from here as well.', 'techzapp-crm')
        );

        if ($status === 'paused') {
            View::notice(
                __('This campaign is paused. Resume it from the Campaigns screen before sending can continue.', 'techzapp-crm'),
                'warning'
            );
        }

        $this->renderAudienceNote($campaign, $metrics);

        printf(
            '<div id="techzapp-send-progress" class="techzapp-send" data-campaign="%d" data-autostart="%s">',
            $id,
            $autostart ? '1' : '0'
        );

        echo '<div class="techzapp-progress"><div class="techzapp-progress__bar" style="width:0%"></div></div>';

        echo '<p class="techzapp-send__counts">';
        foreach ([
            'sent'    => __('Sent', 'techzapp-crm'),
            'pending' => __('Remaining', 'techzapp-crm'),
            'failed'  => __('Failed', 'techzapp-crm'),
            'skipped' => __('Skipped', 'techzapp-crm'),
            'unconfirmed' => __('Unconfirmed', 'techzapp-crm'),
            'total'   => __('Total', 'techzapp-crm'),
        ] as $key => $label) {
            printf(
                '<span class="techzapp-send__count"><strong data-count="%s">%s</strong> %s</span>',
                esc_attr($key),
                esc_html(View::number(match ($key) {
                    'sent'    => $metrics['accepted'],
                    'pending' => $metrics['pending'],
                    'failed'  => $metrics['failed'],
                    'skipped' => $metrics['suppressed'] + $metrics['cancelled'],
                    'unconfirmed' => 0,
                    default   => $metrics['total_recipients'],
                })),
                esc_html($label)
            );
        }
        echo '</p>';

        printf('<p data-role="status" class="description">%s</p>', esc_html__('Ready.', 'techzapp-crm'));

        // Hidden until the first result arrives, so an idle panel is not
        // dominated by an empty box.
        printf(
            '<div class="techzapp-log" data-role="log" role="log" aria-label="%s" hidden></div>',
            esc_attr__('Sending log', 'techzapp-crm')
        );

        if ($status !== 'paused') {
            printf(
                '<button type="button" class="button button-primary" id="techzapp-send-start" data-confirm="%s">%s</button> ',
                esc_attr__('Start sending to every remaining recipient. Messages already sent cannot be recalled.', 'techzapp-crm'),
                esc_html__('Start sending', 'techzapp-crm')
            );
            printf(
                '<button type="button" class="button" id="techzapp-send-stop" hidden>%s</button>',
                esc_html__('Stop', 'techzapp-crm')
            );
        }

        echo '</div>';

        echo '<p class="description">'
            . esc_html__('Stopping only halts this tab. The campaign keeps its place, so starting again continues from the next unsent recipient rather than sending anyone twice.', 'techzapp-crm')
            . '</p>';

        View::cardClose();
    }

    /**
     * Explains a recipient count that looks wrong.
     *
     * "I added someone to the list but they were not sent to" has several
     * different causes — suppressed, unsubscribed, inactive, or simply on a
     * different list, since a contact belongs to exactly one. Comparing what
     * the list holds now against what this send built turns that into a
     * statement rather than a mystery.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,int>   $metrics
     */
    private function renderAudienceNote(array $campaign, array $metrics): void
    {
        $listId = $campaign['list_id'] === null ? null : (int) $campaign['list_id'];
        if ($listId === null) {
            return;
        }

        $counts = CampaignService::recipientCounts($listId);
        $built = $metrics['total_recipients'];

        $excluded = $counts['suppressed'] + $counts['unsubscribed'] + $counts['invalid'];

        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: recipients built, 2: eligible on the list now, 3: list total */
            __('This send built %1$s recipient(s). The list currently holds %3$s contact(s), %2$s of them eligible.', 'techzapp-crm'),
            View::number($built),
            View::number($counts['eligible']),
            View::number($counts['list_total'])
        )) . '</p>';

        if ($excluded > 0) {
            echo '<p class="description tone-warn">' . esc_html(sprintf(
                /* translators: 1: suppressed, 2: unsubscribed, 3: invalid or inactive */
                __('Excluded from this list: %1$s suppressed, %2$s unsubscribed, %3$s invalid or inactive. Those are skipped on purpose.', 'techzapp-crm'),
                View::number($counts['suppressed']),
                View::number($counts['unsubscribed']),
                View::number($counts['invalid'])
            )) . '</p>';
        }

        if ($counts['eligible'] > $built) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(
                /* translators: %s: number of contacts */
                __('%s eligible contact(s) on this list have no recipient row for this send — they were added after it was queued. Starting the send again picks them up; it will not send to anyone twice.', 'techzapp-crm'),
                View::number($counts['eligible'] - $built)
            )) . '</p></div>';
        }

        printf(
            '<p class="description"><a href="%s">%s</a> &middot; <a href="%s">%s</a></p>',
            esc_url(Menu::url('techzapp-crm-list', ['list_id' => $listId, 'assignment' => 'any'])),
            esc_html__('See who is on this list', 'techzapp-crm'),
            esc_url(Menu::url('techzapp-crm-suppressions')),
            esc_html__('Check the suppression list', 'techzapp-crm')
        );
    }

    /** @param array<string,int> $metrics */
    private function renderProgress(array $metrics): void
    {
        View::statTiles([
            [
                'label' => __('Recipients', 'techzapp-crm'),
                'value' => View::number($metrics['total_recipients']),
                'hint'  => __('built for this send', 'techzapp-crm'),
            ],
            [
                'label' => __('Accepted by SES', 'techzapp-crm'),
                'value' => View::number($metrics['accepted']),
                'hint'  => __('handed over successfully', 'techzapp-crm'),
                'tone'  => 'brand',
            ],
            [
                'label' => __('Confirmed delivered', 'techzapp-crm'),
                'value' => View::number($metrics['delivered']),
                'hint'  => __('delivery event received', 'techzapp-crm'),
                'tone'  => $metrics['delivered'] > 0 ? 'ok' : 'neutral',
            ],
            [
                'label' => __('Still queued', 'techzapp-crm'),
                'value' => View::number($metrics['pending']),
                'tone'  => $metrics['pending'] > 0 ? 'brand' : 'neutral',
            ],
            [
                'label' => __('Failed', 'techzapp-crm'),
                'value' => View::number($metrics['failed']),
                'tone'  => $metrics['failed'] > 0 ? 'danger' : 'neutral',
            ],
            [
                'label' => __('Skipped', 'techzapp-crm'),
                'value' => View::number($metrics['suppressed'] + $metrics['cancelled']),
                'hint'  => __('suppressed or cancelled', 'techzapp-crm'),
            ],
        ]);
    }

    /**
     * @param array<string,int>        $metrics
     * @param array<string,float|null> $rates
     */
    private function renderRates(array $metrics, array $rates): void
    {
        View::cardOpen(
            __('Engagement and deliverability', 'techzapp-crm'),
            __('Each rate states the number it is divided by, because the same word means different things in different tools.', 'techzapp-crm')
        );

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Metric', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Count', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Rate', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Out of', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        $rows = [
            [
                __('Delivered', 'techzapp-crm'),
                $metrics['delivered'],
                $rates['delivery_rate'],
                __('messages accepted by SES', 'techzapp-crm'),
            ],
            [
                __('Bounced (hard + soft)', 'techzapp-crm'),
                $metrics['hard_bounces'] + $metrics['soft_bounces'],
                $rates['bounce_rate'],
                __('send attempts', 'techzapp-crm'),
            ],
            [
                __('Hard bounces', 'techzapp-crm'),
                $metrics['hard_bounces'],
                $rates['hard_bounce_rate'],
                __('send attempts', 'techzapp-crm'),
            ],
            [
                __('Complaints', 'techzapp-crm'),
                $metrics['complaints'],
                $rates['complaint_rate'],
                __('messages accepted by SES', 'techzapp-crm'),
            ],
            [
                __('Unique opens', 'techzapp-crm'),
                $metrics['unique_opens'],
                $rates['open_rate'],
                __('confirmed deliveries', 'techzapp-crm'),
            ],
            [
                __('Unique clicks', 'techzapp-crm'),
                $metrics['unique_clicks'],
                $rates['click_rate'],
                __('confirmed deliveries', 'techzapp-crm'),
            ],
            [
                __('Click-to-open', 'techzapp-crm'),
                $metrics['unique_clicks'],
                $rates['click_to_open'],
                __('unique opens', 'techzapp-crm'),
            ],
            [
                __('Unsubscribes', 'techzapp-crm'),
                $metrics['unsubscribes'],
                $rates['unsubscribe_rate'],
                __('confirmed deliveries', 'techzapp-crm'),
            ],
        ];

        foreach ($rows as [$label, $count, $rate, $denominator]) {
            printf(
                '<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="description">%s</td></tr>',
                esc_html((string) $label),
                esc_html(View::number((int) $count)),
                // A null rate means the denominator was zero. Showing 0% there
                // would claim a measurement that was never possible.
                $rate === null
                    ? '<span class="description">—</span>'
                    : esc_html(View::percent((float) $rate)),
                esc_html((string) $denominator)
            );
        }

        echo '</tbody></table>';

        echo '<p class="description">'
            . esc_html__('Totals (not unique): ', 'techzapp-crm')
            . esc_html(sprintf(
                /* translators: 1: total opens, 2: total clicks */
                __('%1$s opens, %2$s clicks.', 'techzapp-crm'),
                View::number($metrics['total_opens']),
                View::number($metrics['total_clicks'])
            ))
            . '</p>';

        View::cardClose();
    }

    /** @param list<array<string,mixed>> $links */
    private function renderLinks(array $links): void
    {
        if ($links === []) {
            return;
        }

        View::cardOpen(__('Links', 'techzapp-crm'));

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('URL', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Unique clicks', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Total clicks', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($links as $link) {
            printf(
                '<tr><td><span class="techzapp-url">%s</span></td><td class="num">%s</td><td class="num">%s</td></tr>',
                esc_html((string) $link['url']),
                esc_html(View::number((int) $link['unique_clicks'])),
                esc_html(View::number((int) $link['total_clicks']))
            );
        }

        echo '</tbody></table>';
        View::cardClose();
    }

    private function renderRecipients(int $campaignId): void
    {
        $status = $this->input('status');
        $engagement = $this->input('engagement');

        $result = ReportService::campaignRecipients($campaignId, [
            'page'       => max(1, $this->intInput('paged', 1)),
            'status'     => $status !== '' ? $status : null,
            'engagement' => $engagement !== '' ? $engagement : null,
            'search'     => $this->input('s'),
        ]);

        View::cardOpen(__('Recipients', 'techzapp-crm'));

        echo '<form method="get" class="techzapp-search-form">';
        echo '<input type="hidden" name="page" value="techzapp-crm-report">';
        printf('<input type="hidden" name="campaign" value="%d">', $campaignId);
        echo '<div class="techzapp-filters">';

        echo '<select name="status"><option value="">' . esc_html__('Any status', 'techzapp-crm') . '</option>';
        foreach (array_keys(self::RECIPIENT_STATUSES) as $candidate) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($candidate),
                selected($status, $candidate, false),
                esc_html($candidate)
            );
        }
        echo '</select>';

        echo '<select name="engagement"><option value="">' . esc_html__('Any engagement', 'techzapp-crm') . '</option>';
        foreach (['opened' => __('Opened', 'techzapp-crm'), 'clicked' => __('Clicked', 'techzapp-crm')] as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($engagement, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';

        printf(
            '<input type="search" name="s" value="%s" placeholder="%s">',
            esc_attr($this->input('s')),
            esc_attr__('Search email', 'techzapp-crm')
        );

        submit_button(__('Filter', 'techzapp-crm'), '', '', false);
        echo '</div></form>';

        if ($result['items'] === []) {
            echo '<p>' . esc_html__('No recipients match that filter.', 'techzapp-crm') . '</p>';
            View::cardClose();
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Email', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Status', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Opened', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Clicked', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Detail', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($result['items'] as $row) {
            $rowStatus = (string) $row['status'];

            $detail = '';
            if (!empty($row['last_error_message'])) {
                $detail = (string) $row['last_error_code'] . ' ' . (string) $row['last_error_message'];
            } elseif (!empty($row['unsubscribed_at'])) {
                $detail = __('Unsubscribed', 'techzapp-crm');
            }

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="description">%s</td></tr>',
                esc_html((string) $row['recipient_email_snapshot']),
                View::badge($rowStatus, self::RECIPIENT_STATUSES[$rowStatus] ?? 'neutral'),
                esc_html(View::dateTime($row['first_opened_at'] ?? null)),
                esc_html(View::dateTime($row['first_clicked_at'] ?? null)),
                esc_html($detail)
            );
        }

        echo '</tbody></table>';

        if ((int) $result['pages'] > 1) {
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

        View::cardClose();
    }
}
