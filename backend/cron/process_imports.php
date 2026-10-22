<?php

declare(strict_types=1);

/**
 * Contact import worker.
 *
 * Picks up queued CSV imports and processes them in bounded chunks. The browser
 * can drive the same work through POST /imports/{id}/process; this job is what
 * makes a large import finish even if the user closes the tab.
 *
 * Run every minute (or every five if imports are rare).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Techzapp\Crm\Core\CronRunner;
use Techzapp\Crm\Services\ImportService;

$runner = CronRunner::start('process_imports');

exit($runner->run(static function (CronRunner $runner): array {
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
}));
