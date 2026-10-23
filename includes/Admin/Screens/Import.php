<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\ImportService;
use TechzappCrm\Services\ListService;
use TechzappCrm\Support\Csv;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSV import: upload, map columns, then process in chunks.
 *
 * A 40,000-row file is never loaded into the browser or inserted in one
 * statement. Each chunk records the byte offset it reached, so an interrupted
 * import resumes rather than restarting — and the background worker finishes
 * it whether or not this tab stays open.
 */
final class Import extends Screen
{
    public function render(): void
    {
        Capabilities::requireManage();

        $jobId = $this->intInput('job');
        $job = null;

        if ($jobId > 0) {
            $job = ImportService::describe($jobId);
        }

        View::open(__('Import contacts', 'techzapp-crm'));

        if ($job === null) {
            $this->renderUploadStep();
        } elseif (in_array($job['job']['status'], ['uploaded', 'mapped'], true)) {
            $this->renderMappingStep($job);
        } else {
            $this->renderProgressStep($job['job']);
        }

        $this->renderHistory();

        View::close();
    }

    // ------------------------------------------------------------- step one

    private function renderUploadStep(): void
    {
        $maxMb = Settings::int('import_max_file_mb', 64);
        $phpMax = (string) ini_get('upload_max_filesize');

        View::cardOpen(
            __('Upload a CSV file', 'techzapp-crm'),
            __('Comma or semicolon separated, UTF-8, with a header row. The delimiter is detected automatically.', 'techzapp-crm')
        );

        echo '<form method="post" enctype="multipart/form-data">';
        View::formFields('techzapp-crm-import', 'upload', 'techzapp_crm_import_upload');

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="import_file">' . esc_html__('CSV file', 'techzapp-crm') . '</label></th><td>';
        echo '<input type="file" id="import_file" name="file" accept=".csv,.txt,text/csv,text/plain" required>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: plugin limit, 2: PHP limit */
            __('Up to %1$d MB. PHP on this server accepts at most %2$s.', 'techzapp-crm'),
            $maxMb,
            $phpMax
        )) . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';

        submit_button(__('Upload and continue', 'techzapp-crm'));
        echo '</form>';

        echo '<h3>' . esc_html__('What the file should look like', 'techzapp-crm') . '</h3>';
        echo '<p>' . esc_html__('Only the email column is required. Any other column can be mapped to a contact field, or stored as a custom field you can use in campaigns.', 'techzapp-crm') . '</p>';
        echo '<pre class="techzapp-code-sample">'
            . esc_html("EMAIL;ADDED_TIME;MODIFIED_TIME\nmichelle@example.com;07-10-2026;14-10-2026\njudd@example.com;07-10-2026;14-10-2026")
            . '</pre>';

        echo '<p class="description">'
            . esc_html__('Addresses are normalised before comparison, so the same address in different capitalisation is never imported twice. An address that previously unsubscribed or complained stays suppressed — re-importing never makes it mailable again.', 'techzapp-crm')
            . '</p>';

        View::cardClose();
    }

    // ------------------------------------------------------------- step two

    /** @param array<string,mixed> $description */
    private function renderMappingStep(array $description): void
    {
        $job = $description['job'];
        $header = $description['header'];
        $preview = $description['preview'];
        $suggested = $description['suggested_mapping'];
        $fields = $description['mappable_fields'];

        View::cardOpen(
            __('Map your columns', 'techzapp-crm'),
            sprintf(
                /* translators: 1: filename, 2: file size, 3: row count */
                __('%1$s · %2$s · %3$s rows', 'techzapp-crm'),
                $job['original_filename'],
                View::bytes((int) $job['file_size_bytes']),
                View::number((int) $job['total_rows'])
            )
        );

        echo '<form method="post">';
        View::formFields('techzapp-crm-import', 'start', 'techzapp_crm_import_start');
        printf('<input type="hidden" name="job" value="%d">', (int) $job['id']);

        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ($fields as $field => $label) {
            $selected = $suggested[$field] ?? null;

            printf('<tr><th scope="row"><label for="map_%s">%s%s</label></th><td>',
                esc_attr($field),
                esc_html($label),
                $field === 'email' ? ' <span class="description">(' . esc_html__('required', 'techzapp-crm') . ')</span>' : ''
            );

            printf('<select id="map_%s" name="mapping[%s]">', esc_attr($field), esc_attr($field));
            printf('<option value="">%s</option>', esc_html__('Not imported', 'techzapp-crm'));

            foreach ($header as $index => $column) {
                printf(
                    '<option value="%d"%s>%s</option>',
                    (int) $index,
                    selected($selected, $index, false),
                    esc_html($column !== '' ? $column : sprintf(
                        /* translators: %d: column number */
                        __('Column %d', 'techzapp-crm'),
                        (int) $index + 1
                    ))
                );
            }

            echo '</select></td></tr>';
        }

        // Unmapped columns can be kept as custom fields.
        $mapped = array_values(array_filter($suggested, static fn ($value): bool => $value !== null));

        echo '<tr><th scope="row">' . esc_html__('Keep other columns', 'techzapp-crm') . '</th><td>';
        $offered = 0;

        foreach ($header as $index => $column) {
            if (in_array($index, $mapped, true)) {
                continue;
            }

            $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $column !== '' ? $column : 'column_' . ((int) $index + 1)));
            $key = trim($key, '_') ?: 'column_' . ((int) $index + 1);
            $offered++;

            printf(
                '<label class="techzapp-checkbox"><input type="checkbox" name="custom_fields[%s]" value="%d" checked> %s <code>{{%s}}</code></label><br>',
                esc_attr($key),
                (int) $index,
                esc_html($column !== '' ? $column : sprintf(__('Column %d', 'techzapp-crm'), (int) $index + 1)),
                esc_html($key)
            );
        }

        if ($offered === 0) {
            echo '<span class="description">' . esc_html__('Every column is mapped to a contact field.', 'techzapp-crm') . '</span>';
        } else {
            echo '<p class="description">' . esc_html__('Stored as custom fields and usable in campaigns with the token shown.', 'techzapp-crm') . '</p>';
        }

        echo '</td></tr>';

        // Duplicate handling.
        $mode = 'skip';
        echo '<tr><th scope="row"><label for="duplicate_mode">' . esc_html__('When an address already exists', 'techzapp-crm') . '</label></th><td>';
        echo '<select id="duplicate_mode" name="duplicate_mode">';
        foreach ([
            'skip'     => __('Skip the row, keep the stored contact (recommended)', 'techzapp-crm'),
            'update'   => __('Update the stored contact with the new values', 'techzapp-crm'),
            'new_only' => __('Import new contacts only', 'techzapp-crm'),
        ] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($mode, $value, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('A duplicate never creates a second contact record. "Update" only overwrites with non-empty values, so a blank cell cannot erase stored data.', 'techzapp-crm') . '</p>';
        echo '</td></tr>';

        // Target list.
        echo '<tr><th scope="row"><label for="target_list_id">' . esc_html__('Assign to list', 'techzapp-crm') . '</label></th><td>';
        echo '<select id="target_list_id" name="target_list_id">';
        printf('<option value="">%s</option>', esc_html__('Leave unassigned', 'techzapp-crm'));

        foreach (ListService::all() as $list) {
            printf(
                '<option value="%d">%s (%s)</option>',
                (int) $list['id'],
                esc_html((string) $list['name']),
                esc_html(View::number((int) $list['contact_count']))
            );
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__('Optional. Assigning moves a contact off any list it is already on — a contact belongs to one list at a time.', 'techzapp-crm') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Header row', 'techzapp-crm') . '</th><td>';
        echo '<label><input type="checkbox" name="has_header" value="1" checked> '
            . esc_html__('The first row contains column names', 'techzapp-crm') . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button(__('Start import', 'techzapp-crm'));
        printf(
            '<a href="%s" class="button">%s</a>',
            esc_url(Menu::url('techzapp-crm-import')),
            esc_html__('Choose a different file', 'techzapp-crm')
        );
        echo '</form>';

        View::cardClose();

        // Preview.
        if ($preview !== []) {
            View::cardOpen(__('Preview', 'techzapp-crm'), __('The first rows, as the importer reads them.', 'techzapp-crm'));
            echo '<div class="techzapp-scroll"><table class="wp-list-table widefat striped"><thead><tr>';

            foreach ($header as $index => $column) {
                printf('<th>%s</th>', esc_html($column !== '' ? $column : sprintf(__('Column %d', 'techzapp-crm'), (int) $index + 1)));
            }

            echo '</tr></thead><tbody>';

            foreach ($preview as $row) {
                echo '<tr>';
                foreach ($header as $index => $_column) {
                    printf('<td>%s</td>', esc_html((string) ($row[$index] ?? '')));
                }
                echo '</tr>';
            }

            echo '</tbody></table></div>';
            View::cardClose();
        }
    }

    // ----------------------------------------------------------- step three

    /** @param array<string,mixed> $job */
    private function renderProgressStep(array $job): void
    {
        $running = in_array($job['status'], ['queued', 'processing'], true);

        View::cardOpen(
            $running
                ? __('Importing…', 'techzapp-crm')
                : ($job['status'] === 'completed'
                    ? __('Import complete', 'techzapp-crm')
                    : sprintf(
                        /* translators: %s: status */
                        __('Import %s', 'techzapp-crm'),
                        $job['status']
                    )),
            (string) $job['original_filename']
        );

        printf(
            '<div id="techzapp-import-progress" class="techzapp-progress" data-job="%d"><div class="techzapp-progress__bar%s" style="width:%d%%"></div></div>',
            (int) $job['id'],
            $running ? '' : ' is-done',
            (int) $job['progress_percent']
        );

        printf(
            '<p id="techzapp-import-status">%s</p>',
            esc_html(sprintf(
                /* translators: 1: processed rows, 2: total rows */
                __('%1$s of %2$s rows processed.', 'techzapp-crm'),
                View::number((int) $job['processed_rows']),
                View::number((int) $job['total_rows'])
            ))
        );

        View::statTiles([
            ['label' => __('Added', 'techzapp-crm'), 'value' => View::number((int) $job['inserted_rows']), 'tone' => 'ok'],
            ['label' => __('Updated', 'techzapp-crm'), 'value' => View::number((int) $job['updated_rows'])],
            ['label' => __('Skipped', 'techzapp-crm'), 'value' => View::number((int) $job['skipped_rows'])],
            [
                'label' => __('Failed', 'techzapp-crm'),
                'value' => View::number((int) $job['failed_rows']),
                'tone'  => (int) $job['failed_rows'] > 0 ? 'danger' : 'neutral',
            ],
        ]);

        // The counts above are refreshed in place by assets/admin.js.
        echo '<div class="screen-reader-text">';
        foreach (['processed_rows', 'inserted_rows', 'updated_rows', 'skipped_rows', 'failed_rows'] as $key) {
            printf('<span data-count="%s">%d</span>', esc_attr($key), (int) $job[$key]);
        }
        echo '</div>';

        if ($running) {
            echo '<p class="description">'
                . esc_html__('You can close this page — the background worker finishes the remaining rows on its own.', 'techzapp-crm')
                . '</p>';
        }

        if ($job['status'] === 'completed') {
            $message = sprintf(
                /* translators: %s: number of contacts */
                __('%s contact(s) added.', 'techzapp-crm'),
                View::number((int) $job['inserted_rows'])
            );
            if (!empty($job['target_list_name'])) {
                $message .= ' ' . sprintf(
                    /* translators: %s: list name */
                    __('Assigned to %s.', 'techzapp-crm'),
                    $job['target_list_name']
                );
            }
            View::notice($message, 'success');
        }

        if ($job['status'] === 'failed') {
            View::notice(
                $job['last_error'] !== ''
                    ? (string) $job['last_error']
                    : __('An unexpected error ended this import.', 'techzapp-crm'),
                'error'
            );
        }

        printf(
            '<p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
            esc_url(Menu::url('techzapp-crm-import')),
            esc_html__('Import another file', 'techzapp-crm'),
            esc_url(Menu::url('techzapp-crm-contacts', ['assignment' => 'any'])),
            esc_html__('View contacts', 'techzapp-crm')
        );

        View::cardClose();

        if ((int) $job['failed_rows'] > 0 && !$running) {
            $this->renderErrors((int) $job['id']);
        }
    }

    private function renderErrors(int $jobId): void
    {
        $errors = ImportService::errors($jobId, 200);

        View::cardOpen(
            sprintf(
                /* translators: %s: number of rows */
                __('%s row(s) could not be imported', 'techzapp-crm'),
                View::number(count($errors))
            )
        );

        printf(
            '<p><a href="%s" class="button">%s</a></p>',
            esc_url(View::actionUrl('techzapp-crm-import', 'errors_csv', 'techzapp_crm_import_errors', ['job' => $jobId])),
            esc_html__('Download error report (CSV)', 'techzapp-crm')
        );

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Row', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Email', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Problem', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (array_slice($errors, 0, 50) as $error) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $error['csv_row_number']),
                $error['email'] !== '' ? esc_html((string) $error['email']) : '<em>' . esc_html__('empty', 'techzapp-crm') . '</em>',
                esc_html((string) $error['error_message'])
            );
        }

        echo '</tbody></table>';

        if (count($errors) > 50) {
            echo '<p class="description">' . esc_html__('Showing the first 50. Download the report for the full list.', 'techzapp-crm') . '</p>';
        }

        View::cardClose();
    }

    private function renderHistory(): void
    {
        $history = ImportService::history(1, 10);

        if ($history['items'] === []) {
            return;
        }

        View::cardOpen(__('Recent imports', 'techzapp-crm'));

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('File', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Status', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Added', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Updated', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Skipped', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Failed', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('When', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($history['items'] as $item) {
            $tone = match ($item['status']) {
                'completed' => 'ok',
                'failed'    => 'danger',
                'cancelled' => 'outline',
                default     => 'brand',
            };

            printf(
                '<tr><td><a href="%s">%s</a><br><span class="description">%s</span></td>'
                . '<td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td>%s</td></tr>',
                esc_url(Menu::url('techzapp-crm-import', ['job' => (int) $item['id']])),
                esc_html((string) $item['original_filename']),
                esc_html(View::bytes((int) $item['file_size_bytes'])),
                View::badge((string) $item['status'], $tone),
                esc_html(View::number((int) $item['inserted_rows'])),
                esc_html(View::number((int) $item['updated_rows'])),
                esc_html(View::number((int) $item['skipped_rows'])),
                (int) $item['failed_rows'] > 0
                    ? '<strong class="tone-danger">' . esc_html(View::number((int) $item['failed_rows'])) . '</strong>'
                    : '0',
                esc_html(View::relative((string) $item['created_at']))
            );
        }

        echo '</tbody></table>';
        View::cardClose();
    }

    // --------------------------------------------------------------- actions

    public function handle(string $action): void
    {
        match ($action) {
            'upload'     => $this->handleUpload(),
            'start'      => $this->handleStart(),
            'errors_csv' => $this->handleErrorsCsv(),
            default      => null,
        };
    }

    private function handleUpload(): void
    {
        $this->authorise('techzapp_crm_import_upload');

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw CrmException::validation(['file' => __('Choose a CSV file to upload.', 'techzapp-crm')]);
        }

        // Only the keys ImportService validates are passed through.
        $file = [
            'name'     => sanitize_file_name((string) ($_FILES['file']['name'] ?? '')),
            'tmp_name' => (string) ($_FILES['file']['tmp_name'] ?? ''),
            'size'     => (int) ($_FILES['file']['size'] ?? 0),
            'error'    => (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE),
        ];

        $description = ImportService::createFromUpload($file, Capabilities::currentUserId());

        $this->redirect(
            'techzapp-crm-import',
            ['job' => (int) $description['job']['id']],
            __('File uploaded. Check the column mapping below.', 'techzapp-crm')
        );
    }

    private function handleStart(): void
    {
        $this->authorise('techzapp_crm_import_start');

        $jobId = $this->intInput('job');

        $mapping = [];
        if (isset($_POST['mapping']) && is_array($_POST['mapping'])) {
            foreach (wp_unslash($_POST['mapping']) as $field => $index) {
                if (!isset(ImportService::MAPPABLE_FIELDS[$field]) || $index === '') {
                    continue;
                }
                $mapping[(string) $field] = (int) $index;
            }
        }

        if (!isset($mapping['email'])) {
            throw CrmException::validation(['mapping' => __('The email column must be mapped.', 'techzapp-crm')]);
        }

        $customFields = [];
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            foreach (wp_unslash($_POST['custom_fields']) as $label => $index) {
                $key = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', (string) $label));
                if ($key === '' || count($customFields) >= 20) {
                    continue;
                }
                $customFields[$key] = (int) $index;
            }
        }

        $listId = $this->intInput('target_list_id');

        ImportService::start(
            $jobId,
            $mapping,
            $this->input('duplicate_mode', 'skip'),
            $listId > 0 ? $listId : null,
            $this->input('has_header') === '1',
            $customFields,
            Capabilities::currentUserId()
        );

        $this->redirect('techzapp-crm-import', ['job' => $jobId], __('Import started.', 'techzapp-crm'));
    }

    private function handleErrorsCsv(): void
    {
        Capabilities::checkNonce('techzapp_crm_import_errors', \TechzappCrm\Admin\View::NONCE_FIELD);
        Capabilities::requireView();

        $jobId = $this->intInput('job');
        $errors = ImportService::errors($jobId, 5000);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="import-' . $jobId . '-errors.csv"');
        header('X-Content-Type-Options: nosniff');

        echo "\xEF\xBB\xBF";
        // phpcs:disable WordPress.Security.EscapeOutput -- CSV, escaped by formatRow
        echo Csv::formatRow(['row_number', 'email', 'error_code', 'error_message', 'raw_row']);

        foreach ($errors as $row) {
            echo Csv::formatRow([
                $row['csv_row_number'],
                $row['email'],
                $row['error_code'],
                $row['error_message'],
                (string) $row['raw_row_json'],
            ]);
        }
        // phpcs:enable

        Audit::log('import.errors_exported', 'import_job', $jobId, ['rows' => count($errors)]);
        exit;
    }
}
