<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\Tables\ContactsTable;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Services\ContactService;
use TechzappCrm\Services\ListService;
use TechzappCrm\Support\Csv;

if (!defined('ABSPATH')) {
    exit;
}

/** The Contacts list screen, its bulk actions and the CSV export. */
final class Contacts extends Screen
{
    public function render(): void
    {
        $table = new ContactsTable();
        $table->prepare_items();

        View::open(
            __('Contacts', 'techzapp-crm'),
            Capabilities::canManage() ? Menu::url('techzapp-crm-contact') : null,
            Capabilities::canManage() ? __('Add contact', 'techzapp-crm') : null
        );

        if (Capabilities::canManage()) {
            printf(
                '<a href="%s" class="page-title-action">%s</a> ',
                esc_url(Menu::url('techzapp-crm-import')),
                esc_html__('Import CSV', 'techzapp-crm')
            );
            printf(
                '<a href="%s" class="page-title-action">%s</a>',
                esc_url(View::actionUrl('techzapp-crm-contacts', 'export', 'techzapp_crm_export', $this->exportArgs())),
                esc_html__('Export CSV', 'techzapp-crm')
            );
        }

        echo '<p class="description">';
        esc_html_e('Contacts not yet assigned to a list. Assigning one moves it off this view.', 'techzapp-crm');
        echo '</p>';

        $table->views();

        echo '<form method="get" id="techzapp-contacts-form">';
        echo '<input type="hidden" name="page" value="techzapp-crm-contacts">';
        printf(
            '<input type="hidden" name="assignment" value="%s">',
            esc_attr($this->input('assignment', 'unassigned'))
        );
        $table->search_box(__('Search contacts', 'techzapp-crm'), 'contact');
        echo '</form>';

        // The bulk-action form posts; the filter form above is a GET so that
        // filters stay shareable in the URL.
        echo '<form method="post">';
        View::formFields('techzapp-crm-contacts', 'bulk', 'techzapp_crm_contacts_bulk');
        printf('<input type="hidden" name="assignment" value="%s">', esc_attr($this->input('assignment', 'unassigned')));

        if (Capabilities::canManage()) {
            $this->assignControls();
        }

        $table->display();
        echo '</form>';

        View::close();
    }

    /** The list picker used by the "Assign to list" bulk action. */
    private function assignControls(): void
    {
        $lists = ListService::all();

        echo '<div class="techzapp-bulk-assign">';
        echo '<label for="techzapp-assign-list">' . esc_html__('Assign selected to:', 'techzapp-crm') . '</label> ';
        echo '<select name="target_list_id" id="techzapp-assign-list">';
        printf('<option value="">%s</option>', esc_html__('Choose a list…', 'techzapp-crm'));

        foreach ($lists as $list) {
            printf(
                '<option value="%d">%s (%s)</option>',
                (int) $list['id'],
                esc_html((string) $list['name']),
                esc_html(View::number((int) $list['contact_count']))
            );
        }

        echo '</select>';
        echo ' <span class="description">'
            . esc_html__('A contact belongs to one list at a time; assigning moves it from any current list.', 'techzapp-crm')
            . '</span>';
        echo '</div>';
    }

    public function handle(string $action): void
    {
        match ($action) {
            'bulk'           => $this->handleBulk(),
            'delete_contact' => $this->handleDelete(),
            'export'         => $this->handleExport(),
            default          => null,
        };
    }

