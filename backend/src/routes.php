<?php

declare(strict_types=1);

/**
 * API route table. Every route below is authenticated inside its controller;
 * there is no "public by default" path in this file.
 */

use Techzapp\Crm\Controllers\AuthController;
use Techzapp\Crm\Controllers\CampaignController;
use Techzapp\Crm\Controllers\ContactController;
use Techzapp\Crm\Controllers\ImportController;
use Techzapp\Crm\Controllers\ListController;
use Techzapp\Crm\Controllers\ReportController;
use Techzapp\Crm\Controllers\SettingsController;
use Techzapp\Crm\Controllers\SuppressionController;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Http\Router;

$router = new Router();
$prefix = '/api/v1';

// --- health -----------------------------------------------------------------
$router->get($prefix . '/health', static function (Request $request): Response {
    // Deliberately minimal: no version numbers, no configuration details.
    return Response::json(['status' => 'ok', 'time' => gmdate('c')]);
});

// --- authentication ---------------------------------------------------------
$router->post($prefix . '/auth/login', [AuthController::class, 'login']);
$router->post($prefix . '/auth/logout', [AuthController::class, 'logout']);
$router->get($prefix . '/auth/me', [AuthController::class, 'me']);
$router->patch($prefix . '/auth/profile', [AuthController::class, 'updateProfile']);
$router->post($prefix . '/auth/change-password', [AuthController::class, 'changePassword']);

// --- contacts ---------------------------------------------------------------
$router->get($prefix . '/contacts', [ContactController::class, 'index']);
$router->get($prefix . '/contacts/meta', [ContactController::class, 'meta']);
$router->post($prefix . '/contacts', [ContactController::class, 'store']);
$router->post($prefix . '/contacts/export', [ContactController::class, 'export']);
$router->post($prefix . '/contacts/bulk-delete', [ContactController::class, 'bulkDelete']);
$router->post($prefix . '/contacts/bulk-assign', [ContactController::class, 'bulkAssign']);
$router->get($prefix . '/contacts/{id}', [ContactController::class, 'show']);
$router->patch($prefix . '/contacts/{id}', [ContactController::class, 'update']);
$router->delete($prefix . '/contacts/{id}', [ContactController::class, 'destroy']);

// --- imports ----------------------------------------------------------------
$router->post($prefix . '/contacts/import', [ImportController::class, 'upload']);
$router->get($prefix . '/imports', [ImportController::class, 'history']);
$router->get($prefix . '/imports/{id}', [ImportController::class, 'show']);
$router->post($prefix . '/imports/{id}/start', [ImportController::class, 'start']);
$router->post($prefix . '/imports/{id}/process', [ImportController::class, 'process']);
$router->post($prefix . '/imports/{id}/cancel', [ImportController::class, 'cancel']);
$router->get($prefix . '/imports/{id}/errors', [ImportController::class, 'errors']);

// --- lists ------------------------------------------------------------------
$router->get($prefix . '/lists', [ListController::class, 'index']);
$router->post($prefix . '/lists', [ListController::class, 'store']);
$router->get($prefix . '/lists/{id}', [ListController::class, 'show']);
$router->get($prefix . '/lists/{id}/members', [ListController::class, 'members']);
$router->patch($prefix . '/lists/{id}', [ListController::class, 'update']);
$router->delete($prefix . '/lists/{id}', [ListController::class, 'destroy']);
$router->post($prefix . '/lists/{id}/assign', [ListController::class, 'assign']);
$router->post($prefix . '/lists/{id}/remove', [ListController::class, 'remove']);

// --- campaigns --------------------------------------------------------------
$router->get($prefix . '/campaigns', [CampaignController::class, 'index']);
$router->post($prefix . '/campaigns', [CampaignController::class, 'store']);
$router->get($prefix . '/campaigns/{id}', [CampaignController::class, 'show']);
$router->patch($prefix . '/campaigns/{id}', [CampaignController::class, 'update']);
$router->delete($prefix . '/campaigns/{id}', [CampaignController::class, 'destroy']);
$router->post($prefix . '/campaigns/{id}/duplicate', [CampaignController::class, 'duplicate']);
$router->get($prefix . '/campaigns/{id}/preview', [CampaignController::class, 'preview']);
$router->post($prefix . '/campaigns/{id}/test', [CampaignController::class, 'test']);
$router->post($prefix . '/campaigns/{id}/schedule', [CampaignController::class, 'schedule']);
$router->post($prefix . '/campaigns/{id}/send-now', [CampaignController::class, 'sendNow']);
$router->post($prefix . '/campaigns/{id}/cancel', [CampaignController::class, 'cancel']);
$router->post($prefix . '/campaigns/{id}/pause', [CampaignController::class, 'pause']);
$router->post($prefix . '/campaigns/{id}/resume', [CampaignController::class, 'resume']);
$router->get($prefix . '/campaigns/{id}/report', [CampaignController::class, 'report']);
$router->get($prefix . '/campaigns/{id}/recipients', [CampaignController::class, 'recipients']);

// --- suppressions -----------------------------------------------------------
$router->get($prefix . '/suppressions', [SuppressionController::class, 'index']);
$router->post($prefix . '/suppressions', [SuppressionController::class, 'store']);
$router->post($prefix . '/suppressions/export', [SuppressionController::class, 'export']);
$router->get($prefix . '/suppressions/history', [SuppressionController::class, 'history']);
$router->delete($prefix . '/suppressions/{id}', [SuppressionController::class, 'destroy']);

// --- reporting --------------------------------------------------------------
$router->get($prefix . '/reports/dashboard', [ReportController::class, 'dashboard']);
$router->get($prefix . '/reports/deliverability', [ReportController::class, 'deliverability']);
$router->get($prefix . '/reports/events', [ReportController::class, 'events']);
$router->get($prefix . '/reports/campaigns/{id}', [ReportController::class, 'campaign']);
$router->get($prefix . '/alerts', [ReportController::class, 'alerts']);
$router->post($prefix . '/alerts/evaluate', [ReportController::class, 'evaluateAlerts']);
$router->post($prefix . '/alerts/{id}/acknowledge', [ReportController::class, 'acknowledgeAlert']);

// --- settings and administration --------------------------------------------
$router->get($prefix . '/settings', [SettingsController::class, 'show']);
$router->patch($prefix . '/settings', [SettingsController::class, 'update']);
$router->post($prefix . '/settings/kill-switch', [SettingsController::class, 'killSwitch']);
$router->get($prefix . '/settings/audit', [SettingsController::class, 'audit']);
$router->get($prefix . '/settings/users', [SettingsController::class, 'users']);

return $router;
