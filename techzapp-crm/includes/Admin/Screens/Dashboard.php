<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Services\ReportService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The overview screen.
 *
 * Every number here is read from stored rows or recorded events. Nothing is
 * estimated, and a metric with no data shows a dash rather than a zero.
 */
final class Dashboard extends Screen
{
    public function render(): void
    {
        $days = max(1, min(365, $this->intInput('range', 30)));
        $from = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        $data = ReportService::dashboard($from, gmdate('Y-m-d H:i:s'));

        $contacts = $data['contacts'];
        $campaigns = $data['campaigns'];
        $metrics = $data['metrics'];
        $rates = $data['rates'];
        $sending = $data['sending_status'];

        View::open(__('Techzapp CRM', 'techzapp-crm'));

        $this->rangeSelector($days);

        // ----------------------------------------------------------- audience
        echo '<h2 class="techzapp-section">' . esc_html__('Audience', 'techzapp-crm') . '</h2>';
        View::statTiles([
            [
                'label' => __('Total contacts', 'techzapp-crm'),
                'value' => View::number($contacts['total']),
                'url'   => Menu::url('techzapp-crm-contacts', ['assignment' => 'any']),
            ],
            [
                'label' => __('Unassigned', 'techzapp-crm'),
                'value' => View::number($contacts['unassigned']),
                'hint'  => __('Not on any list', 'techzapp-crm'),
                'url'   => Menu::url('techzapp-crm-contacts'),
            ],
            [
                'label' => __('Lists', 'techzapp-crm'),
                'value' => View::number($data['lists']['total']),
                'url'   => Menu::url('techzapp-crm-lists'),
            ],
            [
                'label' => __('Suppressed', 'techzapp-crm'),
                'value' => View::number($contacts['suppressed']),
                'tone'  => $contacts['suppressed'] > 0 ? 'warn' : 'neutral',
                'url'   => Menu::url('techzapp-crm-suppressions'),
            ],
            [
                'label' => __('Unsubscribed', 'techzapp-crm'),
                'value' => View::number($contacts['unsubscribed']),
            ],
        ]);

        // ---------------------------------------------------------- campaigns
        echo '<h2 class="techzapp-section">' . esc_html__('Campaigns', 'techzapp-crm') . '</h2>';
        View::statTiles([
            ['label' => __('Total', 'techzapp-crm'), 'value' => View::number($campaigns['total'])],
            ['label' => __('Draft', 'techzapp-crm'), 'value' => View::number($campaigns['draft'])],
            ['label' => __('Scheduled', 'techzapp-crm'), 'value' => View::number($campaigns['scheduled']), 'tone' => 'brand'],
            [
                'label' => __('Sending now', 'techzapp-crm'),
                'value' => View::number($campaigns['sending']),
                'tone'  => $campaigns['sending'] > 0 ? 'brand' : 'neutral',
            ],
            [
                'label' => __('Paused', 'techzapp-crm'),
                'value' => View::number($campaigns['paused']),
                'tone'  => $campaigns['paused'] > 0 ? 'warn' : 'neutral',
            ],
            [
                'label' => __('Failed', 'techzapp-crm'),
                'value' => View::number($campaigns['failed']),
                'tone'  => $campaigns['failed'] > 0 ? 'danger' : 'neutral',
            ],
        ]);

        // ------------------------------------------------------------ sending
        echo '<h2 class="techzapp-section">' . esc_html__('Sending in the selected range', 'techzapp-crm') . '</h2>';
        View::statTiles([
            [
                'label' => __('Attempted', 'techzapp-crm'),
                'value' => View::number($metrics['attempted']),
                'hint'  => __('Recipients the sender tried', 'techzapp-crm'),
            ],
            [
                'label' => __('Accepted by SES', 'techzapp-crm'),
                'value' => View::number($metrics['accepted']),
                'hint'  => __('API acceptance, not delivery', 'techzapp-crm'),
            ],
            [
                'label' => __('Confirmed deliveries', 'techzapp-crm'),
                'value' => View::number($metrics['delivered']),
                'hint'  => sprintf(
                    /* translators: %s: percentage */
                    __('%s of accepted', 'techzapp-crm'),
                    View::percent($rates['delivery_rate'])
                ),
                'tone'  => 'ok',
            ],
            ['label' => __('Unique opens', 'techzapp-crm'), 'value' => View::number($metrics['unique_opens'])],
            ['label' => __('Unique clicks', 'techzapp-crm'), 'value' => View::number($metrics['unique_clicks'])],
            [
                'label' => __('Hard bounces', 'techzapp-crm'),
                'value' => View::number($metrics['hard_bounces']),
                'tone'  => $metrics['hard_bounces'] > 0 ? 'danger' : 'neutral',
            ],
            [
                'label' => __('Spam complaints', 'techzapp-crm'),
                'value' => View::number($metrics['complaints']),
                'tone'  => $metrics['complaints'] > 0 ? 'danger' : 'neutral',
            ],
            [
                'label' => __('Sent today', 'techzapp-crm'),
                'value' => View::number($sending['sent_today']),
                'hint'  => sprintf(
                    /* translators: %s: daily cap */
                    __('Cap %s/day', 'techzapp-crm'),
                    View::number($sending['daily_cap'])
                ),
            ],
        ]);

        echo '<p class="description">'
            . esc_html__('Opens and clicks are tracked engagement signals. Privacy proxies and security scanners can inflate or hide them.', 'techzapp-crm')
            . '</p>';

        foreach ($data['data_quality'] as $note) {
            View::notice($note, 'warning');
        }

        echo '<div class="techzapp-columns">';
        $this->healthCard($data['health'], $sending);
        $this->recentCampaignsCard($data['recent_campaigns']);
        echo '</div>';

        $this->recentEventsCard($data['recent_events']);

        View::close();
    }

