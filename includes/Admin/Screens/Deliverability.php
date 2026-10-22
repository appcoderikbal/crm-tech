<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\AlertService;
use TechzappCrm\Services\ReportService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Account-wide sending health.
 *
 * Bounce and complaint rates are the two numbers Amazon measures when deciding
 * whether to keep an account in good standing, so they are shown against the
 * configured thresholds rather than in isolation — and loudly in red once a
 * critical threshold is crossed.
 */
final class Deliverability extends Screen
{
    private const WINDOWS = [24, 72, 168, 720];

    public function render(): void
    {
        $window = $this->intInput('window');
        if (!in_array($window, self::WINDOWS, true)) {
            $window = (int) Settings::int('alert_rolling_window_hours', 24);
        }

        $report = ReportService::deliverability($window);
        $metrics = $report['metrics'];
        $rates = $report['rates'];
        $thresholds = $report['thresholds'];
        $health = $report['health'];

        View::open(__('Deliverability', 'techzapp-crm'));

        $this->renderHealthBanner($health, $report);
        $this->renderWindowPicker($window);
        $this->renderAlerts($report['alerts']);

        $underSample = (int) $report['sample_size'] < (int) $report['min_sample'];

        if ($underSample) {
            View::notice(
                sprintf(
                    /* translators: 1: messages sent, 2: minimum sample size */
                    __('Only %1$s messages were sent in this window. Rates below are calculated but not acted on until the sample reaches %2$s, because a handful of bounces in a tiny sample is not a trend.', 'techzapp-crm'),
                    View::number((int) $report['sample_size']),
                    View::number((int) $report['min_sample'])
                ),
                'info'
            );
        }

        View::statTiles([
            ['label' => __('Attempted', 'techzapp-crm'), 'value' => View::number($metrics['attempted'])],
            ['label' => __('Accepted by SES', 'techzapp-crm'), 'value' => View::number($metrics['accepted'])],
            [
                'label' => __('Confirmed delivered', 'techzapp-crm'),
                'value' => View::number($metrics['delivered']),
                'tone'  => $metrics['delivered'] > 0 ? 'ok' : 'neutral',
            ],
            [
                'label' => __('Hard bounces', 'techzapp-crm'),
                'value' => View::number($metrics['hard_bounces']),
                'tone'  => $metrics['hard_bounces'] > 0 ? 'warn' : 'neutral',
            ],
            [
                'label' => __('Complaints', 'techzapp-crm'),
                'value' => View::number($metrics['complaints']),
                'tone'  => $metrics['complaints'] > 0 ? 'warn' : 'neutral',
            ],
        ]);

        $this->renderThresholdTable($rates, $thresholds, $underSample);

        foreach ($report['data_quality'] as $note) {
            View::notice((string) $note, 'warning');
        }

        $this->renderProvider($report['provider']);

        echo '<p class="description">'
            . esc_html__('Thresholds are configured under Settings. Amazon generally expects a bounce rate under 5% and a complaint rate under 0.1%; sustained breaches put an SES account under review.', 'techzapp-crm')
            . '</p>';

        View::close();
    }

