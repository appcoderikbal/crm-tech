<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Http\Validator;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Support\Csv;

final class SuppressionController
{
    public function index(Request $request): Response
    {
        Auth::requireUser();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(1, (int) $request->query('per_page', 25)));
        $offset = ($page - 1) * $perPage;

        $clauses = ['1 = 1'];
        $params = [];

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $clauses[] = 's.normalized_email LIKE :search';
            $params['search'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
        }
        $reason = (string) $request->query('reason', '');
        if (in_array($reason, SuppressionService::REASONS, true)) {
            $clauses[] = 's.reason = :reason';
            $params['reason'] = $reason;
        }

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM suppressions s WHERE {$where}", $params);

        $items = Database::all(
            "SELECT s.id, s.normalized_email, s.reason, s.source, s.notes, s.campaign_id,
                    s.created_at, s.updated_at, c.id AS contact_id, c.email AS contact_email,
                    cam.name AS campaign_name
               FROM suppressions s
          LEFT JOIN contacts c ON c.normalized_email = s.normalized_email
          LEFT JOIN campaigns cam ON cam.id = s.campaign_id
              WHERE {$where}
           ORDER BY s.created_at DESC, s.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $counts = Database::all('SELECT reason, COUNT(*) AS total FROM suppressions GROUP BY reason');
        $byReason = [];
        foreach ($counts as $row) {
            $byReason[(string) $row['reason']] = (int) $row['total'];
        }

        return Response::json($items, 200, [
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'pages'     => (int) ceil($total / $perPage),
            'by_reason' => $byReason,
            'reasons'   => SuppressionService::REASONS,
        ]);
    }

    public function store(Request $request): Response
    {
        $user = Auth::requireWriteAccess();

        $validator = Validator::make($request->body());
        $email = $validator->email('email', true);
        $reason = $validator->enum('reason', SuppressionService::REASONS, false, 'manual_block');
        $notes = $validator->string('notes', false, 500, 0, '');
        $validator->validate();

        $added = SuppressionService::suppress(
            (string) $email,
            (string) $reason,
            'admin:' . $user['email'],
            null,
            (string) $notes,
            (int) $user['id']
        );

        Audit::log('suppression.added', 'suppression', (string) $email, [
            'reason' => $reason,
            'new'    => $added,
        ], (int) $user['id']);

        return Response::json(['suppressed' => true, 'newly_added' => $added, 'email' => $email]);
    }

    public function destroy(Request $request, array $params): Response
    {
        // Releasing a suppression is an administrator-only action: it undoes a
        // recipient's opt-out, so it is deliberately privileged and audited.
        $user = Auth::requireAdmin();

        $email = (string) Database::scalar(
            'SELECT normalized_email FROM suppressions WHERE id = :id',
            ['id' => (int) $params['id']]
        );
        if ($email === '') {
            return Response::json(['released' => false]);
        }

        $notes = (string) $request->input('notes', '');
        $released = SuppressionService::release($email, (int) $user['id'], $notes);

        Audit::log('suppression.released', 'suppression', $email, ['notes' => $notes], (int) $user['id']);

        return Response::json(['released' => $released, 'email' => $email]);
    }

    public function history(Request $request): Response
    {
        Auth::requireUser();

        $email = (string) $request->query('email', '');
        return Response::json(SuppressionService::history($email, 100));
    }

    public function export(Request $request): Response
    {
        $user = Auth::requireUser();

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="suppressions-' . gmdate('Ymd-His') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo "\xEF\xBB\xBF";
        echo Csv::formatRow(['email', 'reason', 'source', 'notes', 'created_at']);

        $lastId = 0;
        do {
            $rows = Database::all(
                'SELECT id, normalized_email, reason, source, notes, created_at
                   FROM suppressions WHERE id > :last ORDER BY id LIMIT 1000',
                ['last' => $lastId]
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                echo Csv::formatRow([
                    $row['normalized_email'],
                    $row['reason'],
                    $row['source'],
                    $row['notes'],
                    $row['created_at'],
                ]);
            }
        } while (count($rows) === 1000);

        Audit::log('suppression.exported', 'suppression', '', [], (int) $user['id']);
        exit;
    }
}
