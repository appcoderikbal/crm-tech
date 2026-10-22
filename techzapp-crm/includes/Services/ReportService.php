<?php

declare(strict_types=1);

namespace TechzappCrm\Services;

use TechzappCrm\Core\Database;
use TechzappCrm\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reporting.
 *
 * Two numbers are deliberately kept apart everywhere:
 *   accepted  — SES took the message (an API success)
 *   delivered — a delivery event arrived from SES
 * A campaign that SES accepted in full can still have a poor delivery rate, and
 * the UI must be able to show that.
 *
 * Every rate below names its denominator; the same definitions are rendered in
 * the UI so a number never has two meanings.
 */
final class ReportService
{
    /** Metric denominators, surfaced in the API so the UI can label them. */
    public const DENOMINATORS = [
        'delivery_rate'     => 'SES-accepted messages',
        'bounce_rate'       => 'attempted recipients',
        'hard_bounce_rate'  => 'attempted recipients',
        'complaint_rate'    => 'SES-accepted messages',
        'open_rate'         => 'confirmed deliveries',
        'click_rate'        => 'confirmed deliveries',
        'click_to_open'     => 'unique tracked opens',
        'unsubscribe_rate'  => 'confirmed deliveries',
    ];

    /** Recomputes and stores the per-campaign counters. */
    public static function refreshCampaignStats(int $campaignId): array
    {
        $metrics = self::computeCampaignMetrics($campaignId);

        Database::execute(
            'INSERT INTO {campaign_stats}
                (campaign_id, total_recipients, attempted, accepted, delivered, hard_bounces, soft_bounces,
                 rejects, complaints, unique_opens, total_opens, unique_clicks, total_clicks,
                 unsubscribes, pending, failed, suppressed, refreshed_at)
             VALUES (:campaign, :total, :attempted, :accepted, :delivered, :hard, :soft,
                     :rejects, :complaints, :uopens, :topens, :uclicks, :tclicks,
                     :unsubs, :pending, :failed, :suppressed, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                total_recipients = VALUES(total_recipients), attempted = VALUES(attempted),
                accepted = VALUES(accepted), delivered = VALUES(delivered),
                hard_bounces = VALUES(hard_bounces), soft_bounces = VALUES(soft_bounces),
                rejects = VALUES(rejects), complaints = VALUES(complaints),
                unique_opens = VALUES(unique_opens), total_opens = VALUES(total_opens),
                unique_clicks = VALUES(unique_clicks), total_clicks = VALUES(total_clicks),
                unsubscribes = VALUES(unsubscribes), pending = VALUES(pending),
                failed = VALUES(failed), suppressed = VALUES(suppressed),
                refreshed_at = UTC_TIMESTAMP()',
            [
                'campaign'   => $campaignId,
                'total'      => $metrics['total_recipients'],
                'attempted'  => $metrics['attempted'],
                'accepted'   => $metrics['accepted'],
                'delivered'  => $metrics['delivered'],
                'hard'       => $metrics['hard_bounces'],
                'soft'       => $metrics['soft_bounces'],
                'rejects'    => $metrics['rejects'],
                'complaints' => $metrics['complaints'],
                'uopens'     => $metrics['unique_opens'],
                'topens'     => $metrics['total_opens'],
                'uclicks'    => $metrics['unique_clicks'],
                'tclicks'    => $metrics['total_clicks'],
                'unsubs'     => $metrics['unsubscribes'],
                'pending'    => $metrics['pending'],
                'failed'     => $metrics['failed'],
                'suppressed' => $metrics['suppressed'],
            ]
        );

        return $metrics;
    }

    /** @return array<string,int> */
    public static function computeCampaignMetrics(int $campaignId): array
    {
        $recipients = Database::first(
            "SELECT
                COUNT(*) AS total_recipients,
                SUM(CASE WHEN attempts > 0 THEN 1 ELSE 0 END) AS attempted,
                SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status IN ('pending','sending','needs_reconciliation') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'suppressed' THEN 1 ELSE 0 END) AS suppressed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribes
               FROM {campaign_recipients} WHERE campaign_id = :id",
            ['id' => $campaignId]
        ) ?? [];

        $events = Database::first(
            "SELECT
                SUM(CASE WHEN event_type = 'bounce' AND event_subtype LIKE 'Permanent%' THEN 1 ELSE 0 END) AS hard_bounces,
                SUM(CASE WHEN event_type = 'bounce' AND event_subtype NOT LIKE 'Permanent%' THEN 1 ELSE 0 END) AS soft_bounces,
                SUM(CASE WHEN event_type = 'reject' THEN 1 ELSE 0 END) AS rejects,
                SUM(CASE WHEN event_type = 'complaint' THEN 1 ELSE 0 END) AS complaints,
                SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) AS total_opens,
                SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS total_clicks,
                COUNT(DISTINCT CASE WHEN event_type = 'open' THEN recipient_id END) AS unique_opens,
                COUNT(DISTINCT CASE WHEN event_type = 'click' THEN recipient_id END) AS unique_clicks
               FROM {email_events} WHERE campaign_id = :id",
            ['id' => $campaignId]
        ) ?? [];

