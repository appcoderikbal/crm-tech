<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Logger;
use TechzappCrm\Services\CampaignSender;
use TechzappCrm\Services\CampaignService;
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
