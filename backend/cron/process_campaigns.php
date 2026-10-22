<?php

declare(strict_types=1);

/**
 * Campaign scheduler and sender.
 *
 * Run this every minute. Each run:
 *   1. queues scheduled campaigns that are now due,
 *   2. processes a bounded batch of recipients for each active campaign,
 *   3. stops when its time budget is spent and leaves the rest for the next run.
 *
 * Nothing here assumes it will finish. A 40,000-recipient campaign is simply a
 * campaign that takes many runs.
 *
 * Hostinger cron (replace the paths with your own — see docs/DEPLOYMENT.md):
 *   * * * * * /usr/bin/php8.2 /home/uXXXXXXX/domains/example.com/private/backend/cron/process_campaigns.php >> /home/uXXXXXXX/domains/example.com/private/backend/storage/logs/cron.log 2>&1
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Techzapp\Crm\Core\CronRunner;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Services\CampaignSender;
use Techzapp\Crm\Services\ReportService;

$runner = CronRunner::start('process_campaigns');

exit($runner->run(static function (CronRunner $runner): array {
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
        // The kill switch stops sending but not housekeeping: stale rows are
        // still reconciled so the operator sees an accurate picture.
        $summary['skipped'] = Settings::bool('kill_switch_engaged')
            ? 'kill switch engaged'
            : 'sending disabled';
        $summary['reconciled'] = CampaignSender::reconcileStale();
        return $summary;
    }

    $queued = CampaignSender::queueDueCampaigns();
    $summary['queued_campaigns'] = count($queued);

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

    // Reconciliation is cheap; run it whenever there is time left.
    if (!$runner->expired()) {
        $summary['reconciled'] = CampaignSender::reconcileStale();
    }
    if (!$runner->expired()) {
        ReportService::refreshRecentCampaigns(10);
    }

    return $summary;
}));