    private function rangeSelector(int $days): void
    {
        $options = [
            7   => __('Last 7 days', 'techzapp-crm'),
            30  => __('Last 30 days', 'techzapp-crm'),
            90  => __('Last 90 days', 'techzapp-crm'),
            365 => __('Last 12 months', 'techzapp-crm'),
        ];

        echo '<form method="get" class="techzapp-range">';
        echo '<input type="hidden" name="page" value="techzapp-crm">';
        echo '<label for="techzapp-range">' . esc_html__('Date range', 'techzapp-crm') . '</label> ';
        echo '<select id="techzapp-range" name="range" onchange="this.form.submit()">';

        foreach ($options as $value => $label) {
            printf(
                '<option value="%d"%s>%s</option>',
                $value,
                selected($days, $value, false),
                esc_html($label)
            );
        }

        echo '</select></form>';
    }

    private function healthCard(array $health, array $sending): void
    {
        echo '<div class="techzapp-column">';
        View::cardOpen(
            __('System health', 'techzapp-crm'),
            __('Grey means unknown, never assumed healthy.', 'techzapp-crm')
        );

        echo '<ul class="techzapp-health">';
        foreach ($health as $area => $entry) {
            printf(
                '<li>%s<div><strong>%s</strong><span class="description">%s</span></div></li>',
                View::statusDot((string) $entry['status']),
                esc_html(ucfirst(str_replace('_', ' ', (string) $area))),
                esc_html((string) $entry['detail'])
            );
        }

        printf(
            '<li>%s<div><strong>%s</strong><span class="description">%s</span></div></li>',
            View::statusDot($sending['can_send'] ? 'green' : 'red'),
            esc_html__('Queue', 'techzapp-crm'),
            esc_html(sprintf(
                /* translators: 1: recipients waiting, 2: campaigns in flight */
                __('%1$s recipient(s) waiting across %2$s campaign(s).', 'techzapp-crm'),
                View::number($sending['queued_recipients']),
                View::number($sending['campaigns_in_flight'])
            ))
        );
        echo '</ul>';

        View::cardClose();
        echo '</div>';
    }

    private function recentCampaignsCard(array $campaigns): void
    {
        echo '<div class="techzapp-column techzapp-column--wide">';
        View::cardOpen(__('Recent campaigns', 'techzapp-crm'));

        if ($campaigns === []) {
            echo '<p>' . esc_html__('No campaigns yet.', 'techzapp-crm') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Campaign', 'techzapp-crm') . '</th>';
            echo '<th>' . esc_html__('Status', 'techzapp-crm') . '</th>';
            echo '<th class="num">' . esc_html__('Accepted', 'techzapp-crm') . '</th>';
            echo '<th class="num">' . esc_html__('Delivered', 'techzapp-crm') . '</th>';
            echo '<th class="num">' . esc_html__('Opens', 'techzapp-crm') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($campaigns as $campaign) {
                printf(
                    '<tr><td><a href="%s"><strong>%s</strong></a><br><span class="description">%s</span></td>'
                    . '<td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                    esc_url(Menu::url('techzapp-crm-report', ['campaign' => (int) $campaign['id']])),
                    esc_html((string) $campaign['name']),
                    esc_html(View::relative(
                        $campaign['started_at'] ?? $campaign['scheduled_at_utc'] ?? $campaign['created_at'] ?? null
                    )),
                    CampaignStatus::badge((string) $campaign['status']),
                    esc_html(View::number($campaign['accepted'] ?? 0)),
                    esc_html(View::number($campaign['delivered'] ?? 0)),
                    esc_html(View::number($campaign['unique_opens'] ?? 0))
                );
            }

            echo '</tbody></table>';
        }

        View::cardClose();
        echo '</div>';
    }

    private function recentEventsCard(array $events): void
    {
        View::cardOpen(
            __('Recent delivery events', 'techzapp-crm'),
            __('Newest provider and tracking events.', 'techzapp-crm')
        );

        if ($events === []) {
            echo '<p>' . esc_html__('No events recorded yet. They appear once SES event publishing is configured and a campaign has sent.', 'techzapp-crm') . '</p>';
            View::cardClose();
            return;
        }

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Event', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Recipient', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Campaign', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('When', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $event) {
            $type = (string) $event['event_type'];
            $label = str_replace('_', ' ', $type);
            if (($event['event_subtype'] ?? '') !== '') {
                $label .= ' · ' . $event['event_subtype'];
            }

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                View::badge($label, self::eventTone($type)),
                esc_html((string) ($event['recipient_email_snapshot'] ?? '—')),
                $event['campaign_id'] !== null
                    ? sprintf(
                        '<a href="%s">%s</a>',
                        esc_url(Menu::url('techzapp-crm-report', ['campaign' => (int) $event['campaign_id']])),
                        esc_html((string) $event['campaign_name'])
                    )
                    : '—',
                esc_html(View::dateTime((string) $event['event_timestamp']))
            );
        }

        echo '</tbody></table>';
        View::cardClose();
    }

    public static function eventTone(string $type): string
    {
        return match ($type) {
            'delivery' => 'ok',
            'open', 'click' => 'brand',
            'bounce', 'complaint', 'reject', 'failure' => 'danger',
            'unsubscribe', 'delivery_delay' => 'warn',
            default => 'neutral',
        };
    }
}
