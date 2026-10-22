<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use TechzappCrm\Services\AlertService;
use TechzappCrm\Services\CampaignSender;
use TechzappCrm\Services\EventService;
use TechzappCrm\Services\ImportService;
use TechzappCrm\Services\ReportService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the background jobs actually do.
 *
 * Separated from scheduling so WP-Cron, WP-CLI and the "run now" buttons on
 * the Settings screen all execute exactly the same code.
 *
 * Nothing here assumes it will finish. A 40,000-recipient campaign is simply a
 * campaign that takes many runs.
 */
final class Jobs
{
    /** @return array<string,mixed> */
    public static function processCampaigns(Runner $runner): array
    {
        $summary = [
            'queued_campaigns' => 0,
            'processed'        => [],
            'sent'             => 0,
            'failed'           => 0,
            'suppressed'       => 0,
            'uncertain'        => 0,
            'reconciled'       => [],
            'skipped'          => '',
        ];

        if (!Settings::sendingAllowed()) {
            // The kill switch stops sending but not housekeeping: stale rows
            // are still reconciled so the operator sees an accurate picture.
            $summary['skipped'] = Settings::bool('kill_switch_engaged')
                ? 'kill switch engaged'
                : 'sending disabled';
            $summary['reconciled'] = CampaignSender::reconcileStale();

            return $summary;
        }

        $summary['queued_campaigns'] = count(CampaignSender::queueDueCampaigns());

        foreach (CampaignSender::campaignsNeedingWork(10) as $campaignId) {
            if ($runner->expired()) {
                break;
            }
            $runner->heartbeat();

            // Leave a couple of seconds so the run can finish tidily.
            $result = CampaignSender::processCampaign($campaignId, $runner->deadline() - 2);

            $summary['sent'] += $result['sent'];
            $summary['failed'] += $result['failed'];
            $summary['suppressed'] += $result['suppressed'];
            $summary['uncertain'] += $result['uncertain'];
            $summary['processed'][] = [
                'id'     => $campaignId,
                'sent'   => $result['sent'],
                'status' => $result['status'],
                'reason' => $result['reason'],
            ];
        }

        if (!$runner->expired()) {
            $summary['reconciled'] = CampaignSender::reconcileStale();
        }
        if (!$runner->expired()) {
            ReportService::refreshRecentCampaigns(10);
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    public static function processEvents(Runner $runner): array
    {
        // Leave room for the aggregate refresh and alert evaluation below.
        $events = EventService::processPending(500, $runner->deadline() - 10);

        $refreshed = 0;
        if (!$runner->expired()) {
            $runner->heartbeat();
            $refreshed = ReportService::refreshRecentCampaigns(25);
        }

        $alerts = ['checks' => 0, 'raised' => 0, 'resolved' => 0];
        if (!$runner->expired()) {
            $alerts = AlertService::evaluateAll();
        }

        return [
            'events'              => $events,
            'campaigns_refreshed' => $refreshed,
            'alerts'              => $alerts,
        ];
    }

    /** @return array<string,mixed> */
    public static function processImports(Runner $runner): array
    {
        $processed = [];

        foreach (ImportService::pendingJobs(5) as $jobId) {
            if ($runner->expired()) {
                break;
            }
            $runner->heartbeat();

            $job = ImportService::processChunk($jobId, $runner->deadline() - 2);

            $processed[] = [
                'id'        => $jobId,
                'status'    => $job['status'] ?? 'unknown',
                'processed' => $job['processed_rows'] ?? 0,
                'total'     => $job['total_rows'] ?? 0,
            ];
        }

        return ['jobs' => $processed];
    }

    /**
     * Housekeeping. Shared hosting has finite disk and inode budgets, so this
     * is not optional — without it uploads and counters accumulate forever.
     *
     * @return array<string,mixed>
     */
    public static function maintenance(Runner $runner): array
    {
        $summary = [];

        $summary['imports'] = ImportService::cleanupExpiredFiles();

        $summary['rate_limits_pruned'] = Database::execute(
            'DELETE FROM {rate_limit_counters} WHERE window_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
        );

        $summary['send_counters_pruned'] = Database::execute(
            "DELETE FROM {send_counters}
              WHERE bucket_type = 'minute'
                AND bucket_key < DATE_FORMAT(DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY), '%Y%m%d%H%i')"
        );

        $summary['locks_released'] = Database::execute(
            'DELETE FROM {processing_locks} WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
        );

        // Raw payloads are only needed until they are parsed; the derived
        // events are kept indefinitely because the reports depend on them.
        $summary['notifications_pruned'] = Database::execute(
            "DELETE FROM {provider_notifications}
              WHERE status = 'processed'
                AND processed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
              LIMIT 5000"
        );

        $summary['audit_pruned'] = Database::execute(
            'DELETE FROM {audit_logs} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY) LIMIT 5000'
        );

        $summary['log_files_removed'] = self::rotateLogs();

        return $summary;
    }

    private static function rotateLogs(): int
    {
        $removed = 0;

        foreach (Logger::files() as $file) {
            if ($file['modified'] < time() - 30 * DAY_IN_SECONDS && @unlink($file['path'])) {
                $removed++;
            }
        }

        // Rotated archives (name.log.1730000000) are cleaned up too.
        foreach (glob(Logger::directory() . '/*.log.*') ?: [] as $path) {
            if (filemtime($path) < time() - 30 * DAY_IN_SECONDS && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
