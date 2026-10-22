<?php

declare(strict_types=1);

/**
 * Provider event processor and deliverability monitor.
 *
 * Parses queued SNS notifications into email_events, refreshes report
 * aggregates and re-evaluates every alert rule.
 *
 * Run every five minutes. See docs/DEPLOYMENT.md for the exact cron line —
 * the schedule field is a slash-5 interval, which cannot be written inside a
 * PHP block comment without ending it.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Techzapp\Crm\Core\CronRunner;
use Techzapp\Crm\Services\AlertService;
use Techzapp\Crm\Services\EventService;
use Techzapp\Crm\Services\ReportService;

$runner = CronRunner::start('process_events');

exit($runner->run(static function (CronRunner $runner): array {
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
        'events'             => $events,
        'campaigns_refreshed' => $refreshed,
        'alerts'             => $alerts,
    ];
}));
