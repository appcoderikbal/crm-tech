<?php

declare(strict_types=1);

/**
 * Concurrency probe: assigns a set of contacts to a list from a separate OS
 * process, so several of these racing each other exercise the real database
 * constraints rather than a simulated race.
 *
 * Usage: php assign_worker.php <listId> <contactId,contactId,...> [startAtUnixMillis]
 */

require __DIR__ . '/../bootstrap.php';

use Techzapp\Crm\Services\ListService;

$listId = (int) ($argv[1] ?? 0);
$contactIds = array_map('intval', array_filter(explode(',', (string) ($argv[2] ?? ''))));
$startAt = (float) ($argv[3] ?? 0);

// Spin until the agreed start time so the workers collide instead of queueing.
if ($startAt > 0) {
    while (microtime(true) * 1000 < $startAt) {
        usleep(200);
    }
}

try {
    $result = ListService::assign($listId, $contactIds, null);
    echo json_encode(['ok' => true, 'list_id' => $listId] + $result);
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'list_id' => $listId, 'error' => $e->getMessage()]);
    exit(1);
}
