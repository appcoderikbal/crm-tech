<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Cron;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Logger;
use TechzappCrm\Services\CampaignSender;
use TechzappCrm\Services\CampaignService;
use TechzappCrm\Services\EventService;
use TechzappCrm\Services\ImportService;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX: the endpoints that let the browser drive long work.
 *
 * Both advance work one bounded chunk at a time rather than trying to finish
 * in a single request, because shared hosting will kill a long one. The
 * background workers run the identical code, so with the scheduler enabled
 * closing the tab slows things down rather than abandoning them — and in
 * manual mode the browser is the only thing driving it.
 */
final class Ajax
{
    /** Memory held back so the fatal handler can still run. @see catchFatal */
    private static ?string $reserve = null;

    public static function register(): void
    {
        add_action('wp_ajax_techzapp_crm_import_step', [self::class, 'importStep']);
        add_action('wp_ajax_techzapp_crm_send_step', [self::class, 'sendStep']);
    }

    public static function importStep(): void
    {
        self::authorise(__('You do not have permission to run imports.', 'techzapp-crm'));

        $jobId = isset($_POST['job']) ? (int) $_POST['job'] : 0;
        if ($jobId <= 0) {
            wp_send_json_error(__('Unknown import.', 'techzapp-crm'), 400);
        }

        try {
            // Bounded so the request always returns quickly on shared hosting.
            $job = ImportService::processChunk($jobId, microtime(true) + 10);

            wp_send_json_success($job);
        } catch (CrmException $e) {
            wp_send_json_error($e->getMessage(), 400);
        } catch (Throwable $e) {
            Logger::exception('ajax.import_step_failed', $e, ['job' => $jobId]);
            wp_send_json_error(__('The import stopped unexpectedly. The details were written to the plugin log.', 'techzapp-crm'), 500);
        }
    }

    /**
     * Advances one campaign by a single batch.
     *
     * The browser calls this repeatedly until "done" comes back. Every call
     * re-reads the status from the database, so pausing or cancelling from
     * another tab stops the loop on its next step rather than racing it.
     */
    public static function sendStep(): void
    {
        self::authorise(__('You do not have permission to send campaigns.', 'techzapp-crm'));

        $campaignId = isset($_POST['campaign']) ? (int) $_POST['campaign'] : 0;
        if ($campaignId <= 0) {
            wp_send_json_error(__('Unknown campaign.', 'techzapp-crm'), 400);
        }

        self::catchFatal($campaignId);

        try {
            $campaign = CampaignService::find($campaignId);
            if ($campaign === null) {
                wp_send_json_error(__('That campaign no longer exists.', 'techzapp-crm'), 404);
            }

            $status = (string) $campaign['status'];

            // Terminal or operator-halted: report, do not resume.
            if (!in_array($status, ['queued', 'sending'], true)) {
                wp_send_json_success(self::progress($campaignId, $status, true, match ($status) {
                    'paused'    => __('Paused. Resume it to continue sending.', 'techzapp-crm'),
                    'cancelled' => __('Cancelled. Remaining recipients were skipped.', 'techzapp-crm'),
                    'completed' => __('All messages have been sent.', 'techzapp-crm'),
                    'failed'    => (string) ($campaign['last_error'] ?: __('The campaign failed.', 'techzapp-crm')),
                    default     => __('This campaign is not sending.', 'techzapp-crm'),
                }));
            }

            // Recipients are built incrementally too: a 40,000-contact list
            // cannot be expanded inside one request either.
            $built = CampaignSender::buildRecipients($campaignId, microtime(true) + 8);

            if (empty($built['complete'])) {
                wp_send_json_success(self::progress(
                    $campaignId,
                    $status,
                    false,
                    __('Preparing the recipient list…', 'techzapp-crm')
                ));
            }

            $result = CampaignSender::processCampaign($campaignId, microtime(true) + 12);
            CampaignSender::completeIfFinished($campaignId);

            // Bounce and complaint feedback arrives while a send is in
            // progress. With no scheduled worker nothing else would ever drain
            // that queue, so a hard bounce would not suppress the address and
            // the next campaign would send to it again. Deliberately small:
            // this is a send loop, not an event worker.
            self::drainFeedback();

            $campaign = CampaignService::find($campaignId);
            $status = (string) ($campaign['status'] ?? $status);
            $done = !in_array($status, ['queued', 'sending'], true);

            $note = '';
            if (($result['reason'] ?? '') === 'sending_disabled') {
                $done = true;
                $note = __('Sending is disabled by the global kill switch in Settings.', 'techzapp-crm');
            } elseif (($result['reason'] ?? '') !== '' && (int) $result['sent'] === 0) {
                // A rate or daily cap, typically. Not an error; the loop waits.
                $note = (string) $result['reason'];
            }

            wp_send_json_success(self::progress($campaignId, $status, $done, $note));
        } catch (CrmException $e) {
            wp_send_json_error($e->getMessage(), 400);
        } catch (Throwable $e) {
            Logger::exception('ajax.send_step_failed', $e, ['campaign' => $campaignId]);
            wp_send_json_error(__('Sending stopped unexpectedly. The details were written to the plugin log.', 'techzapp-crm'), 500);
        }
    }