    private function handleBulk(): void
    {
        $this->authorise('techzapp_crm_contacts_bulk');

        $table = new ContactsTable();
        $requested = $table->current_action();
        $ids = $this->idList('contact_ids');

        if ($requested === false || $requested === '' || $ids === []) {
            $this->redirect('techzapp-crm-contacts', [], __('Select at least one contact first.', 'techzapp-crm'), 'warning');
        }

        if ($requested === 'delete') {
            $deleted = ContactService::deleteMany($ids);
            Audit::log('contact.bulk_deleted', 'contact', '', ['count' => $deleted]);

            $this->redirect(
                'techzapp-crm-contacts',
                ['assignment' => $this->input('assignment', 'unassigned')],
                sprintf(
                    /* translators: %s: number of contacts */
                    __('%s contact(s) deleted. Suppression records were kept.', 'techzapp-crm'),
                    View::number($deleted)
                )
            );
        }

        if ($requested === 'assign') {
            $listId = $this->intInput('target_list_id');
            if ($listId <= 0) {
                $this->redirect(
                    'techzapp-crm-contacts',
                    ['assignment' => $this->input('assignment', 'unassigned')],
                    __('Choose a list to assign the selected contacts to.', 'techzapp-crm'),
                    'warning'
                );
            }

            $result = ListService::assign($listId, $ids, Capabilities::currentUserId());
            Audit::log('contact.assigned', 'list', $listId, $result);

            $this->redirect(
                'techzapp-crm-contacts',
                ['assignment' => $this->input('assignment', 'unassigned')],
                sprintf(
                    /* translators: 1: number assigned, 2: number moved */
                    __('%1$s contact(s) assigned (%2$s moved from another list).', 'techzapp-crm'),
                    View::number($result['assigned'] + $result['moved']),
                    View::number($result['moved'])
                )
            );
        }

        $this->redirect('techzapp-crm-contacts');
    }

    private function handleDelete(): void
    {
        $this->authorise('techzapp_crm_contact');

        $id = $this->intInput('contact');
        $contact = ContactService::find($id);

        if ($contact === null) {
            throw CrmException::notFound(__('That contact no longer exists.', 'techzapp-crm'));
        }

        ContactService::delete($id);
        Audit::log('contact.deleted', 'contact', $id, ['email' => $contact['email']]);

        $this->redirect(
            'techzapp-crm-contacts',
            ['assignment' => $this->input('assignment', 'unassigned')],
            __('Contact deleted. Any suppression for that address was kept.', 'techzapp-crm')
        );
    }

    /** @return array<string,string> */
    private function exportArgs(): array
    {
        $args = [];
        foreach (['assignment', 's', 'status', 'country', 'suppressed', 'list_id'] as $key) {
            $value = $this->input($key);
            if ($value !== '') {
                $args[$key] = $value;
            }
        }
        return $args;
    }

    /**
     * Streams the filtered contacts as CSV.
     *
     * Every cell goes through Csv::escapeField, so a contact whose name was
     * imported as "=cmd|…" is inert when the file is opened in Excel.
     */
    private function handleExport(): void
    {
        Capabilities::checkNonce('techzapp_crm_export');
        Capabilities::requireView();

        $filters = [
            'assignment'   => $this->input('assignment', 'any'),
            'search'       => $this->input('s'),
            'status'       => $this->input('status'),
            'country'      => $this->input('country'),
            'suppressed'   => $this->input('suppressed'),
            'list_id'      => $this->input('list_id'),
        ];

        $columns = [
            'id', 'email', 'first_name', 'last_name', 'company_name', 'website',
            'country', 'source', 'status', 'list_name', 'unsubscribed_at',
            'suppression_reason', 'created_at', 'updated_at',
        ];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts-' . gmdate('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');

        // UTF-8 BOM so Excel reads accents correctly.
        echo "\xEF\xBB\xBF";
        echo Csv::formatRow($columns); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV, not HTML

        $exported = 0;
        foreach (ContactService::streamForExport($filters) as $row) {
            $exported++;
            // phpcs:ignore WordPress.Security.EscapeOutput -- CSV, escaped by formatRow
            echo Csv::formatRow(array_map(static fn (string $column) => $row[$column] ?? '', $columns));

            if ($exported % 500 === 0 && function_exists('flush')) {
                flush();
            }
        }

        Audit::log('contact.exported', 'contact', '', ['rows' => $exported]);
        exit;
    }
}
