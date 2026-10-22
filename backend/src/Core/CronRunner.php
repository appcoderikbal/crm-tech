<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

use Throwable;

/**
 * Shared scaffolding for the cron workers.
 *
 * Responsibilities: refuse unauthorised HTTP invocation, hold a per-job lock so
 * overlapping cron runs cannot double-process, enforce a wall-clock budget that
 * stays under the host's process limit, and record a heartbeat the dashboard
 * uses to detect a scheduler that has stopped.
 */
final class CronRunner
{
    private float $startedAt;
    private float $deadline;
    private ?Lock $lock = null;

    public function __construct(
        private readonly string $jobName,
        private readonly int $maxRuntimeSeconds
    ) {
        $this->startedAt = microtime(true);
        $this->deadline = $this->startedAt + $maxRuntimeSeconds;
    }

    /**
     * Boots a cron job and returns the runner, or exits when another instance
     * holds the lock.
     */
    public static function start(string $jobName, ?int $maxRuntimeSeconds = null): self
    {
        $config = \Techzapp\Crm\Bootstrap::init($jobName);
        self::assertAuthorised($config);

        $runtime = $maxRuntimeSeconds ?? max(10, $config->int('cron.max_runtime_seconds', 50));
        $runner = new self($jobName, $runtime);

        // A long-running job must not be killed by max_execution_time; the
        // runner's own deadline is the real limit.
        if (PHP_SAPI === 'cli') {
            @set_time_limit(0);
        } else {
            @set_time_limit($runtime + 30);
        }
        @ignore_user_abort(true);

        $runner->lock = Lock::acquire('cron:' . $jobName, $runtime + 60, 'pid ' . getmypid());
        if ($runner->lock === null) {
            $runner->output("Another {$jobName} run is still in progress; exiting.");
            exit(0);
        }

        return $runner;
    }

    /**
     * Blocks unauthenticated HTTP invocation.
     *
     * Hostinger cron normally runs the PHP binary directly, in which case this
     * is a no-op. If you must trigger a job over HTTP (wget/curl cron), set
     * cron.http_token and pass it as ?token=… — without it the script refuses.
     */
    private static function assertAuthorised(Config $config): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $expected = $config->string('cron.http_token');
        $provided = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            Logger::warning('cron.unauthorised_http_invocation', [
                'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
                'path' => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            http_response_code(403);
            header('Content-Type: text/plain');
            exit("Forbidden\n");
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    public function secondsRemaining(): float
    {
        return max(0, $this->deadline - microtime(true));
    }

    public function expired(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    /** Keeps the job lock alive during long runs. */
    public function heartbeat(): bool
    {
        return $this->lock?->heartbeat() ?? false;
    }

    public function output(string $message): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $line);
        } else {
            echo $line;
        }
        Logger::info('cron.' . $this->jobName, ['message' => $message]);
    }

    /**
     * Runs the job body, records the outcome and releases the lock.
     *
     * @param callable(self):array<string,mixed> $work
     */
    public function run(callable $work): int
    {
        $status = 'ok';
        $message = '';
        $exitCode = 0;

        try {
            $summary = $work($this);
            $message = json_encode($summary, JSON_UNESCAPED_SLASHES) ?: '';
            $this->output('done ' . $message);
        } catch (Throwable $e) {
            $status = 'error';
            $message = $e->getMessage();
            $exitCode = 1;
            Logger::exception('cron.failed', $e, ['job' => $this->jobName]);
            $this->output('ERROR: ' . $message);
        } finally {
            $this->recordHeartbeat($status, $message);
            $this->lock?->release();
        }

        return $exitCode;
    }

    private function recordHeartbeat(string $status, string $message): void
    {
        try {
            Database::execute(
                'INSERT INTO cron_heartbeats (job_name, last_run_at, last_status, last_message, duration_ms, run_count)
                 VALUES (:job, UTC_TIMESTAMP(), :status, :message, :duration, 1)
                 ON DUPLICATE KEY UPDATE
                    last_run_at = UTC_TIMESTAMP(),
                    last_status = VALUES(last_status),
                    last_message = VALUES(last_message),
                    duration_ms = VALUES(duration_ms),
                    run_count = run_count + 1',
                [
                    'job'      => $this->jobName,
                    'status'   => $status,
                    'message'  => substr($message, 0, 500),
                    'duration' => (int) ((microtime(true) - $this->startedAt) * 1000),
                ]
            );
        } catch (Throwable $e) {
            Logger::exception('cron.heartbeat_failed', $e, ['job' => $this->jobName]);
        }
    }
}