    /**
     * Health is reported per component (deliverability, provider, events,
     * cron). The banner takes the worst of them, then the table below says
     * which component is responsible — an overall "red" is useless without it.
     *
     * @param array<string,array{status:string,detail:string}> $health
     * @param array<string,mixed>                              $report
     */
    private function renderHealthBanner(array $health, array $report): void
    {
        $rank = ['green' => 0, 'unknown' => 1, 'amber' => 2, 'red' => 3];

        $worst = 'green';
        foreach ($health as $component) {
            $status = (string) ($component['status'] ?? 'unknown');
            if (($rank[$status] ?? 1) > ($rank[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        View::notice(
            match ($worst) {
                'green' => __('Sending health: good.', 'techzapp-crm'),
                'amber' => __('Sending health: needs attention. See which component below.', 'techzapp-crm'),
                'red'   => __('Sending health: critical. Resolve this before sending more.', 'techzapp-crm'),
                default => __('Sending health: not enough data yet.', 'techzapp-crm'),
            },
            match ($worst) {
                'green' => 'success',
                'amber' => 'warning',
                'red'   => 'error',
                default => 'brand',
            }
        );

        View::cardOpen(__('Components', 'techzapp-crm'));
        echo '<table class="widefat striped"><tbody>';

        foreach ($health as $name => $component) {
            $status = (string) ($component['status'] ?? 'unknown');

            printf(
                '<tr><th scope="row" style="width:200px">%s %s</th><td>%s</td><td class="description">%s</td></tr>',
                View::statusDot($status),
                esc_html(ucwords(str_replace('_', ' ', (string) $name))),
                View::badge($status, match ($status) {
                    'green'  => 'ok',
                    'amber'  => 'warn',
                    'red'    => 'danger',
                    default  => 'neutral',
                }),
                esc_html((string) ($component['detail'] ?? ''))
            );
        }

        echo '</tbody></table>';
        View::cardClose();

        if (!Settings::sendingAllowed()) {
            View::notice(
                sprintf(
                    /* translators: %s: the configured reason */
                    __('Sending is currently disabled by the kill switch. Reason: %s', 'techzapp-crm'),
                    Settings::string('kill_switch_reason', '—')
                ),
                'error'
            );
        }
    }

    private function renderWindowPicker(int $current): void
    {
        $labels = [
            24  => __('Last 24 hours', 'techzapp-crm'),
            72  => __('Last 3 days', 'techzapp-crm'),
            168 => __('Last 7 days', 'techzapp-crm'),
            720 => __('Last 30 days', 'techzapp-crm'),
        ];

        $links = [];
        foreach (self::WINDOWS as $hours) {
            $links[] = sprintf(
                '<a href="%s"%s>%s</a>',
                esc_url(Menu::url('techzapp-crm-deliverability', ['window' => $hours])),
                $hours === $current ? ' class="current"' : '',
                esc_html($labels[$hours])
            );
        }

        echo '<p class="techzapp-window-picker">' . implode(' | ', $links) . '</p>';
    }

    /** @param list<array<string,mixed>> $alerts */
    private function renderAlerts(array $alerts): void
    {
        if ($alerts === []) {
            return;
        }

        View::cardOpen(__('Open alerts', 'techzapp-crm'));

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Severity', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Alert', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Last seen', 'techzapp-crm') . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        foreach ($alerts as $alert) {
            $severity = (string) ($alert['severity'] ?? 'info');

            if ((string) ($alert['status'] ?? '') === 'acknowledged') {
                $acknowledge = '<span class="description">' . esc_html__('Acknowledged', 'techzapp-crm') . '</span>';
            } elseif (Capabilities::canManage()) {
                $acknowledge = sprintf(
                    '<a href="%s" class="button button-small">%s</a>',
                    esc_url(View::actionUrl(
                        'techzapp-crm-deliverability',
                        'acknowledge',
                        'techzapp_crm_alert_ack',
                        ['alert' => (int) $alert['id']]
                    )),
                    esc_html__('Acknowledge', 'techzapp-crm')
                );
            } else {
                $acknowledge = '';
            }

            printf(
                '<tr><td>%s</td><td><strong>%s</strong><br><span class="description">%s</span></td><td>%s</td><td>%s</td></tr>',
                View::badge($severity, match ($severity) {
                    'critical' => 'danger',
                    'warning'  => 'warn',
                    default    => 'neutral',
                }),
                esc_html((string) ($alert['title'] ?? $alert['alert_key'] ?? '')),
                esc_html((string) ($alert['message'] ?? '')),
                esc_html(View::dateTime($alert['last_seen_at'] ?? null)),
                $acknowledge
            );
        }

        echo '</tbody></table>';
        View::cardClose();
    }

    /**
     * @param array<string,float|null> $rates
     * @param array<string,mixed>      $thresholds
     */
    private function renderThresholdTable(array $rates, array $thresholds, bool $underSample): void
    {
        View::cardOpen(__('Rates against your thresholds', 'techzapp-crm'));

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Rate', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Current', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Warning at', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Critical at', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Out of', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        $rows = [
            [
                __('Bounce rate', 'techzapp-crm'),
                $rates['bounce_rate'],
                (float) ($thresholds['bounce_warning'] ?? 0),
                (float) ($thresholds['bounce_critical'] ?? 0),
                __('send attempts', 'techzapp-crm'),
            ],
            [
                __('Complaint rate', 'techzapp-crm'),
                $rates['complaint_rate'],
                (float) ($thresholds['complaint_warning'] ?? 0),
                (float) ($thresholds['complaint_critical'] ?? 0),
                __('messages accepted by SES', 'techzapp-crm'),
            ],
        ];

        foreach ($rows as [$label, $value, $warn, $critical, $denominator]) {
            $tone = 'neutral';

            // Under the minimum sample the colour is deliberately withheld:
            // one bounce out of five is 20% and means nothing.
            if ($value !== null && !$underSample) {
                if ($critical > 0 && $value >= $critical) {
                    $tone = 'bad';
                } elseif ($warn > 0 && $value >= $warn) {
                    $tone = 'warn';
                } else {
                    $tone = 'good';
                }
            }

            printf(
                '<tr><td>%s</td><td class="num tone-%s"><strong>%s</strong></td><td class="num">%s</td><td class="num">%s</td><td class="description">%s</td></tr>',
                esc_html((string) $label),
                esc_attr($tone),
                $value === null
                    ? '<span class="description">—</span>'
                    : esc_html(View::percent((float) $value)),
                esc_html(View::percent($warn)),
                esc_html(View::percent($critical)),
                esc_html((string) $denominator)
            );
        }

        echo '</tbody></table>';

        echo '<p class="description">'
            . esc_html__('A dash means the rate could not be calculated because nothing was sent in this window — not that the rate is zero.', 'techzapp-crm')
            . '</p>';

        View::cardClose();
    }

    /** @param array<string,mixed>|null $provider */
    private function renderProvider(?array $provider): void
    {
        if ($provider === null) {
            View::cardOpen(__('Amazon SES account', 'techzapp-crm'));
            echo '<p class="description">'
                . esc_html__('The SES account status could not be read. Check the credentials and region under Settings.', 'techzapp-crm')
                . '</p>';
            View::cardClose();
            return;
        }

        View::cardOpen(__('Amazon SES account', 'techzapp-crm'));

        echo '<table class="widefat striped"><tbody>';
        foreach ($provider as $key => $value) {
            printf(
                '<tr><th scope="row" style="width:240px">%s</th><td>%s</td></tr>',
                esc_html(ucwords(str_replace('_', ' ', (string) $key))),
                esc_html(is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : (string) wp_json_encode($value)))
            );
        }
        echo '</tbody></table>';

        View::cardClose();
    }

    public function handle(string $action): void
    {
        if ($action !== 'acknowledge') {
            return;
        }

        $this->authorise('techzapp_crm_alert_ack');

        $userId = Capabilities::currentUserId();
        AlertService::acknowledge($this->intInput('alert'), $userId ?? 0);

        $this->redirect(
            'techzapp-crm-deliverability',
            [],
            __('Alert acknowledged. It stays open until the underlying condition clears.', 'techzapp-crm')
        );
    }
}
