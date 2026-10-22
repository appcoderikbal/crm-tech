<?php

declare(strict_types=1);

namespace TechzappCrm\Services;

use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Config;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Lock;
use TechzappCrm\Core\Logger;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Settings;
use TechzappCrm\Core\Storage;
use TechzappCrm\Support\Csv;
use TechzappCrm\Support\EmailAddress;
use TechzappCrm\Support\Tokens;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunked CSV import.
 *
 * A 40,000-row file is never loaded into memory or inserted in one statement.
 * The uploaded file is stored outside the web root; each run processes a
 * bounded number of rows, records the byte offset it reached, and returns. The
 * next run — from the browser polling or from cron — resumes at that offset, so
 * an interrupted import continues rather than restarting.
 */
final class ImportService
{
    /** Contact fields a CSV column can be mapped to. */
    public const MAPPABLE_FIELDS = [
        'email'        => 'Email address',
        'first_name'   => 'First name',
        'last_name'    => 'Last name',
        'company_name' => 'Company name',
        'website'      => 'Website',
        'country'      => 'Country',
        'source'       => 'Source',
    ];

    public const DUPLICATE_MODES = ['skip', 'update', 'new_only'];

    /**
     * Stores an uploaded file and creates the job in "uploaded" state.
     *
     * @param array{name:string, tmp_name:string, size:int, error:int} $file
     * @return array<string,mixed>
     */
    public static function createFromUpload(array $file, ?int $userId): array
    {
        self::assertUploadOk($file);

        $directory = Config::storagePath('imports');

        try {
            Storage::ensureProtectedDirectory($directory);
        } catch (Throwable) {
            throw CrmException::unprocessable(
                __('The import folder could not be created. Check that wp-content/uploads is writable.', 'techzapp-crm')
            );
        }

        // The filename carries a per-install random suffix, so an upload is not
        // reachable by guessing even on a server that ignores .htaccess.
        $storedName = Storage::privateFilename('import', 'csv');
        $storedPath = $directory . '/' . $storedName;

        // move_uploaded_file() is the correct primitive here rather than
        // wp_handle_upload(): the file must land outside the media library,
        // keep its private name, and never become an attachment.
        if (!move_uploaded_file($file['tmp_name'], $storedPath) && !@rename($file['tmp_name'], $storedPath)) {
            throw CrmException::unprocessable(
                __('The uploaded file could not be saved. Check the permissions on wp-content/uploads.', 'techzapp-crm')
            );
        }

        @chmod($storedPath, 0600);

        $delimiter = Csv::detectDelimiter($storedPath);
        $header = Csv::readHeader($storedPath, $delimiter);
        if ($header === []) {
            @unlink($storedPath);
            throw CrmException::unprocessable('The file appears to be empty.');
        }

        Database::execute(
            'INSERT INTO {import_jobs}
                (original_filename, stored_path, file_size_bytes, file_checksum, delimiter_char,
                 has_header, status, created_by, created_at, updated_at)
             VALUES (:name, :path, :size, :checksum, :delimiter, 1, :status, :user, :created_at, :updated_at)',
            [
                'name'      => substr(basename($file['name']), 0, 255),
                'path'      => $storedPath,
                'size'      => (int) $file['size'],
                'checksum'  => (string) hash_file('sha256', $storedPath),
                'delimiter' => $delimiter,
                'status'    => 'uploaded',
                'user'      => $userId,
                'created_at' => Database::nowUtc(),
                'updated_at' => Database::nowUtc(),
            ]
        );

        $jobId = Database::lastInsertId();

        Audit::log('import.uploaded', 'import_job', $jobId, [
            'filename' => $file['name'],
            'bytes'    => (int) $file['size'],
        ], $userId);

        return self::describe($jobId);
    }

    /** @return array<string,mixed> job description including header and preview */
    public static function describe(int $jobId): array
    {
        $job = self::findOrFail($jobId);

        $header = [];
        $preview = [];
        if (is_file($job['stored_path'])) {
            $header = Csv::readHeader($job['stored_path'], (string) $job['delimiter_char']);
            $preview = Csv::preview($job['stored_path'], (string) $job['delimiter_char'], (bool) $job['has_header'], 8);
        }

        return [
            'job'              => self::present($job),
            'header'           => $header,
            'preview'          => $preview,
            'suggested_mapping' => self::suggestMapping($header),
            'mappable_fields'  => self::MAPPABLE_FIELDS,
        ];
    }

