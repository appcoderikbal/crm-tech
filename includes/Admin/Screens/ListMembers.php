<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\Tables\ContactsTable;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Services\CampaignService;
use TechzappCrm\Services\ListService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The contacts on one list, with the same table the Contacts screen uses.
 *
 * ContactsTable already reads a list_id filter from the request, so this
 * screen only has to supply the surrounding context and the removal action.
 */
final class ListMembers extends Screen
{
    public function render(): void
    {
        $listId = $this->intInput('list_id');
        $list = $listId > 0 ? ListService::find($listId) : null;

        if ($list === null) {
            throw CrmException::notFound(__('That list no longer exists.', 'techzapp-crm'));
        }

        $counts = CampaignService::recipientCounts($listId);

        View::open(
            sprintf(
                /* translators: %s: list name */
                __('List: %s', 'techzapp-crm'),
                (string) $list['name']
            ),
            Menu::url('techzapp-crm-lists'),
            __('All lists', 'techzapp-crm')
        );

        if (Capabilities::canManage()) {
            printf(
                '<a href="%s" class="page-title-action">%s</a> ',
                esc_url(Menu::url('techzapp-crm-campaign', ['list' => $listId])),
                esc_html__('New campaign to this list', 'techzapp-crm')
            );
            printf(
                '<a href="%s" class="page-title-action">%s</a>',
                esc_url(Menu::url('techzapp-crm-contacts')),
                esc_html__('Add contacts from Contacts', 'techzapp-crm')
            );
        }

        if (!empty($list['description'])) {
            echo '<p class="description">' . esc_html((string) $list['description']) . '</p>';
        }

        View::statTiles([
            [
                'label' => __('Will receive a campaign', 'techzapp-crm'),
                'value' => View::number($counts['eligible']),
                'tone'  => $counts['eligible'] > 0 ? 'ok' : 'warn',
            ],
            ['label' => __('On the list', 'techzapp-crm'), 'value' => View::number($counts['list_total'])],
            [
                'label' => __('Suppressed', 'techzapp-crm'),
                'value' => View::number($counts['suppressed']),
                'tone'  => $counts['suppressed'] > 0 ? 'warn' : 'neutral',
            ],
            [
                'label' => __('Unsubscribed', 'techzapp-crm'),
                'value' => View::number($counts['unsubscribed']),
                'tone'  => $counts['unsubscribed'] > 0 ? 'warn' : 'neutral',
            ],
            [
                'label' => __('Invalid or inactive', 'techzapp-crm'),
                'value' => View::number($counts['invalid']),
                'tone'  => $counts['invalid'] > 0 ? 'warn' : 'neutral',
            ],
        ]);

        if ($counts['list_total'] === 0) {
            View::cardOpen(__('This list is empty', 'techzapp-crm'));
            echo '<p>' . esc_html__('Assign contacts to it from the Contacts screen using the "Assign selected to" bulk action, or choose it as the target list when importing a CSV.', 'techzapp-crm') . '</p>';
            printf(
                '<p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button">%s</a></p>',
                esc_url(Menu::url('techzapp-crm-contacts')),
                esc_html__('Go to Contacts', 'techzapp-crm'),
                esc_url(Menu::url('techzapp-crm-import')),
                esc_html__('Import a CSV', 'techzapp-crm')
            );
            View::cardClose();
            View::close();
            return;
        }

        $table = new ContactsTable();
        $table->prepare_items();

        echo '<form method="get" id="techzapp-contacts-form">';
        echo '<input type="hidden" name="page" value="techzapp-crm-list">';
        printf('<input type="hidden" name="list_id" value="%d">', $listId);
        echo '<input type="hidden" name="assignment" value="any">';
        $table->search_box(__('Search this list', 'techzapp-crm'), 'contact');
        echo '</form>';

        echo '<form method="post">';
        View::formFields('techzapp-crm-list', 'remove', 'techzapp_crm_list_remove');
        printf('<input type="hidden" name="list_id" value="%d">', $listId);
        echo '<input type="hidden" name="assignment" value="any">';

        if (Capabilities::canManage()) {
            printf(
                '<div class="techzapp-bulk-assign"><button type="submit" class="button techzapp-confirm" data-confirm="%s">%s</button> '
                . '<span class="description">%s</span></div>',
                esc_attr__('Remove the selected contacts from this list? The contacts themselves are kept.', 'techzapp-crm'),
                esc_html__('Remove selected from this list', 'techzapp-crm'),
                esc_html__('Removing returns a contact to the unassigned pool; it does not delete them.', 'techzapp-crm')
            );
        }

        $table->display();
        echo '</form>';

        View::close();
    }

    public function handle(string $action): void
    {
        if ($action !== 'remove') {
            return;
        }

        $this->authorise('techzapp_crm_list_remove');

        $listId = $this->intInput('list_id');
        $contactIds = $this->idList('contact_ids');

        if ($contactIds === []) {
            throw CrmException::validation(['contact_ids' => __('Select at least one contact.', 'techzapp-crm')]);
        }

        $removed = ListService::removeContacts($listId, $contactIds);

        $this->redirect(
            'techzapp-crm-list',
            ['list_id' => $listId],
            sprintf(
                /* translators: %s: number of contacts */
                _n(
                    '%s contact removed from this list.',
                    '%s contacts removed from this list.',
                    $removed,
                    'techzapp-crm'
                ),
                View::number($removed)
            )
        );
    }
}
