<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Services\AlertService;
use Techzapp\Crm\Services\ReportService;

final class ReportController
{
    public function dashboard(Request $request): Response
    {
        Auth::requireUser();

        [$from, $to] = $this->range($request);

        return Response::json(ReportService::dashboard($from, $to));
    }

    public function deliverability(Request $request): Response
    {
        Auth::requireUser();

        $windowHours = (int) $request->query('window_hours', 0);
        $report = ReportService::deliverability($windowHours > 0 ? min(720, $windowHours) : null);

        return Response::json($report);
    }

    public function campaign(Request $request, array $params): Response
    {
        Auth::requireUser();
        return Response::json(ReportService::campaignReport((int) $params['id']));
    }

    public function alerts(Request $request): Response
    {
        Auth::requireUser();

        return Response::json(AlertService::active(100), 200, [
            'critical' => AlertService::criticalCount(),
            'open'     => AlertService::unacknowledgedCount(),
            'health'   => AlertService::health(),
        ]);
    }

    public function acknowledgeAlert(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $acknowledged = AlertService::acknowledge((int) $params['id'], (int) $user['id']);

        return Response::json(['acknowledged' => $acknowledged]);
    }

    /** Re-runs every monitoring check on demand. */
    public function evaluateAlerts(Request $request): Response
    {
        Auth::requireWriteAccess();

        return Response::json(AlertService::evaluateAll() + [
            'health' => AlertService::health(),
            'alerts' => AlertService::active(),
        ]);
    }

    public function events(Request $request): Response
    {
        Auth::requireUser();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $offset = ($page - 1) * $perPage;

        $clauses = ['1 = 1'];
        $params = [];

        $type = (string) $request->query('event_type', '');
        if ($type !== '' && in_array($type, \Techzapp\Crm\Services\EventService::TYPES, true)) {
            $clauses[] = 'e.event_type = :type';
            $params['type'] = $type;
        }
        if ($request->query('campaign_id') !== null) {
            $clauses[] = 'e.campaign_id = :campaign';
            $params['campaign'] = (int) $request->query('campaign_id');
        }

        [$from, $to] = $this->range($request);
        $clauses[] = 'e.event_timestamp BETWEEN :from AND :to';
        $params['from'] = $from;
        $params['to'] = $to;

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM email_events e WHERE {$where}", $params);

        $items = Database::all(
            "SELECT e.id, e.event_type, e.event_subtype, e.event_timestamp, e.campaign_id,
                    c.name AS campaign_name, r.recipient_email_snapshot, e.metadata_json
               FROM email_events e
          LEFT JOIN campaigns c ON c.id = e.campaign_id
          LEFT JOIN campaign_recipients r ON r.id = e.recipient_id
              WHERE {$where}
           ORDER BY e.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return Response::json($items, 200, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ]);
    }

    /** @return array{0:string,1:string} UTC bounds */
    private function range(Request $request): array
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $fromTime = $from !== '' ? strtotime($from . ' UTC') : false;
        $toTime = $to !== '' ? strtotime($to . ' UTC') : false;

        if ($fromTime === false) {
            $fromTime = time() - 30 * 86400;
        }
        if ($toTime === false) {
            $toTime = time();
        }
        if ($toTime < $fromTime) {
            [$fromTime, $toTime] = [$toTime, $fromTime];
        }
        // Cap the window so a crafted range cannot scan the whole event table.
        if (($toTime - $fromTime) > 400 * 86400) {
            $fromTime = $toTime - 400 * 86400;
        }

        return [gmdate('Y-m-d H:i:s', $fromTime), gmdate('Y-m-d H:i:s', $toTime)];
    }
}
