<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Services\ContactService;
use TechzappCrm\Services\ListService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lists: create, rename, delete, and see who is on each one.
 *
 * A contact belongs to exactly one list at a time — enforced by a UNIQUE index,
 * not by this screen — so assigning moves rather than copies, and deleting a
 * list releases its members instead of deleting them.
 */
final class Lists extends Screen
{
    public function render(): void
    {
        $lists = ListService::all($this->input('s'));
        $counts = ContactService::counts();
        $editing = $this->intInput('edit');
        $list = $editing > 0 ? ListService::find($editing) : null;

        View::open(__('Lists', 'techzapp-crm'));

        View::statTiles([
            ['label' => __('Lists', 'techzapp-crm'), 'value' => View::number(count($lists))],
            [
                'label' => __('On a list', 'techzapp-crm'),
                'value' => View::number($counts['assigned']),
                'url'   => Menu::url('techzapp-crm-contacts', ['assignment' => 'assigned']),
            ],
            [
                'label' => __('Unassigned', 'techzapp-crm'),
                'value' => View::number($counts['unassigned']),
                'url'   => Menu::url('techzapp-crm-contacts'),
            ],
        ]);

        if (Capabilities::canManage()) {
            $this->renderForm($list);
        }

        echo '<form method="get" class="techzapp-search-form">';
        echo '<input type="hidden" name="page" value="techzapp-crm-lists">';
        printf(
            '<p class="search-box"><input type="search" name="s" value="%s" placeholder="%s"> ',
            esc_attr($this->input('s')),
            esc_attr__('Search lists', 'techzapp-crm')
        );
        submit_button(__('Search', 'techzapp-crm'), '', '', false);
        echo '</p></form>';

        if ($lists === []) {
            View::cardOpen(__('No lists yet', 'techzapp-crm'));
            echo '<p>' . esc_html__('Create a list above, then assign contacts to it from the Contacts screen or during a CSV import.', 'techzapp-crm') . '</p>';
            View::cardClose();
            View::close();
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('List', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Contacts', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Sendable', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Created', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($lists as $row) {
            $id = (int) $row['id'];
            $membersUrl = Menu::url('techzapp-crm-list', ['list_id' => $id, 'assignment' => 'any']);

            $actions = [
                sprintf('<a href="%s">%s</a>', esc_url($membersUrl), esc_html__('View members', 'techzapp-crm')),
            ];

            if (Capabilities::canManage()) {
                $actions[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(Menu::url('techzapp-crm-lists', ['edit' => $id])),
                    esc_html__('Rename', 'techzapp-crm')
                );
                $actions[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(Menu::url('techzapp-crm-campaign', ['list' => $id])),
                    esc_html__('New campaign', 'techzapp-crm')
                );
                $actions[] = sprintf(
                    '<a href="%s" class="submitdelete techzapp-confirm" data-confirm="%s">%s</a>',
                    esc_url(View::actionUrl('techzapp-crm-lists', 'delete', 'techzapp_crm_list_delete', ['list' => $id])),
                    esc_attr(sprintf(
                        /* translators: %s: number of contacts */
                        __('Delete this list? Its %s contact(s) return to the unassigned pool and are not deleted.', 'techzapp-crm'),
                        View::number((int) $row['contact_count'])
                    )),
                    esc_html__('Delete', 'techzapp-crm')
                );
            }

            $excluded = (int) $row['contact_count'] - (int) $row['sendable_count'];

            printf(
                '<tr><td><strong><a href="%s">%s</a></strong>%s<div class="row-actions">%s</div></td>'
                . '<td class="num">%s</td><td class="num">%s</td><td>%s</td></tr>',
                esc_url($membersUrl),
                esc_html((string) $row['name']),
                $row['description'] !== null && $row['description'] !== ''
                    ? '<br><span class="description">' . esc_html((string) $row['description']) . '</span>'
                    : '',
                implode(' | ', $actions),
                esc_html(View::number((int) $row['contact_count'])),
                $excluded > 0
                    ? esc_html(View::number((int) $row['sendable_count']))
                        . '<br><span class="description tone-warn">'
                        . esc_html(sprintf(
                            /* translators: %s: number excluded */
                            __('%s excluded', 'techzapp-crm'),
                            View::number($excluded)
                        )) . '</span>'
                    : esc_html(View::number((int) $row['sendable_count'])),
                esc_html(View::dateTime((string) $row['created_at']))
            );
        }

        echo '</tbody></table>';

        echo '<p class="description">'
            . esc_html__('"Sendable" excludes contacts that are suppressed or have unsubscribed. Those are skipped automatically when a campaign runs.', 'techzapp-crm')
            . '</p>';

        View::close();
    }

    /** @param array<string,mixed>|null $list */
    private function renderForm(?array $list): void
    {
        $isEdit = $list !== null;

        View::cardOpen(
            $isEdit ? __('Rename list', 'techzapp-crm') : __('Create a list', 'techzapp-crm')
        );

        echo '<form method="post">';
        View::formFields('techzapp-crm-lists', $isEdit ? 'update' : 'create', 'techzapp_crm_list_save');

        if ($isEdit) {
            printf('<input type="hidden" name="list" value="%d">', (int) $list['id']);
        }

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="list_name">' . esc_html__('Name', 'techzapp-crm') . '</label></th><td>';
        printf(
            '<input type="text" id="list_name" name="name" class="regular-text" maxlength="190" required value="%s">',
            esc_attr($isEdit ? (string) $list['name'] : '')
        );
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="list_description">' . esc_html__('Description', 'techzapp-crm') . '</label></th><td>';
        printf(
            '<textarea id="list_description" name="description" class="large-text" rows="2" maxlength="1000">%s</textarea>',
            esc_textarea($isEdit ? (string) ($list['description'] ?? '') : '')
        );
        echo '<p class="description">' . esc_html__('Optional, for your own reference.', 'techzapp-crm') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button($isEdit ? __('Save changes', 'techzapp-crm') : __('Create list', 'techzapp-crm'), 'primary', 'submit', false);

        if ($isEdit) {
            printf(
                ' <a href="%s" class="button">%s</a>',
                esc_url(Menu::url('techzapp-crm-lists')),
                esc_html__('Cancel', 'techzapp-crm')
            );
        }

        echo '</form>';
        View::cardClose();
    }

    public function handle(string $action): void
    {
        match ($action) {
            'create' => $this->handleSave(false),
            'update' => $this->handleSave(true),
            'delete' => $this->handleDelete(),
            default  => null,
        };
    }

    private function handleSave(bool $isEdit): void
    {
        $this->authorise('techzapp_crm_list_save');

        $name = $this->input('name');
        $description = $this->input('description');

        if ($name === '') {
            throw CrmException::validation(['name' => __('Give the list a name.', 'techzapp-crm')]);
        }

        if ($isEdit) {
            $id = $this->intInput('list');
            ListService::rename($id, $name, $description);
            Audit::log('list.renamed', 'list', $id, ['name' => $name]);

            $this->redirect('techzapp-crm-lists', [], __('List updated.', 'techzapp-crm'));
        }

        $id = ListService::create($name, $description, Capabilities::currentUserId());
        Audit::log('list.created', 'list', $id, ['name' => $name]);

        $this->redirect(
            'techzapp-crm-lists',
            [],
            sprintf(
                /* translators: %s: list name */
                __('List "%s" created. Assign contacts to it from the Contacts screen.', 'techzapp-crm'),
                $name
            )
        );
    }

    private function handleDelete(): void
    {
        $this->authorise('techzapp_crm_list_delete');

        $id = $this->intInput('list');
        $list = ListService::find($id);

        if ($list === null) {
            throw CrmException::notFound(__('That list no longer exists.', 'techzapp-crm'));
        }

        // The service refuses unless confirmed; the link's confirm dialog is
        // the user-facing half of that, this is the explicit acknowledgement.
        // ListService::delete writes its own audit entry.
        $result = ListService::delete($id, true);

        $this->redirect(
            'techzapp-crm-lists',
            [],
            sprintf(
                /* translators: 1: list name, 2: number of contacts */
                __('List "%1$s" deleted. %2$s contact(s) returned to the unassigned pool.', 'techzapp-crm'),
                $list['name'],
                View::number($result['released'])
            )
        );
    }
}