    /**
     * Guesses a column mapping from the header labels.
     *
     * @param list<string> $header
     * @return array<string,int> field => column index
     */
    public static function suggestMapping(array $header): array
    {
        $patterns = [
            'email'        => ['email', 'e-mail', 'emailaddress', 'mail', 'contactemail'],
            'first_name'   => ['firstname', 'first', 'givenname', 'fname', 'forename'],
            'last_name'    => ['lastname', 'last', 'surname', 'lname', 'familyname'],
            'company_name' => ['company', 'companyname', 'organisation', 'organization', 'business', 'account'],
            'website'      => ['website', 'url', 'site', 'domain', 'web'],
            'country'      => ['country', 'countryname', 'nation'],
            'source'       => ['source', 'leadsource', 'origin', 'channel'],
        ];

        $mapping = [];
        foreach ($header as $index => $label) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $label) ?? '');
            if ($key === '') {
                continue;
            }
            foreach ($patterns as $field => $candidates) {
                if (isset($mapping[$field])) {
                    continue;
                }
                if (in_array($key, $candidates, true)) {
                    $mapping[$field] = $index;
                    continue 2;
                }
            }
        }

        // Second pass: partial matches for anything still unmapped.
        foreach ($header as $index => $label) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $label) ?? '');
            foreach ($patterns as $field => $candidates) {
                if (isset($mapping[$field]) || $key === '') {
                    continue;
                }
                foreach ($candidates as $candidate) {
                    if (str_contains($key, $candidate)) {
                        $mapping[$field] = $index;
                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Confirms the mapping and queues the job for processing.
     *
     * @param array<string,int> $mapping field => column index
     * @param array<string,int> $customFields label => column index
     */
    public static function start(
        int $jobId,
        array $mapping,
        string $duplicateMode,
        ?int $targetListId,
        bool $hasHeader,
        array $customFields,
        ?int $userId
    ): array {
        $job = self::findOrFail($jobId);

        if (!in_array($job['status'], ['uploaded', 'mapped', 'failed'], true)) {
            throw CrmException::conflict("This import is already {$job['status']}.");
        }
        if (!isset($mapping['email'])) {
            throw CrmException::validation(['mapping' => 'The email column must be mapped.']);
        }
        if (!in_array($duplicateMode, self::DUPLICATE_MODES, true)) {
            throw CrmException::validation(['duplicate_mode' => 'Unknown duplicate handling mode.']);
        }
        if ($targetListId !== null && ListService::find($targetListId) === null) {
            throw CrmException::validation(['target_list_id' => 'The selected list no longer exists.']);
        }

        $totalRows = is_file($job['stored_path'])
            ? Csv::countDataRows($job['stored_path'], (string) $job['delimiter_char'], $hasHeader)
            : 0;

        Database::execute(
            "UPDATE {import_jobs}
                SET mapping_json = :mapping, options_json = :options, duplicate_mode = :mode,
                    target_list_id = :list, has_header = :header, total_rows = :total,
                    status = 'queued', processed_rows = 0, inserted_rows = 0, updated_rows = 0,
                    skipped_rows = 0, failed_rows = 0, resume_byte_offset = 0, resume_row_number = 0,
                    last_error = ''
              WHERE id = :id",
            [
                'mapping' => wp_json_encode($mapping),
                'options' => wp_json_encode(['custom_fields' => $customFields]),
                'mode'    => $duplicateMode,
                'list'    => $targetListId,
                'header'  => $hasHeader ? 1 : 0,
                'total'   => $totalRows,
                'id'      => $jobId,
            ]
        );

        Database::execute('DELETE FROM {import_row_errors} WHERE import_job_id = :id', ['id' => $jobId]);

        Audit::log('import.started', 'import_job', $jobId, [
            'duplicate_mode' => $duplicateMode,
            'target_list_id' => $targetListId,
            'total_rows'     => $totalRows,
        ], $userId);

        return self::present(self::findOrFail($jobId));
    }

    /**
     * Processes the next chunk of a job.
     *
     * @return array<string,mixed> the job state after this chunk
     */
    public static function processChunk(int $jobId, ?float $deadline = null, ?int $maxRows = null): array
    {
        $lock = Lock::acquire('import:' . $jobId, 120, 'pid ' . getmypid());
        if ($lock === null) {
            // Another worker (cron or a parallel poll) holds it; report progress.
            return self::present(self::findOrFail($jobId)) + ['locked' => true];
        }

        try {
            $job = self::findOrFail($jobId);

            if (!in_array($job['status'], ['queued', 'processing'], true)) {
                return self::present($job);
            }
            if (!is_file($job['stored_path'])) {
                self::fail($jobId, 'The uploaded file is no longer available. Upload it again.');
                return self::present(self::findOrFail($jobId));
            }

            Database::execute(
                "UPDATE {import_jobs}
                    SET status = 'processing',
                        started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                        heartbeat_at = UTC_TIMESTAMP()
                  WHERE id = :id",
                ['id' => $jobId]
            );

            $mapping = json_decode((string) $job['mapping_json'], true);
            $options = json_decode((string) $job['options_json'], true);
            $customFields = is_array($options['custom_fields'] ?? null) ? $options['custom_fields'] : [];
            if (!is_array($mapping) || !isset($mapping['email'])) {
                self::fail($jobId, 'The column mapping is missing or invalid.');
                return self::present(self::findOrFail($jobId));
            }

            $batchRows = $maxRows ?? max(50, Settings::int('import_batch_rows', 500));
            $deadline ??= microtime(true) + 20;

            $rows = [];
            $stream = Csv::streamBatch(
                $job['stored_path'],
                (int) $job['resume_byte_offset'],
                (int) $job['resume_row_number'],
                $batchRows,
                (string) $job['delimiter_char'],
                (bool) $job['has_header'],
                static function (array $row, int $rowNumber) use (&$rows): void {
                    $rows[] = ['row' => $row, 'number' => $rowNumber];
                },
                $deadline
            );

            $counters = $rows === []
                ? ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0]
                : self::processRows($jobId, $rows, $mapping, $customFields, (string) $job['duplicate_mode'], $job['target_list_id'] === null ? null : (int) $job['target_list_id']);

            Database::execute(
                'UPDATE {import_jobs}
                    SET processed_rows = processed_rows + :processed,
                        inserted_rows = inserted_rows + :inserted,
                        updated_rows = updated_rows + :updated,
                        skipped_rows = skipped_rows + :skipped,
                        failed_rows = failed_rows + :failed,
                        resume_byte_offset = :offset,
                        resume_row_number = :row_number,
                        heartbeat_at = UTC_TIMESTAMP()
                  WHERE id = :id',
                [
                    'processed'  => count($rows),
                    'inserted'   => $counters['inserted'],
                    'updated'    => $counters['updated'],
                    'skipped'    => $counters['skipped'],
                    'failed'     => $counters['failed'],
                    'offset'     => $stream['offset'],
                    'row_number' => $stream['row_number'],
                    'id'         => $jobId,
                ]
            );

            if ($stream['eof'] || $rows === []) {
                self::complete($jobId);
            }

            return self::present(self::findOrFail($jobId));
        } catch (Throwable $e) {
            Logger::exception('import.chunk_failed', $e, ['job_id' => $jobId]);
            self::fail($jobId, $e->getMessage());
            return self::present(self::findOrFail($jobId));
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<array{row:list<string>, number:int}> $rows
     * @param array<string,int> $mapping
     * @param array<string,int> $customFields
     * @return array{inserted:int, updated:int, skipped:int, failed:int}
     */
    private static function processRows(
        int $jobId,
        array $rows,
        array $mapping,
        array $customFields,
        string $duplicateMode,
        ?int $targetListId
    ): array {
        $counters = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];
        $valid = [];
        $seenInBatch = [];

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $rowNumber = $entry['number'];

            $email = trim((string) ($row[$mapping['email']] ?? ''));
            if ($email === '') {
                $counters['failed']++;
                $errors[] = [$rowNumber, '', 'missing_email', 'No email address in the mapped column.', $row];
                continue;
            }
            if (!EmailAddress::isValid($email)) {
                $counters['failed']++;
                $errors[] = [$rowNumber, $email, 'invalid_email', 'The email address is not valid.', $row];
                continue;
            }

            $normalized = EmailAddress::normalize($email);
            if (isset($seenInBatch[$normalized])) {
                $counters['skipped']++;
                $errors[] = [$rowNumber, $email, 'duplicate_in_file', 'This address appears earlier in the same file.', $row];
                continue;
            }
            $seenInBatch[$normalized] = true;

            $custom = [];
            foreach ($customFields as $label => $index) {
                $value = trim((string) ($row[$index] ?? ''));
                if ($value !== '') {
                    $custom[strtolower((string) $label)] = mb_substr($value, 0, 1000);
                }
            }

            $valid[$normalized] = [
                'row_number' => $rowNumber,
                'email'      => $email,
                'normalized' => $normalized,
                'fields'     => [
                    'first_name'   => self::cell($row, $mapping, 'first_name', 120),
                    'last_name'    => self::cell($row, $mapping, 'last_name', 120),
                    'company_name' => self::cell($row, $mapping, 'company_name', 190),
                    'website'      => self::cell($row, $mapping, 'website', 255),
                    'country'      => self::cell($row, $mapping, 'country', 100),
                    'source'       => self::cell($row, $mapping, 'source', 120) ?: 'import',
                ],
                'custom'     => $custom,
            ];
        }

        if ($valid !== []) {
            $existing = self::lookupExisting(array_keys($valid));

            $toInsert = [];
            $toUpdate = [];
            foreach ($valid as $normalized => $record) {
                if (isset($existing[$normalized])) {
                    if ($duplicateMode === 'update') {
                        $toUpdate[$normalized] = $record;
                    } else {
                        $counters['skipped']++;
                    }
                } else {
                    $toInsert[$normalized] = $record;
                }
            }

            $counters['inserted'] += self::insertContacts($toInsert);
            $counters['updated'] += self::updateContacts($toUpdate, $existing);

            if ($targetListId !== null) {
                $ids = array_values($existing);
                foreach (self::lookupExisting(array_keys($toInsert)) as $id) {
                    $ids[] = $id;
                }
                if ($ids !== []) {
                    ListService::assign($targetListId, array_map('intval', $ids), null);
                }
            }
        }

        if ($errors !== []) {
            self::recordErrors($jobId, $errors);
        }

        return $counters;
    }

    /**
     * @param list<string> $normalizedEmails
     * @return array<string,int> normalized email => contact id
     */
    private static function lookupExisting(array $normalizedEmails): array
    {
        if ($normalizedEmails === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($normalizedEmails, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::all(
                "SELECT id, normalized_email FROM {contacts} WHERE normalized_email IN ({$placeholders})",
                $chunk
            );
            foreach ($rows as $row) {
                $found[(string) $row['normalized_email']] = (int) $row['id'];
            }
        }
        return $found;
    }

    /** @param array<string,array<string,mixed>> $records */
    private static function insertContacts(array $records): int
    {
        if ($records === []) {
            return 0;
        }

        $inserted = 0;
        $now = Database::nowUtc();

        foreach (array_chunk($records, 200, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $record) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $record['email'],
                    $record['normalized'],
                    $record['fields']['first_name'],
                    $record['fields']['last_name'],
                    $record['fields']['company_name'],
                    $record['fields']['website'],
                    $record['fields']['country'],
                    $record['fields']['source'],
                    $record['custom'] === [] ? null : wp_json_encode($record['custom'], JSON_UNESCAPED_SLASHES),
                    Tokens::contact(),
                    $now,
                    $now
                );
            }

            // INSERT IGNORE protects against a concurrent import inserting the
            // same address between the lookup and this statement.
            $inserted += Database::execute(
                'INSERT IGNORE INTO {contacts}
                    (email, normalized_email, first_name, last_name, company_name, website,
                     country, source, custom_fields_json, unsubscribe_token, created_at, updated_at)
                 VALUES ' . implode(',', $values),
                $params
            );
        }

        return $inserted;
    }

    /**
     * @param array<string,array<string,mixed>> $records
     * @param array<string,int> $existing
     */
    private static function updateContacts(array $records, array $existing): int
    {
        if ($records === []) {
            return 0;
        }

        $updated = 0;
        foreach ($records as $normalized => $record) {
            $id = $existing[$normalized] ?? null;
            if ($id === null) {
                continue;
            }

            // Only non-empty incoming values overwrite stored data, and
            // unsubscribed_at / suppression state is deliberately untouched:
            // re-importing a contact must never resurrect an opt-out.
            Database::execute(
                'UPDATE {contacts}
                    SET first_name   = IF(:first <> "", :first2, first_name),
                        last_name    = IF(:last <> "", :last2, last_name),
                        company_name = IF(:company <> "", :company2, company_name),
                        website      = IF(:website <> "", :website2, website),
                        country      = IF(:country <> "", :country2, country),
                        source       = IF(:source <> "", :source2, source),
                        custom_fields_json = COALESCE(:custom, custom_fields_json),
                        updated_at = :updated_at
                  WHERE id = :id',
                [
                    'first'    => $record['fields']['first_name'],
                    'first2'   => $record['fields']['first_name'],
                    'last'     => $record['fields']['last_name'],
                    'last2'    => $record['fields']['last_name'],
                    'company'  => $record['fields']['company_name'],
                    'company2' => $record['fields']['company_name'],
                    'website'  => $record['fields']['website'],
                    'website2' => $record['fields']['website'],
                    'country'  => $record['fields']['country'],
                    'country2' => $record['fields']['country'],
                    'source'   => $record['fields']['source'],
                    'source2'  => $record['fields']['source'],
                    'custom'     => $record['custom'] === [] ? null : wp_json_encode($record['custom'], JSON_UNESCAPED_SLASHES),
                    'updated_at' => Database::nowUtc(),
                    'id'         => (int) $id,
                ]
            );
            $updated++;
        }

        return $updated;
    }

    /** @param list<array{0:int,1:string,2:string,3:string,4:list<string>}> $errors */
    private static function recordErrors(int $jobId, array $errors): void
    {
        foreach (array_chunk($errors, 200) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as [$rowNumber, $email, $code, $message, $raw]) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $jobId,
                    $rowNumber,
                    substr($email, 0, 255),
                    $code,
                    substr($message, 0, 400),
                    wp_json_encode(array_slice($raw, 0, 30), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    Database::nowUtc()
                );
            }

            Database::execute(
                'INSERT INTO {import_row_errors}
                    (import_job_id, csv_row_number, email, error_code, error_message, raw_row_json, created_at)
                 VALUES ' . implode(',', $values),
                $params
            );
        }
    }

    private static function complete(int $jobId): void
    {
        Database::execute(
            "UPDATE {import_jobs}
                SET status = 'completed', completed_at = UTC_TIMESTAMP()
              WHERE id = :id AND status = 'processing'",
            ['id' => $jobId]
        );
        Audit::log('import.completed', 'import_job', $jobId, []);
    }

    private static function fail(int $jobId, string $reason): void
    {
        Database::execute(
            "UPDATE {import_jobs}
                SET status = 'failed', last_error = :error, completed_at = UTC_TIMESTAMP()
              WHERE id = :id",
            ['error' => substr($reason, 0, 500), 'id' => $jobId]
        );
    }

    public static function cancel(int $jobId, ?int $userId): array
    {
        $job = self::findOrFail($jobId);
        if (in_array($job['status'], ['completed', 'cancelled'], true)) {
            throw CrmException::conflict("This import is already {$job['status']}.");
        }

        Database::execute(
            "UPDATE {import_jobs} SET status = 'cancelled', completed_at = UTC_TIMESTAMP() WHERE id = :id",
            ['id' => $jobId]
        );
        Audit::log('import.cancelled', 'import_job', $jobId, [], $userId);

        return self::present(self::findOrFail($jobId));
    }

    /** @return list<int> job ids that still need processing */
    public static function pendingJobs(int $limit = 5): array
    {
        $rows = Database::all(
            "SELECT id FROM {import_jobs}
              WHERE status IN ('queued','processing')
           ORDER BY id
              LIMIT " . max(1, min(20, $limit))
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @return array{items:list<array<string,mixed>>, total:int, page:int, per_page:int, pages:int} */
    public static function history(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar('SELECT COUNT(*) FROM {import_jobs}');
        $rows = Database::all(
            "SELECT j.*, u.email AS created_by_email, l.name AS target_list_name
               FROM {import_jobs} j
          LEFT JOIN users u ON u.id = j.created_by
          LEFT JOIN {lists} l ON l.id = j.target_list_id
           ORDER BY j.id DESC
              LIMIT {$perPage} OFFSET {$offset}"
        );

        return [
            'items'    => array_map([self::class, 'present'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function errors(int $jobId, int $limit = 500): array
    {
        return Database::all(
            'SELECT csv_row_number, email, error_code, error_message, raw_row_json
               FROM {import_row_errors}
              WHERE import_job_id = :id
           ORDER BY csv_row_number
              LIMIT ' . max(1, min(5000, $limit)),
            ['id' => $jobId]
        );
    }

    /** Deletes expired upload files. Rows are kept for the import history. */
    public static function cleanupExpiredFiles(): array
    {
        $hours = max(1, Settings::int('import_retention_hours', 48));
        $rows = Database::all(
            "SELECT id, stored_path FROM {import_jobs}
              WHERE stored_path <> ''
                AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR)",
            ['hours' => $hours]
        );

        $deleted = 0;
        foreach ($rows as $row) {
            $path = (string) $row['stored_path'];
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
            Database::execute("UPDATE {import_jobs} SET stored_path = '' WHERE id = :id", ['id' => (int) $row['id']]);
        }

        return ['files_deleted' => $deleted, 'jobs_checked' => count($rows)];
    }

    /** @return array<string,mixed> */
    public static function findOrFail(int $jobId): array
    {
        $job = Database::first('SELECT * FROM {import_jobs} WHERE id = :id', ['id' => $jobId]);
        if ($job === null) {
            throw CrmException::notFound('Import job not found.');
        }
        return $job;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public static function present(array $job): array
    {
        $total = (int) $job['total_rows'];
        $processed = (int) $job['processed_rows'];

        return [
            'id'                => (int) $job['id'],
            'original_filename' => (string) $job['original_filename'],
            'status'            => (string) $job['status'],
            'duplicate_mode'    => (string) $job['duplicate_mode'],
            'target_list_id'    => $job['target_list_id'] === null ? null : (int) $job['target_list_id'],
            'target_list_name'  => $job['target_list_name'] ?? null,
            'file_size_bytes'   => (int) $job['file_size_bytes'],
            'total_rows'        => $total,
            'processed_rows'    => $processed,
            'inserted_rows'     => (int) $job['inserted_rows'],
            'updated_rows'      => (int) $job['updated_rows'],
            'skipped_rows'      => (int) $job['skipped_rows'],
            'failed_rows'       => (int) $job['failed_rows'],
            'progress_percent'  => $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 0,
            'has_file'          => (string) $job['stored_path'] !== '' && is_file((string) $job['stored_path']),
            'last_error'        => (string) $job['last_error'],
            'created_by_email'  => $job['created_by_email'] ?? null,
            'started_at'        => $job['started_at'],
            'completed_at'      => $job['completed_at'],
            'created_at'        => $job['created_at'],
            'mapping'           => $job['mapping_json'] === null ? [] : (json_decode((string) $job['mapping_json'], true) ?: []),
        ];
    }

    private static function cell(array $row, array $mapping, string $field, int $maxLength): string
    {
        if (!isset($mapping[$field])) {
            return '';
        }
        $value = trim((string) ($row[$mapping[$field]] ?? ''));
        return mb_substr($value, 0, $maxLength);
    }

    /** @param array{name:string, tmp_name:string, size:int, error:int} $file */
    private static function assertUploadOk(array $file): void
    {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw CrmException::unprocessable(match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the upload size limit configured for this server.',
                UPLOAD_ERR_PARTIAL  => 'The upload was interrupted. Try again.',
                UPLOAD_ERR_NO_FILE  => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload.',
                default => 'The upload failed.',
            });
        }

        $maxBytes = max(1, Settings::int('import_max_file_mb', 64)) * 1024 * 1024;
        if ((int) $file['size'] > $maxBytes) {
            throw CrmException::unprocessable(
                'The file is larger than the configured limit of ' . Settings::int('import_max_file_mb', 64) . ' MB.'
            );
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw CrmException::unprocessable('Only .csv files can be imported.');
        }

        // Content check: reject anything that is clearly not text.
        $handle = @fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            throw CrmException::unprocessable('The uploaded file could not be read.');
        }
        $sample = (string) fread($handle, 4096);
        fclose($handle);

        if ($sample !== '' && str_contains($sample, "\0")) {
            throw CrmException::unprocessable('The file does not look like a CSV document.');
        }
    }
}
