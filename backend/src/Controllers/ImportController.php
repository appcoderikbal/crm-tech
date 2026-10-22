<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Http\Validator;
use Techzapp\Crm\Services\ImportService;
use Techzapp\Crm\Support\Csv;

final class ImportController
{
    public function upload(Request $request): Response
    {
        $user = Auth::requireWriteAccess();

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'])) {
            throw ApiException::validation(['file' => 'Choose a CSV file to upload.']);
        }

        return Response::created(ImportService::createFromUpload($file, (int) $user['id']));
    }

    public function show(Request $request, array $params): Response
    {
        Auth::requireUser();
        return Response::json(ImportService::describe((int) $params['id']));
    }

    public function start(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $jobId = (int) $params['id'];

        $validator = Validator::make($request->body());
        $duplicateMode = $validator->enum('duplicate_mode', ImportService::DUPLICATE_MODES, false, 'skip');
        $targetListId = $validator->int('target_list_id', false, 1);
        $hasHeader = $validator->bool('has_header', true);
        $validator->validate();

        $mapping = $this->validateMapping($request->input('mapping'));
        $customFields = $this->validateCustomFields($request->input('custom_fields'));

        $job = ImportService::start(
            $jobId,
            $mapping,
            (string) $duplicateMode,
            $targetListId,
            $hasHeader,
            $customFields,
            (int) $user['id']
        );

        return Response::json(['job' => $job]);
    }

    /**
     * Processes one bounded chunk and returns progress.
     *
     * The browser polls this so the user sees a progress bar, and the cron job
     * runs the same code — whichever gets there first makes progress, and the
     * per-job lock stops them colliding.
     */
    public function process(Request $request, array $params): Response
    {
        Auth::requireWriteAccess();

        // Bounded so the HTTP request always returns quickly on shared hosting.
        $job = ImportService::processChunk((int) $params['id'], microtime(true) + 10);

        return Response::json(['job' => $job]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        return Response::json(['job' => ImportService::cancel((int) $params['id'], (int) $user['id'])]);
    }

    public function history(Request $request): Response
    {
        Auth::requireUser();

        $result = ImportService::history(
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 20)
        );

        return Response::json($result['items'], 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $result['pages'],
        ]);
    }

    public function errors(Request $request, array $params): Response
    {
        Auth::requireUser();

        $jobId = (int) $params['id'];
        $rows = ImportService::errors($jobId, 5000);

        if ((string) $request->query('format', 'json') !== 'csv') {
            return Response::json($rows, 200, ['total' => count($rows)]);
        }

        $user = Auth::requireUser();
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="import-' . $jobId . '-errors.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo "\xEF\xBB\xBF";
        echo Csv::formatRow(['row_number', 'email', 'error_code', 'error_message', 'raw_row']);
        foreach ($rows as $row) {
            echo Csv::formatRow([
                $row['csv_row_number'],
                $row['email'],
                $row['error_code'],
                $row['error_message'],
                (string) $row['raw_row_json'],
            ]);
        }

        Audit::log('import.errors_exported', 'import_job', $jobId, ['rows' => count($rows)], (int) $user['id']);
        exit;
    }

    /** @return array<string,int> */
    private function validateMapping(mixed $mapping): array
    {
        if (!is_array($mapping) || $mapping === []) {
            throw ApiException::validation(['mapping' => 'Map at least the email column.']);
        }

        $clean = [];
        foreach ($mapping as $field => $index) {
            if (!is_string($field) || !isset(ImportService::MAPPABLE_FIELDS[$field])) {
                continue;
            }
            if ($index === null || $index === '') {
                continue;
            }
            if (!is_numeric($index) || (int) $index < 0 || (int) $index > 500) {
                throw ApiException::validation(['mapping' => "Invalid column index for {$field}."]);
            }
            $clean[$field] = (int) $index;
        }

        if (!isset($clean['email'])) {
            throw ApiException::validation(['mapping' => 'The email column must be mapped.']);
        }

        return $clean;
    }

    /** @return array<string,int> */
    private function validateCustomFields(mixed $customFields): array
    {
        if (!is_array($customFields)) {
            return [];
        }

        $clean = [];
        foreach ($customFields as $label => $index) {
            if (!is_string($label) || preg_match('/^[A-Za-z0-9_]{1,40}$/', $label) !== 1) {
                continue;
            }
            if (!is_numeric($index) || (int) $index < 0 || (int) $index > 500) {
                continue;
            }
            $clean[strtolower($label)] = (int) $index;
            if (count($clean) >= 20) {
                break;
            }
        }

        return $clean;
    }
}
