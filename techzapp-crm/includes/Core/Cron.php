<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Background processing.
 *
 * WP-Cron is the default because it works on any WordPress install with no
 * setup. It is also request-triggered: a site with no visitors runs no jobs,
 * so a campaign scheduled for 3am on a quiet site sends when someone next
 * loads a page. For anything beyond small volumes the Settings screen tells
 * you to disable WP-Cron and point a real system cron at WP-CLI instead.
 *
 * Either way the work is identical — the same four jobs, each holding a lock
 * and stopping at a deadline well inside the host's process limit.
 */
final class Cron
{
    public const JOBS = [
        'process_campaigns' => 'techzapp_crm_process_campaigns',
        'process_events'    => 'techzapp_crm_process_events',
        'process_imports'   => 'techzapp_crm_process_imports',
        'maintenance'       => 'techzapp_crm_maintenance',
    ];

    /** Jobs whose absence means scheduled campaigns silently never send. */
    public const CRITICAL_JOBS = ['process_campaigns', 'process_events'];

    public static function register(): void
    {
        add_filter('cron_schedules', [self::class, 'addSchedules']);

        add_action(self::JOBS['process_campaigns'], [self::class, 'runProcessCampaigns']);
        add_action(self::JOBS['process_events'], [self::class, 'runProcessEvents']);
        add_action(self::JOBS['process_imports'], [self::class, 'runProcessImports']);
        add_action(self::JOBS['maintenance'], [self::class, 'runMaintenance']);

        // A fresh install, or a site where the schedule was lost.
        add_action('init', [self::class, 'ensureScheduled']);
    }

    /** @param array<string,array{interval:int,display:string}> $schedules */
    public static function addSchedules(array $schedules): array
    {
        $schedules['techzapp_crm_minute'] = [
            'interval' => 60,
            'display'  => __('Every minute (Techzapp CRM)', 'techzapp-crm'),
        ];
        $schedules['techzapp_crm_five_minutes'] = [
            'interval' => 300,
            'display'  => __('Every five minutes (Techzapp CRM)', 'techzapp-crm'),
        ];

        return $schedules;
    }

    public static function schedule(): void
    {
        $plan = [
            self::JOBS['process_campaigns'] => 'techzapp_crm_minute',
            self::JOBS['process_imports']   => 'techzapp_crm_minute',
            self::JOBS['process_events']    => 'techzapp_crm_five_minutes',
            self::JOBS['maintenance']       => 'hourly',
        ];

        foreach ($plan as $hook => $recurrence) {
            if (wp_next_scheduled($hook) === false) {
                wp_schedule_event(time() + 30, $recurrence, $hook);
            }
        }
    }

    public static function ensureScheduled(): void
    {
        foreach (self::JOBS as $hook) {
            if (wp_next_scheduled($hook) === false) {
                self::schedule();
                return;
            }
        }
    }

    public static function unschedule(): void
    {
        foreach (self::JOBS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /** True when WP-Cron is turned off and a real scheduler is expected. */
    public static function wpCronDisabled(): bool
    {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    /** @return array<string,int|false> hook => next run timestamp */
    public static function nextRuns(): array
    {
        $runs = [];
        foreach (self::JOBS as $name => $hook) {
            $runs[$name] = wp_next_scheduled($hook);
        }
        return $runs;
    }

    // ------------------------------------------------------------- job bodies

    public static function runProcessCampaigns(): void
    {
        self::run('process_campaigns', static fn (Runner $runner): array => Jobs::processCampaigns($runner));
    }

    public static function runProcessEvents(): void
    {
        self::run('process_events', static fn (Runner $runner): array => Jobs::processEvents($runner));
    }

    public static function runProcessImports(): void
    {
        self::run('process_imports', static fn (Runner $runner): array => Jobs::processImports($runner));
    }

    public static function runMaintenance(): void
    {
        self::run('maintenance', static fn (Runner $runner): array => Jobs::maintenance($runner));
    }

    /**
     * Runs one job under a lock, with a deadline and a recorded heartbeat.
     *
     * @param callable(Runner):array<string,mixed> $work
     * @return array<string,mixed> the job's own summary
     */
    public static function run(string $job, callable $work): array
    {
        Logger::channel('cron');

        $lock = Lock::acquire('cron:' . $job, Config::maxRuntimeSeconds() + 60);
        if ($lock === null) {
            return ['skipped' => 'another run is still in progress'];
        }

        $runner = new Runner($job, Config::maxRuntimeSeconds(), $lock);
        $started = microtime(true);
        $status = 'ok';
        $summary = [];

        // The runner's own deadline is the real limit; the PHP time limit must
        // not cut a batch off halfway through.
        if (function_exists('set_time_limit')) {
            @set_time_limit(Config::maxRuntimeSeconds() + 60);
        }
        @ignore_user_abort(true);

        try {
            $summary = $work($runner);
        } catch (Throwable $e) {
            $status = 'error';
            $summary = ['error' => $e->getMessage()];
            Logger::exception('cron.failed', $e, ['job' => $job]);
        } finally {
            self::recordHeartbeat($job, $status, $summary, (int) ((microtime(true) - $started) * 1000));
            $lock->release();
        }

        return $summary;
    }

    private static function recordHeartbeat(string $job, string $status, array $summary, int $durationMs): void
    {
        try {
            $message = (string) wp_json_encode($summary, JSON_UNESCAPED_SLASHES);

            Database::execute(
                'INSERT INTO {cron_heartbeats} (job_name, last_run_at, last_status, last_message, duration_ms, run_count)
                 VALUES (:job, :now, :status, :message, :duration, 1)
                 ON DUPLICATE KEY UPDATE
                    last_run_at  = VALUES(last_run_at),
                    last_status  = VALUES(last_status),
                    last_message = VALUES(last_message),
                    duration_ms  = VALUES(duration_ms),
                    run_count    = run_count + 1',
                [
                    'job'      => $job,
                    'now'      => Database::nowUtc(),
                    'status'   => $status,
                    'message'  => substr($message, 0, 500),
                    'duration' => $durationMs,
                ]
            );
        } catch (Throwable $e) {
            Logger::exception('cron.heartbeat_failed', $e, ['job' => $job]);
        }
    }
}

/**
 * The time budget and lock for one background run.
 *
 * Every batch loop checks expired() so a run always finishes tidily rather
 * than being killed mid-statement by the host.
 */
final class Runner
{
    private readonly float $deadline;

    public function __construct(
        public readonly string $job,
        int $maxRuntimeSeconds,
        private readonly Lock $lock
    ) {
        $this->deadline = microtime(true) + $maxRuntimeSeconds;
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    public function expired(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    public function secondsRemaining(): float
    {
        return max(0, $this->deadline - microtime(true));
    }

    public function heartbeat(): bool
    {
        return $this->lock->heartbeat();
    }

    public function log(string $message, array $context = []): void
    {
        Logger::info('cron.' . $this->job, ['message' => $message] + $context);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log($message);
        }
    }
}