    /**
     * Counts straight from the recipient rows rather than campaign_stats,
     * which a scheduled job refreshes and would lag behind the live loop.
     *
     * @return array<string,mixed>
     */
    private static function progress(int $campaignId, string $status, bool $done, string $note = ''): array
    {
        // "Remaining" is anything not yet in a terminal state, so a row left
        // mid-flight in 'sending' or 'scheduled' still counts as outstanding.
        // Counting only 'pending' would report a campaign as finished while
        // rows were still in play.
        $row = Database::first(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status NOT IN
                    ('accepted','delivered','bounced','complained','failed','suppressed','cancelled')
                    THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status IN ('accepted','delivered','bounced','complained') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status IN ('suppressed','cancelled') THEN 1 ELSE 0 END) AS skipped
               FROM {campaign_recipients}
              WHERE campaign_id = :id",
            ['id' => $campaignId]
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);
        $pending = (int) ($row['pending'] ?? 0);

        return [
            'campaign_id' => $campaignId,
            'status'      => $status,
            'done'        => $done,
            'note'        => $note,
            'total'       => $total,
            'pending'     => $pending,
            'sent'        => (int) ($row['sent'] ?? 0),
            'failed'      => (int) ($row['failed'] ?? 0),
            'skipped'     => (int) ($row['skipped'] ?? 0),
            'percent'     => $total > 0 ? (int) floor((($total - $pending) / $total) * 100) : 0,
        ];
    }

    /**
     * Turns a fatal error into a JSON answer.
     *
     * A memory-limit abort or a fatal inside a bundled library kills PHP
     * outright: try/catch never sees it, nothing is printed, and the browser
     * gets an empty body it can only describe as "Unexpected end of JSON
     * input" — true, and useless. A shutdown handler still runs, so the real
     * message gets through and ends up in front of the person who needs it.
     */
    private static function catchFatal(int $campaignId): void
    {
        // Reserved headroom. When PHP aborts on the memory limit there is by
        // definition nothing left to allocate, so a shutdown handler that
        // needs to build a string and encode JSON cannot run — which is
        // exactly the case this exists to report. Releasing this buys it back.
        self::$reserve = str_repeat('x', 256 * 1024);

        register_shutdown_function(static function () use ($campaignId): void {
            self::$reserve = null;

            $error = error_get_last();

            // Normal completion calls die() from wp_send_json; only a genuine
            // fatal should produce a second response.
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::error('ajax.send_step_fatal', [
                'campaign' => $campaignId,
                'message'  => $error['message'],
                'file'     => $error['file'],
                'line'     => $error['line'],
            ]);

            if (headers_sent()) {
                return;
            }

            $detail = $error['message'];
            if (str_contains($detail, 'Allowed memory size')) {
                $detail .= ' — ' . sprintf(
                    /* translators: %s: the configured PHP memory limit */
                    __('PHP ran out of memory (limit %s). Raise memory_limit, or ask the host to.', 'techzapp-crm'),
                    (string) ini_get('memory_limit')
                );
            }

            status_header(500);
            header('Content-Type: application/json; charset=utf-8');

            echo (string) wp_json_encode([
                'success' => false,
                'data'    => sprintf(
                    /* translators: 1: error message, 2: file, 3: line */
                    __('PHP stopped: %1$s (in %2$s line %3$d)', 'techzapp-crm'),
                    $detail,
                    basename((string) $error['file']),
                    (int) $error['line']
                ),
            ]);
        });
    }

    /**
     * Processes a few queued provider notifications, best-effort.
     *
     * Only runs when there is no scheduled worker to do it. A failure here
     * must never fail the send: the messages went out regardless, and the
     * feedback queue is durable, so it is logged and left for the next pass.
     */
    private static function drainFeedback(): void
    {
        if (!Cron::isManual()) {
            return;
        }

        try {
            EventService::processPending(50, microtime(true) + 5);
        } catch (Throwable $e) {
            Logger::warning('ajax.feedback_drain_failed', ['error' => $e->getMessage()]);
        }
    }

    /** Nonce first, then capability. Being in wp-admin is not authorisation. */
    private static function authorise(string $denied): void
    {
        if (!check_ajax_referer('techzapp_crm_ajax', 'nonce', false)) {
            wp_send_json_error(__('Your session expired. Reload the page.', 'techzapp-crm'), 403);
        }

        if (!Capabilities::canManage()) {
            wp_send_json_error($denied, 403);
        }
    }
}
