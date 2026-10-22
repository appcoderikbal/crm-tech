<?php

declare(strict_types=1);

namespace TechzappCrm\Cli;

use TechzappCrm\Core\Cron;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Installer;
use TechzappCrm\Core\Jobs;
use TechzappCrm\Core\Runner;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\AlertService;
use TechzappCrm\Services\ReportService;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI commands.
 *
 * These exist so a real system cron can drive the sending pipeline. WP-Cron
 * only fires when someone visits the site, which is fine for a busy site and
 * useless for a quiet one at 3am:
 *
 *   * * * * * cd /path/to/wp && wp techzapp-crm run process_campaigns --quiet
 */
final class Commands
{
    /**
     * Runs a background job once.
     *
     * ## OPTIONS
     *
     * <job>
     * : Which job to run.
     * ---
     * options:
     *   - process_campaigns
     *   - process_events
     *   - process_imports
     *   - maintenance
     * ---
     *
     * ## EXAMPLES
     *
     *     wp techzapp-crm run process_campaigns
     *     wp techzapp-crm run maintenance --quiet
     *
     * @when after_wp_load
     */
    public function run(array $args, array $assoc): void
    {
        $job = (string) ($args[0] ?? '');

        if (!array_key_exists($job, Cron::JOBS)) {
            WP_CLI::error(sprintf('Unknown job "%s". Expected one of: %s', $job, implode(', ', array_keys(Cron::JOBS))));
        }

        $summary = Cron::run($job, static fn (Runner $runner): array => match ($job) {
            'process_campaigns' => Jobs::processCampaigns($runner),
            'process_events'    => Jobs::processEvents($runner),
            'process_imports'   => Jobs::processImports($runner),
            default             => Jobs::maintenance($runner),
        });

        if (!isset($assoc['quiet'])) {
            WP_CLI::log((string) wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        WP_CLI::success(sprintf('%s finished.', $job));
    }

    /**
     * Shows the current operational state.
     *
     * ## EXAMPLES
     *
     *     wp techzapp-crm status
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assoc): void
    {
        $missing = Installer::missingTables();
        if ($missing !== []) {
            WP_CLI::warning('Missing tables: ' . implode(', ', $missing));
        }

        $sending = ReportService::sendingStatus();

        WP_CLI::log('Sending allowed: ' . ($sending['can_send'] ? 'yes' : 'no'));
        if (!$sending['can_send'] && $sending['kill_switch']) {
            WP_CLI::log('  Kill switch: ' . $sending['kill_switch_reason']);
        }
        WP_CLI::log(sprintf(
            'Queue: %d recipient(s) across %d campaign(s). Sent today: %d of %d.',
            $sending['queued_recipients'],
            $sending['campaigns_in_flight'],
            $sending['sent_today'],
            $sending['daily_cap']
        ));

        $rows = [];
        foreach (Database::all('SELECT job_name, last_run_at, last_status, run_count FROM {cron_heartbeats}') as $row) {
            $rows[] = [
                'job'      => (string) $row['job_name'],
                'last_run' => (string) $row['last_run_at'],
                'status'   => (string) $row['last_status'],
                'runs'     => (string) $row['run_count'],
            ];
        }

        if ($rows === []) {
            WP_CLI::warning('No background job has run yet.');
        } else {
            WP_CLI\Utils\format_items('table', $rows, ['job', 'last_run', 'status', 'runs']);
        }

        foreach (AlertService::health() as $area => $entry) {
            WP_CLI::log(sprintf('%-16s %-8s %s', $area, $entry['status'], $entry['detail']));
        }
    }

    /**
     * Stops or resumes all sending.
     *
     * ## OPTIONS
     *
     * <state>
     * : "stop" or "resume".
     *
     * [--reason=<reason>]
     * : Why sending is being stopped. Required when stopping.
     *
     * ## EXAMPLES
     *
     *     wp techzapp-crm sending stop --reason="Investigating a bounce spike"
     *     wp techzapp-crm sending resume
     *
     * @when after_wp_load
     */
    public function sending(array $args, array $assoc): void
    {
        $state = (string) ($args[0] ?? '');

        if ($state === 'stop') {
            $reason = (string) ($assoc['reason'] ?? '');
            if ($reason === '') {
                WP_CLI::error('Pass --reason when stopping sending; it is recorded in the audit log.');
            }

            Settings::update(['kill_switch_engaged' => true, 'kill_switch_reason' => $reason]);
            \TechzappCrm\Core\Audit::log('sending.kill_switch_engaged', 'system', 'kill_switch', ['reason' => $reason]);
            WP_CLI::success('Sending stopped.');
            return;
        }

        if ($state === 'resume') {
            Settings::update(['kill_switch_engaged' => false, 'kill_switch_reason' => '']);
            \TechzappCrm\Core\Audit::log('sending.kill_switch_released', 'system', 'kill_switch');
            AlertService::resolve('kill_switch', 'released from WP-CLI');
            WP_CLI::success('Sending resumed.');
            return;
        }

        WP_CLI::error('Expected "stop" or "resume".');
    }

    /**
     * Re-runs every deliverability check.
     *
     * ## EXAMPLES
     *
     *     wp techzapp-crm check
     *
     * @when after_wp_load
     */
    public function check(array $args, array $assoc): void
    {
        $result = AlertService::evaluateAll();

        WP_CLI::log(sprintf(
            '%d check(s) run, %d alert(s) raised, %d resolved.',
            $result['checks'],
            $result['raised'],
            $result['resolved']
        ));

        $active = AlertService::active();
        if ($active === []) {
            WP_CLI::success('No active alerts.');
            return;
        }

        foreach ($active as $alert) {
            $line = sprintf('[%s] %s — %s', $alert['severity'], $alert['title'], $alert['message']);
            if ($alert['severity'] === 'critical') {
                WP_CLI::warning($line);
            } else {
                WP_CLI::log($line);
            }
        }
    }

    /**
     * Re-installs the database schema.
     *
     * Safe to run repeatedly: dbDelta only applies what is missing.
     *
     * ## EXAMPLES
     *
     *     wp techzapp-crm install-schema
     *
     * @when after_wp_load
     */
    public function install_schema(array $args, array $assoc): void
    {
        Installer::installSchema();

        $missing = Installer::missingTables();
        if ($missing !== []) {
            WP_CLI::error('Still missing after install: ' . implode(', ', $missing));
        }

        WP_CLI::success(sprintf('Schema is up to date (%d tables).', count(Database::TABLES)));
    }
}
