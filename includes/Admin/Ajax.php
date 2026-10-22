<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Logger;
use TechzappCrm\Services\ImportService;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX.
 *
 * Only one endpoint: advancing an import by one chunk so the operator sees a
 * progress bar. The background worker runs the identical code, so closing the
 * tab slows an import down rather than abandoning it.
 */
final class Ajax
{
    public static function register(): void
    {
        add_action('wp_ajax_techzapp_crm_import_step', [self::class, 'importStep']);
    }

    public static function importStep(): void
    {
        // Nonce first, then capability. Being logged into wp-admin is not
        // authorisation on its own.
        if (!check_ajax_referer('techzapp_crm_ajax', 'nonce', false)) {
            wp_send_json_error(__('Your session expired. Reload the page.', 'techzapp-crm'), 403);
        }

        if (!Capabilities::canManage()) {
            wp_send_json_error(__('You do not have permission to run imports.', 'techzapp-crm'), 403);
        }

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
}
