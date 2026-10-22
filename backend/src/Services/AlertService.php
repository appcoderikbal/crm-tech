<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Logger;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Services\Mail\Mailer;
use Throwable;

/**
 * Deliverability monitoring.
 *
 * Two rules shape this file:
 *   - An unknown metric is never reported as healthy. If the monitoring data is
 *     missing or stale the status is "unknown", which the UI shows in grey.
 *   - A complaint spike does not wait for the top threshold: it pauses sending
 *     straight away, because the cost of over-reacting is a delayed campaign
 *     and the cost of under-reacting is the sending domain's reputation.
 */
final class AlertService
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    /** @return array<string,float> */
    public static function thresholds(): array
    {
        return [
            'bounce_warning'     => Settings::float('bounce_rate_warning_pct', 2.0),
            'bounce_critical'    => Settings::float('bounce_rate_critical_pct', 5.0),
            'complaint_warning'  => Settings::float('complaint_rate_warning_pct', 0.05),
            'complaint_critical' => Settings::float('complaint_rate_critical_pct', 0.1),
        ];
    }

    /** Opens or refreshes an alert. One open row per alert_key. */
    public static function raise(
        string $key,
        string $category,
        string $severity,
        string $title,
        string $message,
        array $metrics = [],
        ?int $campaignId = null
    ): void {
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'warning';
        }

        try {
            Database::execute(
                "INSERT INTO alerts (alert_key, category, severity, status, title, message, metrics_json, campaign_id)
                 VALUES (:key, :category, :severity, 'active', :title, :message, :metrics, :campaign)
                 ON DUPLICATE KEY UPDATE
                    severity = VALUES(severity),
                    title = VALUES(title),
                    message = VALUES(message),
                    metrics_json = VALUES(metrics_json),
                    last_seen_at = UTC_TIMESTAMP()",
                [
                    'key'      => substr($key, 0, 190),
                    'category' => substr($category, 0, 60),
                    'severity' => $severity,
                    'title'    => substr($title, 0, 190),
                    'message'  => substr($message, 0, 800),
                    'metrics'  => $metrics === [] ? null : json_encode($metrics, JSON_UNESCAPED_SLASHES),
                    'campaign' => $campaignId,
                ]
            );
        } catch (Throwable $e) {
            Logger::exception('alerts.raise_failed', $e, ['key' => $key]);
        }
    }

    public static function resolve(string $key, string $note = 'condition cleared'): void
    {
        try {
            Database::execute(
                "UPDATE alerts
                    SET status = 'resolved', resolved_at = UTC_TIMESTAMP(),
                        message = CONCAT(LEFT(message, 600), ' — ', :note)
                  WHERE alert_key = :key AND status IN ('active','acknowledged')",
                ['key' => $key, 'note' => substr($note, 0, 180)]
            );
        } catch (Throwable $e) {
            Logger::exception('alerts.resolve_failed', $e, ['key' => $key]);
        }
    }

    public static function acknowledge(int $alertId, int $userId): bool
    {
        return Database::execute(
            "UPDATE alerts
                SET status = 'acknowledged', acknowledged_at = UTC_TIMESTAMP(), acknowledged_by = :user
              WHERE id = :id AND status = 'active'",
            ['id' => $alertId, 'user' => $userId]
        ) > 0;
    }

    /** @return list<array<string,mixed>> */
    public static function active(int $limit = 50): array
    {
        $rows = Database::all(
            "SELECT id, alert_key, category, severity, status, title, message, metrics_json,
                    campaign_id, first_seen_at, last_seen_at
               FROM alerts
              WHERE status IN ('active','acknowledged')
           ORDER BY FIELD(severity,'critical','warning','info'), last_seen_at DESC
              LIMIT " . max(1, min(200, $limit))
        );

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['metrics'] = $row['metrics_json'] === null ? [] : (json_decode((string) $row['metrics_json'], true) ?: []);
            unset($row['metrics_json']);
            return $row;
        }, $rows);
    }

    /** Count of unresolved critical alerts — drives the red dot in the sidebar. */
    public static function criticalCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE status = 'active' AND severity = 'critical'"
        );
    }

    public static function unacknowledgedCount(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM alerts WHERE status = 'active'");
    }

    /**
     * Runs every monitoring check. Called by cron and by the deliverability
     * endpoint so the UI is never looking at a stale verdict.
     *
     * @return array{checks:int, raised:int, resolved:int}
     */
    public static function evaluateAll(): array
    {
        $raised = 0;
        $resolved = 0;

        foreach ([
            'evaluateRates',
            'evaluateEventProcessing',
            'evaluateProviderStatus',
            'evaluateCampaignFailures',
            'evaluateOverdueCampaigns',
            'evaluateCronHealth',
        ] as $check) {
            try {
                $outcome = self::{$check}();
                $raised += $outcome[0];
                $resolved += $outcome[1];
            } catch (Throwable $e) {
                Logger::exception('alerts.check_failed', $e, ['check' => $check]);
            }
        }

        return ['checks' => 6, 'raised' => $raised, 'resolved' => $resolved];
    }

    /** @return array{0:int,1:int} */
    private static function evaluateRates(): array
    {
        $report = ReportService::deliverability();
        $metrics = $report['metrics'];
        $rates = $report['rates'];
        $thresholds = self::thresholds();
        $minSample = Settings::int('alert_min_sample_size', 200);

        $raised = $resolved = 0;

        // Below the minimum sample a single bounce is 100%; refuse to judge.
        if ($metrics['attempted'] < $minSample) {
            self::resolve('bounce_rate', 'sample size below threshold');
            self::resolve('complaint_rate', 'sample size below threshold');
            return [0, 0];
        }

        $bounceRate = $rates['bounce_rate'];
        if ($bounceRate !== null && $bounceRate >= $thresholds['bounce_critical']) {
            self::raise(
                'bounce_rate',
                'deliverability',
                'critical',
                'Bounce rate is critical',
                sprintf(
                    'Bounce rate is %.2f%% of %d attempted recipients over the last %d hours (critical threshold %.2f%%).',
                    $bounceRate,
                    $metrics['attempted'],
                    $report['window_hours'],
                    $thresholds['bounce_critical']
                ),
                ['bounce_rate' => $bounceRate] + $metrics
            );
            $raised++;
        } elseif ($bounceRate !== null && $bounceRate >= $thresholds['bounce_warning']) {
            self::raise(
                'bounce_rate',
                'deliverability',
                'warning',
                'Bounce rate is elevated',
                sprintf(
                    'Bounce rate is %.2f%% of %d attempted recipients (warning threshold %.2f%%).',
                    $bounceRate,
                    $metrics['attempted'],
                    $thresholds['bounce_warning']
                ),
                ['bounce_rate' => $bounceRate] + $metrics
            );
            $raised++;
        } else {
            self::resolve('bounce_rate');
            $resolved++;
        }

        $complaintRate = $rates['complaint_rate'];
        if ($complaintRate !== null && $complaintRate >= $thresholds['complaint_warning']) {
            $critical = $complaintRate >= $thresholds['complaint_critical'];

            self::raise(
                'complaint_rate',
                'deliverability',
                $critical ? 'critical' : 'warning',
                $critical ? 'Spam complaint rate is critical' : 'Spam complaint rate is elevated',
                sprintf(
                    'Complaint rate is %.3f%% of %d accepted messages (warning %.3f%%, critical %.3f%%).',
                    $complaintRate,
                    $metrics['accepted'],
                    $thresholds['complaint_warning'],
                    $thresholds['complaint_critical']
                ),
                ['complaint_rate' => $complaintRate] + $metrics
            );
            $raised++;

            // Complaints are the signal that gets a sending domain blocked.
            // Pause at the WARNING level, not at critical.
            if (Settings::bool('complaint_spike_auto_pause', true) && !Settings::bool('kill_switch_engaged', false)) {
                Settings::set('kill_switch_engaged', true);
                Settings::set(
                    'kill_switch_reason',
                    sprintf('Automatically paused: complaint rate %.3f%% reached the warning threshold.', $complaintRate)
                );
                Audit::log('sending.auto_paused', 'system', 'kill_switch', [
                    'complaint_rate' => $complaintRate,
                    'accepted'       => $metrics['accepted'],
                ]);
                self::raise(
                    'auto_pause_complaints',
                    'sending',
                    'critical',
                    'Sending paused automatically',
                    sprintf('Sending was paused because the complaint rate reached %.3f%%. Review before releasing the kill switch.', $complaintRate),
                    ['complaint_rate' => $complaintRate]
                );
            }
        } else {
            self::resolve('complaint_rate');
            $resolved++;
        }

        return [$raised, $resolved];
    }

    /** @return array{0:int,1:int} */
    private static function evaluateEventProcessing(): array
    {
        $row = Database::first(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                MIN(CASE WHEN status = 'pending' THEN received_at END) AS oldest_pending
               FROM provider_notifications"
        ) ?? [];

        $pending = (int) ($row['pending'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);
        $oldest = $row['oldest_pending'] ?? null;
        $ageMinutes = is_string($oldest) ? (int) ((time() - strtotime($oldest . ' UTC')) / 60) : 0;

        if ($failed > 0) {
            self::raise(
                'event_processing_failed',
                'events',
                'critical',
                'Event processing is failing',
                "{$failed} provider notification(s) could not be processed after repeated attempts. "
                . 'Delivery, bounce and complaint reports are incomplete until this is resolved.',
                ['failed' => $failed, 'pending' => $pending]
            );
            return [1, 0];
        }

        if ($pending > 0 && $ageMinutes > 30) {
            self::raise(
                'event_processing_delayed',
                'events',
                'warning',
                'Event processing is delayed',
                "{$pending} notification(s) have been waiting {$ageMinutes} minutes. Check that the event cron job is running.",
                ['pending' => $pending, 'age_minutes' => $ageMinutes]
            );
            return [1, 0];
        }

        self::resolve('event_processing_failed');
        self::resolve('event_processing_delayed');
        return [0, 1];
    }

    /** @return array{0:int,1:int} */
    private static function evaluateProviderStatus(): array
    {
        $status = self::providerSnapshot();

        if ($status === null) {
            self::raise(
                'ses_status_unknown',
                'provider',
                'warning',
                'Amazon SES status unavailable',
                'The SES account status could not be read. Sending limits and the account enforcement state are unknown.',
                []
            );
            return [1, 0];
        }
        self::resolve('ses_status_unknown');

        if (!$status['sending_enabled'] || strtoupper($status['enforcement_status']) === 'SHUTDOWN') {
            self::raise(
                'ses_sending_disabled',
                'provider',
                'critical',
                'Amazon SES sending is disabled',
                'SES reports that sending is disabled or the account is under enforcement (' . $status['enforcement_status'] . ').',
                $status
            );
            return [1, 0];
        }
        self::resolve('ses_sending_disabled');

        if ($status['max_24_hour'] > 0 && $status['sent_last_24_hours'] >= $status['max_24_hour'] * 0.9) {
            self::raise(
                'ses_quota_near_limit',
                'provider',
                'warning',
                'Approaching the SES 24-hour quota',
                sprintf(
                    '%d of %d messages used in the last 24 hours.',
                    (int) $status['sent_last_24_hours'],
                    (int) $status['max_24_hour']
                ),
                $status
            );
            return [1, 0];
        }
        self::resolve('ses_quota_near_limit');

        return [0, 1];
    }

    /** @return array{0:int,1:int} */
    private static function evaluateCampaignFailures(): array
    {
        $rows = Database::all(
            "SELECT id, name, consecutive_failures, last_error
               FROM campaigns
              WHERE consecutive_failures >= 3 AND status IN ('queued','sending','paused','failed')
              LIMIT 20"
        );

        foreach ($rows as $row) {
            self::raise(
                'campaign_failing:' . $row['id'],
                'campaign',
                'critical',
                'Campaign is failing repeatedly',
                "\"{$row['name']}\" has failed {$row['consecutive_failures']} consecutive processing attempts. "
                . 'Last error: ' . (string) $row['last_error'],
                ['consecutive_failures' => (int) $row['consecutive_failures']],
                (int) $row['id']
            );
        }

        return [count($rows), 0];
    }

    /** @return array{0:int,1:int} */
    private static function evaluateOverdueCampaigns(): array
    {
        $overdueMinutes = max(1, Settings::int('campaign_overdue_minutes', 20));

        $rows = Database::all(
            "SELECT id, name, scheduled_at_utc
               FROM campaigns
              WHERE status = 'scheduled'
                AND scheduled_at_utc IS NOT NULL
                AND scheduled_at_utc < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)
              LIMIT 20",
            ['minutes' => $overdueMinutes]
        );

        foreach ($rows as $row) {
            self::raise(
                'campaign_overdue:' . $row['id'],
                'scheduling',
                'critical',
                'Scheduled campaign is overdue',
                "\"{$row['name']}\" was due at {$row['scheduled_at_utc']} UTC and has not started. "
                . 'This usually means the campaign cron job is not running.',
                ['scheduled_at_utc' => $row['scheduled_at_utc']],
                (int) $row['id']
            );
        }

        if ($rows === []) {
            Database::execute(
                "UPDATE alerts SET status = 'resolved', resolved_at = UTC_TIMESTAMP()
                  WHERE category = 'scheduling' AND status = 'active'"
            );
        }

        return [count($rows), 0];
    }

    /** @return array{0:int,1:int} */
    private static function evaluateCronHealth(): array
    {
        $staleMinutes = max(5, Settings::int('cron_stale_minutes', 30));
        $raised = 0;

        $expected = ['process_campaigns', 'process_events'];
        foreach ($expected as $job) {
            $row = Database::first(
                'SELECT last_run_at, last_status, last_message FROM cron_heartbeats WHERE job_name = :job',
                ['job' => $job]
            );

            if ($row === null) {
                self::raise(
                    'cron_missing:' . $job,
                    'cron',
                    'critical',
                    'Cron job has never run',
                    "The \"{$job}\" cron job has no recorded run. Scheduled campaigns and event processing will not happen until it is configured.",
                    ['job' => $job]
                );
                $raised++;
                continue;
            }

            $ageMinutes = (int) ((time() - strtotime((string) $row['last_run_at'] . ' UTC')) / 60);
            if ($ageMinutes > $staleMinutes) {
                self::raise(
                    'cron_stale:' . $job,
                    'cron',
                    'critical',
                    'Cron job is not running',
                    "The \"{$job}\" cron job last ran {$ageMinutes} minutes ago (threshold {$staleMinutes}).",
                    ['job' => $job, 'age_minutes' => $ageMinutes]
                );
                $raised++;
            } else {
                self::resolve('cron_stale:' . $job);
                self::resolve('cron_missing:' . $job);
            }

            if (($row['last_status'] ?? 'ok') === 'error') {
                self::raise(
                    'cron_error:' . $job,
                    'cron',
                    'warning',
                    'Cron job reported an error',
                    "The \"{$job}\" job failed on its last run: " . (string) $row['last_message'],
                    ['job' => $job]
                );
                $raised++;
            } else {
                self::resolve('cron_error:' . $job);
            }
        }

        return [$raised, 0];
    }

    /** @return array<string,mixed>|null */
    public static function providerSnapshot(): ?array
    {
        try {
            return Mailer::transport()->accountStatus();
        } catch (Throwable $e) {
            Logger::exception('alerts.provider_snapshot_failed', $e);
            return null;
        }
    }

    /**
     * Overall health per area: green | amber | red | unknown.
     *
     * @param array<string,mixed>|null $report a deliverability report to reuse.
     *        ReportService passes its own so the two do not call each other.
     * @return array<string,array{status:string, detail:string}>
     */
    public static function health(?array $report = null): array
    {
        $health = [];

        // --- deliverability -------------------------------------------------
        $report ??= ReportService::deliverability();
        $minSample = Settings::int('alert_min_sample_size', 200);
        $thresholds = self::thresholds();

        if ($report['metrics']['attempted'] < $minSample) {
            $health['deliverability'] = [
                'status' => 'unknown',
                'detail' => sprintf(
                    'Only %d attempted recipients in the window — below the %d minimum needed for a meaningful rate.',
                    $report['metrics']['attempted'],
                    $minSample
                ),
            ];
        } else {
            $bounce = $report['rates']['bounce_rate'];
            $complaint = $report['rates']['complaint_rate'];

            if ($bounce === null || $complaint === null) {
                $status = 'unknown';
                $detail = 'Bounce or complaint rate could not be calculated.';
            } elseif ($bounce >= $thresholds['bounce_critical'] || $complaint >= $thresholds['complaint_critical']) {
                $status = 'red';
                $detail = sprintf('Bounce %.2f%%, complaints %.3f%% — at or above critical thresholds.', $bounce, $complaint);
            } elseif ($bounce >= $thresholds['bounce_warning'] || $complaint >= $thresholds['complaint_warning']) {
                $status = 'amber';
                $detail = sprintf('Bounce %.2f%%, complaints %.3f%% — above warning thresholds.', $bounce, $complaint);
            } else {
                $status = 'green';
                $detail = sprintf('Bounce %.2f%%, complaints %.3f%%.', $bounce, $complaint);
            }
            $health['deliverability'] = ['status' => $status, 'detail' => $detail];
        }

        // --- provider -------------------------------------------------------
        $provider = self::providerSnapshot();
        if ($provider === null) {
            $health['provider'] = ['status' => 'unknown', 'detail' => 'SES account status could not be read.'];
        } elseif (!$provider['sending_enabled']) {
            $health['provider'] = ['status' => 'red', 'detail' => 'SES reports sending is disabled for this account.'];
        } elseif (strtoupper((string) $provider['enforcement_status']) !== 'HEALTHY'
            && strtoupper((string) $provider['enforcement_status']) !== 'LOG_TRANSPORT') {
            $health['provider'] = [
                'status' => 'amber',
                'detail' => 'SES enforcement status: ' . $provider['enforcement_status'] . '.',
            ];
        } else {
            $health['provider'] = [
                'status' => 'green',
                'detail' => sprintf(
                    '%d of %d messages used in the last 24 hours.',
                    (int) $provider['sent_last_24_hours'],
                    (int) $provider['max_24_hour']
                ),
            ];
        }

        // --- event pipeline -------------------------------------------------
        $events = Database::first(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                MAX(processed_at) AS last_processed
               FROM provider_notifications"
        ) ?? [];

        $failed = (int) ($events['failed'] ?? 0);
        $pending = (int) ($events['pending'] ?? 0);
        if ($failed > 0) {
            $health['events'] = ['status' => 'red', 'detail' => "{$failed} notification(s) failed to process."];
        } elseif ($pending > 50) {
            $health['events'] = ['status' => 'amber', 'detail' => "{$pending} notification(s) waiting to be processed."];
        } else {
            $health['events'] = ['status' => 'green', 'detail' => "{$pending} notification(s) in the queue."];
        }

        // --- cron -----------------------------------------------------------
        $staleMinutes = max(5, Settings::int('cron_stale_minutes', 30));
        $heartbeats = Database::all('SELECT job_name, last_run_at, last_status FROM cron_heartbeats');

        if ($heartbeats === []) {
            $health['cron'] = ['status' => 'unknown', 'detail' => 'No cron job has reported in yet.'];
        } else {
            $stale = [];
            foreach ($heartbeats as $beat) {
                $age = (int) ((time() - strtotime((string) $beat['last_run_at'] . ' UTC')) / 60);
                if ($age > $staleMinutes) {
                    $stale[] = $beat['job_name'] . " ({$age}m ago)";
                }
            }
            $health['cron'] = $stale === []
                ? ['status' => 'green', 'detail' => count($heartbeats) . ' job(s) reporting normally.']
                : ['status' => 'red', 'detail' => 'Stale: ' . implode(', ', $stale) . '.'];
        }

        // --- sending switch -------------------------------------------------
        $health['sending'] = Settings::sendingAllowed()
            ? ['status' => 'green', 'detail' => 'Sending is enabled.']
            : ['status' => 'red', 'detail' => Settings::string('kill_switch_reason', 'Sending is disabled.')];

        return $health;
    }
}
