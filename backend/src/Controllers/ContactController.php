<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Http\Validator;
use Techzapp\Crm\Services\ContactService;
use Techzapp\Crm\Services\ListService;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Support\Csv;

final class ContactController
{
    public function index(Request $request): Response
    {
        Auth::requireUser();

        $filters = [
            // The Contacts page defaults to unassigned contacts only, which is
            // the product rule; an explicit assignment filter overrides it.
            'assignment'   => (string) $request->query('assignment', 'unassigned'),
            'list_id'      => $request->query('list_id'),
            'search'       => (string) $request->query('search', ''),
            'status'       => $request->query('status'),
            'country'      => $request->query('country'),
            'source'       => $request->query('source'),
            'suppressed'   => $request->query('suppressed'),
            'unsubscribed' => $request->query('unsubscribed'),
            'sort'         => (string) $request->query('sort', 'created_at'),
            'direction'    => (string) $request->query('direction', 'desc'),
            'page'         => (int) $request->query('page', 1),
            'per_page'     => (int) $request->query('per_page', 25),
        ];

        $result = ContactService::paginate($filters);

        return Response::json($result['items'], 200, [
            'total'      => $result['total'],
            'page'       => $result['page'],
            'per_page'   => $result['per_page'],
            'pages'      => $result['pages'],
            'counts'     => ContactService::counts(),
            'assignment' => $filters['assignment'],
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        Auth::requireUser();

        $contact = ContactService::find((int) $params['id']);
        if ($contact === null) {
            throw ApiException::notFound('Contact not found.');
        }

        $campaigns = Database::all(
            'SELECT r.campaign_id, c.name, r.status, r.accepted_at, r.delivered_at,
                    r.bounced_at, r.complained_at, r.first_opened_at, r.first_clicked_at
               FROM campaign_recipients r
               JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.contact_id = :id
           ORDER BY r.id DESC
              LIMIT 50',
            ['id' => (int) $params['id']]
        );

        return Response::json([
            'contact'             => $contact,
            'campaign_history'    => $campaigns,
            'suppression_history' => SuppressionService::history((string) $contact['email']),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = Auth::requireWriteAccess();

        $data = $this->validatePayload($request, true);
        $id = ContactService::create($data);

        Audit::log('contact.created', 'contact', $id, ['email' => $data['email']], (int) $user['id']);

        return Response::created(ContactService::find($id));
    }

    public function update(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];

        $data = $this->validatePayload($request, false);
        ContactService::update($id, $data);

        Audit::log('contact.updated', 'contact', $id, array_keys($data), (int) $user['id']);

        return Response::json(ContactService::find($id));
    }

    public function destroy(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];

        $contact = ContactService::find($id);
        if ($contact === null) {
            throw ApiException::notFound('Contact not found.');
        }

        ContactService::delete($id);
        // Suppression survives deletion: it is keyed on the email address.
        Audit::log('contact.deleted', 'contact', $id, ['email' => $contact['email']], (int) $user['id']);

        return Response::json(['deleted' => true]);
    }

    public function bulkDelete(Request $request): Response
    {
        $user = Auth::requireWriteAccess();

        $validator = Validator::make($request->body());
        $ids = $validator->intList('contact_ids', true, 10000);
        $validator->validate();

        $deleted = ContactService::deleteMany($ids ?? []);
        Audit::log('contact.bulk_deleted', 'contact', '', ['count' => $deleted], (int) $user['id']);

        return Response::json(['deleted' => $deleted]);
    }

    public function bulkAssign(Request $request): Response
    {
        $user = Auth::requireWriteAccess();

        $validator = Validator::make($request->body());
        $listId = $validator->int('list_id', true, 1);
        $ids = $validator->intList('contact_ids', false, 10000);
        $allMatching = $validator->bool('all_matching_filter', false);
        $validator->validate();

        if ($allMatching) {
            $filters = is_array($request->input('filters')) ? $request->input('filters') : [];
            $result = ListService::assignByFilter((int) $listId, $filters, (int) $user['id']);
        } else {
            if ($ids === null || $ids === []) {
                throw ApiException::validation(['contact_ids' => 'Select at least one contact.']);
            }
            $result = ListService::assign((int) $listId, $ids, (int) $user['id']);
        }

        Audit::log('contact.assigned', 'list', (int) $listId, $result, (int) $user['id']);

        return Response::json($result + ['list' => ListService::find((int) $listId)]);
    }

    public function meta(Request $request): Response
    {
        Auth::requireUser();

        return Response::json([
            'counts'    => ContactService::counts(),
            'countries' => ContactService::distinctValues('country'),
            'sources'   => ContactService::distinctValues('source'),
            'statuses'  => ['active', 'inactive', 'archived'],
        ]);
    }

    /**
     * Streams a CSV export.
     *
     * Every cell goes through Csv::escapeField, so a contact whose name was
     * imported as "=cmd|…" cannot execute when the file is opened in Excel.
     */
    public function export(Request $request): Response
    {
        $user = Auth::requireUser();

        $filters = [
            'assignment'   => (string) $request->input('assignment', 'any'),
            'list_id'      => $request->input('list_id'),
            'search'       => (string) $request->input('search', ''),
            'status'       => $request->input('status'),
            'country'      => $request->input('country'),
            'source'       => $request->input('source'),
            'suppressed'   => $request->input('suppressed'),
            'unsubscribed' => $request->input('unsubscribed'),
        ];

        [$where, $params] = ContactService::buildFilters($filters);

        $columns = [
            'id', 'email', 'first_name', 'last_name', 'company_name', 'website',
            'country', 'source', 'status', 'list_name', 'unsubscribed_at',
            'suppression_reason', 'created_at', 'updated_at',
        ];

        $filename = 'contacts-' . gmdate('Ymd-His') . '.csv';

        // Headers are sent directly: the body is streamed rather than buffered
        // so a large export does not exhaust the shared-hosting memory limit.
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel reads accents correctly
        echo Csv::formatRow($columns);

        $lastId = 0;
        $exported = 0;
        do {
            $rows = Database::all(
                "SELECT c.id, c.email, c.first_name, c.last_name, c.company_name, c.website,
                        c.country, c.source, c.status, l.name AS list_name, c.unsubscribed_at,
                        s.reason AS suppression_reason, c.created_at, c.updated_at
                   FROM contacts c
              LEFT JOIN list_contacts lc ON lc.contact_id = c.id
              LEFT JOIN lists l ON l.id = lc.list_id
              LEFT JOIN suppressions s ON s.normalized_email = c.normalized_email
                  WHERE {$where} AND c.id > :last_id
               ORDER BY c.id
                  LIMIT 1000",
                $params + ['last_id' => $lastId]
            );

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $exported++;
                echo Csv::formatRow(array_map(static fn (string $column) => $row[$column] ?? '', $columns));
            }

            if (function_exists('flush')) {
                flush();
            }
        } while (count($rows) === 1000);

        Audit::log('contact.exported', 'contact', '', ['rows' => $exported], (int) $user['id']);
        exit;
    }

    /** @return array<string,mixed> */
    private function validatePayload(Request $request, bool $creating): array
    {
        $validator = Validator::make($request->body());

        $data = [];
        $email = $validator->email('email', $creating);
        if ($email !== null) {
            $data['email'] = $email;
        }

        foreach ([
            'first_name'   => 120,
            'last_name'    => 120,
            'company_name' => 190,
            'country'      => 100,
            'source'       => 120,
        ] as $field => $max) {
            if ($validator->has($field)) {
                $data[$field] = $validator->string($field, false, $max, 0, '');
            }
        }
        if ($validator->has('website')) {
            $data['website'] = $validator->url('website', false, 255);
        }
        if ($validator->has('status')) {
            $data['status'] = $validator->enum('status', ['active', 'inactive', 'archived'], false, 'active');
        }
        if ($validator->has('custom_fields')) {
            $data['custom_fields'] = $validator->jsonObject('custom_fields');
        }

        $validator->validate();

        return $data;
    }
}
