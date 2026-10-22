<?php

declare(strict_types=1);

/**
 * Concurrency probe: runs the campaign sender from a separate OS process.
 *
 * Several of these launched at once reproduce what happens when a cron job
 * overruns and the next invocation starts while the previous one is still
 * working — the exact situation that must never send a recipient twice.
 *
 * Usage: php send_worker.php <campaignId> <runtimeSeconds> [startAtUnixMillis]
 */

require __DIR__ . '/../bootstrap.php';

use Techzapp\Crm\Services\CampaignSender;

$campaignId = (int) ($argv[1] ?? 0);
$runtime = (float) ($argv[2] ?? 10);
$startAt = (float) ($argv[3] ?? 0);

if ($startAt > 0) {
    while (microtime(true) * 1000 < $startAt) {
        usleep(200);
    }
}

try {
    $result = CampaignSender::processCampaign($campaignId, microtime(true) + $runtime);
    echo json_encode(['ok' => true] + $result);
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
}