        $toInt = static fn (array $row, string $key): int => (int) ($row[$key] ?? 0);

        return [
            'total_recipients' => $toInt($recipients, 'total_recipients'),
            'attempted'        => $toInt($recipients, 'attempted'),
            'accepted'         => $toInt($recipients, 'accepted'),
            'delivered'        => $toInt($recipients, 'delivered'),
            'pending'          => $toInt($recipients, 'pending'),
            'failed'           => $toInt($recipients, 'failed'),
            'suppressed'       => $toInt($recipients, 'suppressed'),
            'cancelled'        => $toInt($recipients, 'cancelled'),
            'unsubscribes'     => $toInt($recipients, 'unsubscribes'),
            'hard_bounces'     => $toInt($events, 'hard_bounces'),
            'soft_bounces'     => $toInt($events, 'soft_bounces'),
            'rejects'          => $toInt($events, 'rejects'),
            'complaints'       => $toInt($events, 'complaints'),
            'total_opens'      => $toInt($events, 'total_opens'),
            'total_clicks'     => $toInt($events, 'total_clicks'),
            'unique_opens'     => $toInt($events, 'unique_opens'),
            'unique_clicks'    => $toInt($events, 'unique_clicks'),
        ];
    }

    /** @param array<string,int> $m @return array<string,float|null> */
    public static function rates(array $m): array
    {
        $rate = static function (int $numerator, int $denominator): ?float {
            // Null — not zero — when there is nothing to divide by, so the UI
            // can show "—" instead of a misleading 0%.
            return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
        };

        return [
            'delivery_rate'    => $rate($m['delivered'], $m['accepted']),
            'bounce_rate'      => $rate($m['hard_bounces'] + $m['soft_bounces'], $m['attempted']),
            'hard_bounce_rate' => $rate($m['hard_bounces'], $m['attempted']),
            'complaint_rate'   => $rate($m['complaints'], $m['accepted']),
            'open_rate'        => $rate($m['unique_opens'], $m['delivered']),
            'click_rate'       => $rate($m['unique_clicks'], $m['delivered']),
            'click_to_open'    => $rate($m['unique_clicks'], $m['unique_opens']),
            'unsubscribe_rate' => $rate($m['unsubscribes'], $m['delivered']),
        ];
    }

    /**
     * Flags combinations that mean the data itself is incomplete, rather than
     * the sending being bad.
     *
     * The common case is a rate above 100%: if SES publishes Open events but
     * not Delivery events, "unique opens / confirmed deliveries" is a real
     * division of two real numbers that nonetheless describes a gap in
     * configuration. Saying so is more useful than capping the number at 100%
     * and pretending everything is fine.
     *
     * @param array<string,int> $metrics
     * @return list<string>
     */
    public static function dataQualityNotes(array $metrics): array
    {
        $notes = [];

        if ($metrics['accepted'] > 0 && $metrics['delivered'] === 0
            && ($metrics['hard_bounces'] + $metrics['soft_bounces']) === 0) {
            $notes[] = 'No delivery events have been received for these messages. '
                . 'Delivery, bounce and complaint rates are unknown until SES event publishing is configured.';
        }

        if ($metrics['unique_opens'] > $metrics['delivered'] && $metrics['delivered'] > 0) {
            $notes[] = 'More recipients opened than have a confirmed delivery event, which pushes the open rate '
                . 'above 100%. Delivery events are missing for some messages — check that the SES configuration '
                . 'set publishes Delivery as well as Open.';
        }

        if ($metrics['delivered'] > $metrics['accepted']) {
            $notes[] = 'Confirmed deliveries exceed the messages this system recorded as accepted, which usually '
                . 'means events arrived for messages sent outside this application.';
        }

        return $notes;
    }

    /** Full campaign report. */
    public static function campaignReport(int $campaignId): array
    {
        $campaign = CampaignService::findOrFail($campaignId);
        $metrics = self::refreshCampaignStats($campaignId);

        $timeline = Database::all(
            "SELECT event_type, DATE_FORMAT(event_timestamp, '%Y-%m-%d %H:00:00') AS bucket, COUNT(*) AS total
               FROM {email_events}
              WHERE campaign_id = :id
           GROUP BY event_type, bucket
           ORDER BY bucket
              LIMIT 2000",
            ['id' => $campaignId]
        );

        $topLinks = Database::all(
            "SELECT cl.id, cl.url,
                    COUNT(e.id) AS total_clicks,
                    COUNT(DISTINCT e.recipient_id) AS unique_clicks
               FROM {campaign_links} cl
          LEFT JOIN {email_events} e
                 ON e.campaign_id = cl.campaign_id
                AND e.event_type = 'click'
                AND JSON_EXTRACT(e.metadata_json, '$.link_id') = cl.id
              WHERE cl.campaign_id = :id
           GROUP BY cl.id, cl.url
           ORDER BY total_clicks DESC
              LIMIT 50",
            ['id' => $campaignId]
        );

        return [
            'campaign'     => $campaign,
            'metrics'      => $metrics,
            'rates'        => self::rates($metrics),
            'denominators' => self::DENOMINATORS,
            'data_quality' => self::dataQualityNotes($metrics),
            'timeline'     => $timeline,
            'links'        => array_map(static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['total_clicks'] = (int) $row['total_clicks'];
                $row['unique_clicks'] = (int) $row['unique_clicks'];
                return $row;
            }, $topLinks),
            'tracking_note' => 'Opens and clicks are tracked engagement signals. Privacy proxies '
                . 'and security scanners can both inflate and suppress them.',
        ];
    }

    /** Individual recipient results for a campaign. */
    public static function campaignRecipients(int $campaignId, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $clauses = ['r.campaign_id = :campaign'];
        $params = ['campaign' => $campaignId];

        if (!empty($filters['status'])) {
            $clauses[] = 'r.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $clauses[] = 'r.recipient_email_snapshot LIKE :search';
            $params['search'] = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['search'])) . '%';
        }
        if (($filters['engagement'] ?? '') === 'opened') {
            $clauses[] = 'r.first_opened_at IS NOT NULL';
        } elseif (($filters['engagement'] ?? '') === 'clicked') {
            $clauses[] = 'r.first_clicked_at IS NOT NULL';
        }

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM {campaign_recipients} r WHERE {$where}", $params);

        $items = Database::all(
            "SELECT r.id, r.contact_id, r.recipient_email_snapshot, r.status, r.attempts,
                    r.ses_message_id, r.last_error_code, r.last_error_message,
                    r.accepted_at, r.delivered_at, r.bounced_at, r.complained_at,
                    r.first_opened_at, r.first_clicked_at, r.unsubscribed_at
               FROM {campaign_recipients} r
              WHERE {$where}
           ORDER BY r.id
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Dashboard aggregate for a date range (UTC bounds).
     *
     * @return array<string,mixed>
     */
    public static function dashboard(?string $from, ?string $to): array
    {
        $from ??= gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $to ??= gmdate('Y-m-d H:i:s');
        $range = ['from' => $from, 'to' => $to];

        $contacts = ContactService::counts();

        $campaignCounts = Database::first(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN status IN ('queued','sending') THEN 1 ELSE 0 END) AS sending,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) AS paused,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
               FROM {campaigns}"
        ) ?? [];

        $sending = Database::first(
            'SELECT
                SUM(CASE WHEN attempts > 0 THEN 1 ELSE 0 END) AS attempted,
                SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribes
               FROM {campaign_recipients}
              WHERE created_at BETWEEN :from AND :to',
            $range
        ) ?? [];

        // Events are counted for the SAME recipients the volumes above describe,
        // not for "events that happened in the window". Mixing the two produces
        // nonsense like a 200% open rate when opens arrive for messages sent
        // before the window started.
        $events = Database::first(
            "SELECT
                SUM(CASE WHEN e.event_type = 'bounce' AND e.event_subtype LIKE 'Permanent%' THEN 1 ELSE 0 END) AS hard_bounces,
                SUM(CASE WHEN e.event_type = 'bounce' AND e.event_subtype NOT LIKE 'Permanent%' THEN 1 ELSE 0 END) AS soft_bounces,
                SUM(CASE WHEN e.event_type = 'reject' THEN 1 ELSE 0 END) AS rejects,
                SUM(CASE WHEN e.event_type = 'complaint' THEN 1 ELSE 0 END) AS complaints,
                SUM(CASE WHEN e.event_type = 'open' THEN 1 ELSE 0 END) AS total_opens,
                SUM(CASE WHEN e.event_type = 'click' THEN 1 ELSE 0 END) AS total_clicks,
                COUNT(DISTINCT CASE WHEN e.event_type = 'open' THEN e.recipient_id END) AS unique_opens,
                COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.recipient_id END) AS unique_clicks
               FROM {email_events} e
               JOIN {campaign_recipients} r ON r.id = e.recipient_id
              WHERE r.created_at BETWEEN :from AND :to",
            $range
        ) ?? [];

        $metrics = [
            'attempted'     => (int) ($sending['attempted'] ?? 0),
            'accepted'      => (int) ($sending['accepted'] ?? 0),
            'delivered'     => (int) ($sending['delivered'] ?? 0),
            'unsubscribes'  => (int) ($sending['unsubscribes'] ?? 0),
            'hard_bounces'  => (int) ($events['hard_bounces'] ?? 0),
            'soft_bounces'  => (int) ($events['soft_bounces'] ?? 0),
            'complaints'    => (int) ($events['complaints'] ?? 0),
            'total_opens'   => (int) ($events['total_opens'] ?? 0),
            'total_clicks'  => (int) ($events['total_clicks'] ?? 0),
            'unique_opens'  => (int) ($events['unique_opens'] ?? 0),
            'unique_clicks' => (int) ($events['unique_clicks'] ?? 0),
            'rejects'       => (int) ($events['rejects'] ?? 0),
        ];

        $recentCampaigns = Database::all(
            "SELECT c.id, c.name, c.status, c.scheduled_at_utc, c.started_at, c.completed_at,
                    c.total_recipients, st.accepted, st.delivered, st.hard_bounces, st.complaints,
                    st.unique_opens, st.unique_clicks
               FROM {campaigns} c
          LEFT JOIN {campaign_stats} st ON st.campaign_id = c.id
           ORDER BY c.created_at DESC
              LIMIT 8"
        );

        $recentEvents = Database::all(
            "SELECT e.id, e.event_type, e.event_subtype, e.event_timestamp,
                    e.campaign_id, c.name AS campaign_name, r.recipient_email_snapshot
               FROM {email_events} e
          LEFT JOIN {campaigns} c ON c.id = e.campaign_id
          LEFT JOIN {campaign_recipients} r ON r.id = e.recipient_id
           ORDER BY e.id DESC
              LIMIT 15"
        );

        return [
            'range'    => $range,
            'contacts' => $contacts,
            'lists'    => ['total' => (int) Database::scalar('SELECT COUNT(*) FROM {lists}')],
            'campaigns' => [
                'total'     => (int) ($campaignCounts['total'] ?? 0),
                'draft'     => (int) ($campaignCounts['drafts'] ?? 0),
                'scheduled' => (int) ($campaignCounts['scheduled'] ?? 0),
                'sending'   => (int) ($campaignCounts['sending'] ?? 0),
                'paused'    => (int) ($campaignCounts['paused'] ?? 0),
                'completed' => (int) ($campaignCounts['completed'] ?? 0),
                'failed'    => (int) ($campaignCounts['failed'] ?? 0),
            ],
            'metrics'          => $metrics,
            'rates'            => self::rates($metrics),
            'denominators'     => self::DENOMINATORS,
            'data_quality'     => self::dataQualityNotes($metrics),
            'sending_status'   => self::sendingStatus(),
            'recent_campaigns' => array_map([CampaignService::class, 'present'], $recentCampaigns),
            'recent_events'    => $recentEvents,
            'alerts'           => AlertService::active(),
            'health'           => AlertService::health(),
        ];
    }

    /** Current operational sending state, for the dashboard header. */
    public static function sendingStatus(): array
    {
        $killSwitch = Settings::bool('kill_switch_engaged', false);
        $enabled = Settings::bool('sending_enabled', true);

        $inFlight = (int) Database::scalar(
            "SELECT COUNT(*) FROM {campaigns} WHERE status IN ('queued','sending')"
        );
        $queuedRecipients = (int) Database::scalar(
            "SELECT COUNT(*) FROM {campaign_recipients} WHERE status IN ('pending','sending')"
        );
        $sentToday = (int) (Database::scalar(
            "SELECT sent_count FROM {send_counters} WHERE bucket_type = 'day' AND bucket_key = :key",
            ['key' => gmdate('Ymd')]
        ) ?? 0);

        return [
            'sending_enabled'    => $enabled,
            'kill_switch'        => $killSwitch,
            'kill_switch_reason' => Settings::string('kill_switch_reason'),
            'can_send'           => $enabled && !$killSwitch,
            'campaigns_in_flight' => $inFlight,
            'queued_recipients'  => $queuedRecipients,
            'sent_today'         => $sentToday,
            'daily_cap'          => Settings::int('max_sends_per_day', 50000),
            'rate_per_minute'    => Settings::int('max_send_rate_per_minute', 120),
        ];
    }

    /**
     * Account-level deliverability over a rolling window.
     *
     * @return array<string,mixed>
     */
    public static function deliverability(?int $windowHours = null): array
    {
        $windowHours = $windowHours ?? max(1, Settings::int('alert_rolling_window_hours', 72));
        $since = gmdate('Y-m-d H:i:s', time() - $windowHours * 3600);

        $sending = Database::first(
            'SELECT
                SUM(CASE WHEN attempts > 0 THEN 1 ELSE 0 END) AS attempted,
                SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribes
               FROM {campaign_recipients}
              WHERE accepted_at >= :since OR (accepted_at IS NULL AND updated_at >= :since2)',
            ['since' => $since, 'since2' => $since]
        ) ?? [];

        // Same recipient set as the volumes above, so every rate divides two
        // numbers that describe the same messages.
        $events = Database::first(
            "SELECT
                SUM(CASE WHEN e.event_type = 'bounce' AND e.event_subtype LIKE 'Permanent%' THEN 1 ELSE 0 END) AS hard_bounces,
                SUM(CASE WHEN e.event_type = 'bounce' AND e.event_subtype NOT LIKE 'Permanent%' THEN 1 ELSE 0 END) AS soft_bounces,
                SUM(CASE WHEN e.event_type = 'complaint' THEN 1 ELSE 0 END) AS complaints,
                SUM(CASE WHEN e.event_type = 'reject' THEN 1 ELSE 0 END) AS rejects,
                COUNT(DISTINCT CASE WHEN e.event_type = 'open' THEN e.recipient_id END) AS unique_opens,
                COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.recipient_id END) AS unique_clicks,
                SUM(CASE WHEN e.event_type = 'open' THEN 1 ELSE 0 END) AS total_opens,
                SUM(CASE WHEN e.event_type = 'click' THEN 1 ELSE 0 END) AS total_clicks
               FROM {email_events} e
               JOIN {campaign_recipients} r ON r.id = e.recipient_id
              WHERE r.accepted_at >= :since OR (r.accepted_at IS NULL AND r.updated_at >= :since2)",
            ['since' => $since, 'since2' => $since]
        ) ?? [];

        $metrics = [
            'attempted'     => (int) ($sending['attempted'] ?? 0),
            'accepted'      => (int) ($sending['accepted'] ?? 0),
            'delivered'     => (int) ($sending['delivered'] ?? 0),
            'unsubscribes'  => (int) ($sending['unsubscribes'] ?? 0),
            'hard_bounces'  => (int) ($events['hard_bounces'] ?? 0),
            'soft_bounces'  => (int) ($events['soft_bounces'] ?? 0),
            'complaints'    => (int) ($events['complaints'] ?? 0),
            'rejects'       => (int) ($events['rejects'] ?? 0),
            'unique_opens'  => (int) ($events['unique_opens'] ?? 0),
            'unique_clicks' => (int) ($events['unique_clicks'] ?? 0),
            'total_opens'   => (int) ($events['total_opens'] ?? 0),
            'total_clicks'  => (int) ($events['total_clicks'] ?? 0),
        ];

        $report = [
            'window_hours'  => $windowHours,
            'since'         => $since,
            'metrics'       => $metrics,
            'rates'         => self::rates($metrics),
            'denominators'  => self::DENOMINATORS,
            'data_quality'  => self::dataQualityNotes($metrics),
            'thresholds'    => AlertService::thresholds(),
            'sample_size'   => $metrics['attempted'],
            'min_sample'    => Settings::int('alert_min_sample_size', 200),
            'alerts'        => AlertService::active(),
            'provider'      => AlertService::providerSnapshot(),
        ];

        // health() needs a deliverability report; hand it this one rather than
        // letting it compute a second (and recurse back into this method).
        $report['health'] = AlertService::health($report);

        return $report;
    }

    /** Refreshes stats for campaigns touched recently (cron). */
    public static function refreshRecentCampaigns(int $limit = 25): int
    {
        $ids = Database::all(
            "SELECT DISTINCT c.id
               FROM {campaigns} c
          LEFT JOIN {campaign_stats} s ON s.campaign_id = c.id
              WHERE c.status IN ('queued','sending','paused')
                 OR s.campaign_id IS NULL
                 OR s.refreshed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
              ORDER BY c.updated_at DESC
              LIMIT " . max(1, min(100, $limit))
        );

        foreach ($ids as $row) {
            self::refreshCampaignStats((int) $row['id']);
        }

        return count($ids);
    }
}
