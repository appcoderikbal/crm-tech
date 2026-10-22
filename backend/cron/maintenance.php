<?php

declare(strict_types=1);

/**
 * Housekeeping: expired import files, old throttling rows, stale locks and
 * log rotation. Run once an hour.
 *
 * Shared hosting has finite disk and inode budgets, so this job is not
 * optional — without it, import uploads and rate-limit rows accumulate forever.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\CronRunner;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Services\ImportService;

$runner = CronRunner::start('maintenance');

exit($runner->run(static function (CronRunner $runner): array {
    $summary = [];

    // --- temporary import files ---------------------------------------------
    $summary['imports'] = ImportService::cleanupExpiredFiles();

    // --- throttling and counters --------------------------------------------
    $summary['login_attempts_pruned'] = Database::execute(
        'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)'
    );
    $summary['rate_limits_pruned'] = Database::execute(
        'DELETE FROM rate_limit_counters WHERE window_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
    );
    $summary['send_counters_pruned'] = Database::execute(
        "DELETE FROM send_counters
          WHERE bucket_type = 'minute' AND bucket_key < DATE_FORMAT(DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY), '%Y%m%d%H%i')"
    );

    // --- expired locks -------------------------------------------------------
    $summary['locks_released'] = Database::execute(
        'DELETE FROM processing_locks WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
    );

    // --- processed notifications ---------------------------------------------
    // Raw payloads are only needed until they are parsed; the derived events
    // are kept forever.
    $summary['notifications_pruned'] = Database::execute(
        "DELETE FROM provider_notifications
          WHERE status = 'processed' AND processed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
          LIMIT 5000"
    );

    // --- audit log retention --------------------------------------------------
    $summary['audit_pruned'] = Database::execute(
        'DELETE FROM audit_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY) LIMIT 5000'
    );

    // --- log rotation ----------------------------------------------------------
    $logDirectory = Config::load()->string('storage.logs');
    $removed = 0;
    foreach (glob($logDirectory . '/*.log*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 30 * 86400 && @unlink($file)) {
            $removed++;
        }
    }
    $summary['log_files_removed'] = $removed;

    return $summary;
}));
