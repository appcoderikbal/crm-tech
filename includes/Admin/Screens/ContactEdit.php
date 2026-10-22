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
use TechzappCrm\Services\SuppressionService;
use TechzappCrm\Support\EmailAddress;

if (!defined('ABSPATH')) {
    exit;
}

/** Add or edit a single contact. */
final class ContactEdit extends Screen
{
    private const STATUSES = ['active', 'inactive', 'bounced'];

    public function render(): void
    {
        $id = $this->intInput('contact');
        $contact = $id > 0 ? ContactService::find($id) : null;

        if ($id > 0 && $contact === null) {
            throw CrmException::notFound(__('That contact no longer exists.', 'techzapp-crm'));
        }

        $isNew = $contact === null;

        View::open(
            $isNew ? __('Add contact', 'techzapp-crm') : __('Edit contact', 'techzapp-crm'),
            Menu::url('techzapp-crm-contacts'),
            __('All contacts', 'techzapp-crm')
        );

        if (!$isNew) {
            $this->renderStatusPanel($contact);
        }

        echo '<form method="post">';
        View::formFields('techzapp-crm-contact', 'save', 'techzapp_crm_contact_save');

        if (!$isNew) {
            printf('<input type="hidden" name="contact" value="%d">', $id);
        }

        View::cardOpen(__('Details', 'techzapp-crm'));
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->row('email', __('Email', 'techzapp-crm'), (string) ($contact['email'] ?? ''), [
            'type'     => 'email',
            'required' => true,
        ]);
        $this->row('first_name', __('First name', 'techzapp-crm'), (string) ($contact['first_name'] ?? ''));
        $this->row('last_name', __('Last name', 'techzapp-crm'), (string) ($contact['last_name'] ?? ''));
        $this->row('company_name', __('Company', 'techzapp-crm'), (string) ($contact['company_name'] ?? ''));
        $this->row('website', __('Website', 'techzapp-crm'), (string) ($contact['website'] ?? ''), ['type' => 'url']);
        $this->row('country', __('Country', 'techzapp-crm'), (string) ($contact['country'] ?? ''));
        $this->row('source', __('Source', 'techzapp-crm'), (string) ($contact['source'] ?? 'manual'), [
            'hint' => __('Where this contact came from, e.g. "website form". Used for filtering only.', 'techzapp-crm'),
        ]);

        echo '<tr><th scope="row"><label for="status">' . esc_html__('Status', 'techzapp-crm') . '</label></th><td>';
        echo '<select id="status" name="status">';
        foreach (self::STATUSES as $status) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($status),
                selected((string) ($contact['status'] ?? 'active'), $status, false),
                esc_html($status)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Only active contacts receive campaigns.', 'techzapp-crm') . '</p>';
        echo '</td></tr>';

        if ($isNew) {
            $lists = ListService::all();

            if ($lists !== []) {
                echo '<tr><th scope="row"><label for="list_id">' . esc_html__('Add to list', 'techzapp-crm') . '</label></th><td>';
                echo '<select id="list_id" name="list_id">';
                printf('<option value="">%s</option>', esc_html__('Leave unassigned', 'techzapp-crm'));
                foreach ($lists as $list) {
                    printf(
                        '<option value="%d">%s</option>',
                        (int) $list['id'],
                        esc_html((string) $list['name'])
                    );
                }
                echo '</select></td></tr>';
            }
        }

        echo '</tbody></table>';
        View::cardClose();

        if (!$isNew && !empty($contact['custom_fields'])) {
            $this->renderCustomFields((array) $contact['custom_fields']);
        }

        if (Capabilities::canManage()) {
            submit_button(
                $isNew ? __('Add contact', 'techzapp-crm') : __('Save changes', 'techzapp-crm'),
                'primary',
                'submit',
                false
            );
        }

        echo '</form>';
        View::close();
    }

    /** @param array<string,mixed> $contact */
    private function renderStatusPanel(array $contact): void
    {
        $email = (string) $contact['email'];
        $suppressed = SuppressionService::isSuppressed($email);
        $unsubscribed = !empty($contact['unsubscribed_at']);

        if (!$suppressed && !$unsubscribed) {
            return;
        }

        $reasons = [];
        if ($suppressed) {
            $reasons[] = __('This address is on the suppression list and will be skipped by every campaign.', 'techzapp-crm');
        }
        if ($unsubscribed) {
            $reasons[] = sprintf(
                /* translators: %s: date and time */
                __('This contact unsubscribed on %s and will be skipped by every campaign.', 'techzapp-crm'),
                View::dateTime((string) $contact['unsubscribed_at'])
            );
        }

        View::notice(implode(' ', $reasons), 'warning');

        if ($suppressed && Capabilities::canManage()) {
            printf(
                '<p><a href="%s" class="button techzapp-confirm" data-confirm="%s">%s</a></p>',
                esc_url(View::actionUrl(
                    'techzapp-crm-suppressions',
                    'release',
                    'techzapp_crm_suppression_release',
                    ['email' => $email]
                )),
                esc_attr__('Remove this address from the suppression list? Only do this if you are certain the address is deliverable and the contact wants your email.', 'techzapp-crm'),
                esc_html__('Release from suppression list', 'techzapp-crm')
            );
        }
    }

    /** @param array<string,mixed> $fields */
    private function renderCustomFields(array $fields): void
    {
        View::cardOpen(
            __('Imported fields', 'techzapp-crm'),
            __('Columns kept from the CSV this contact was imported from. Read-only.', 'techzapp-crm')
        );

        echo '<table class="widefat striped"><tbody>';
        foreach ($fields as $key => $value) {
            printf(
                '<tr><th scope="row" style="width:220px">%s</th><td>%s</td></tr>',
                esc_html((string) $key),
                esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value))
            );
        }
        echo '</tbody></table>';

        View::cardClose();
    }

    /** @param array<string,mixed> $options */
    private function row(string $name, string $label, string $value, array $options = []): void
    {
        printf(
            '<tr><th scope="row"><label for="%s">%s</label></th><td>',
            esc_attr($name),
            esc_html($label)
        );
        printf(
            '<input type="%s" id="%s" name="%s" class="regular-text" value="%s"%s maxlength="255">',
            esc_attr((string) ($options['type'] ?? 'text')),
            esc_attr($name),
            esc_attr($name),
            esc_attr($value),
            !empty($options['required']) ? ' required' : ''
        );

        if (!empty($options['hint'])) {
            echo '<p class="description">' . esc_html((string) $options['hint']) . '</p>';
        }

        echo '</td></tr>';
    }

    public function handle(string $action): void
    {
        if ($action !== 'save') {
            return;
        }

        $this->authorise('techzapp_crm_contact_save');

        $email = $this->input('email');
        if ($email === '' || !EmailAddress::isValid($email)) {
            throw CrmException::validation(['email' => __('Enter a valid email address.', 'techzapp-crm')]);
        }

        $status = $this->input('status', 'active');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'active';
        }

        $data = [
            'email'        => $email,
            'first_name'   => $this->input('first_name'),
            'last_name'    => $this->input('last_name'),
            'company_name' => $this->input('company_name'),
            'website'      => $this->input('website'),
            'country'      => $this->input('country'),
            'source'       => $this->input('source', 'manual'),
            'status'       => $status,
        ];

        $id = $this->intInput('contact');

        if ($id > 0) {
            ContactService::update($id, $data);
            Audit::log('contact.updated', 'contact', $id, ['email' => $email]);

            $this->redirect(
                'techzapp-crm-contact',
                ['contact' => $id],
                __('Contact saved.', 'techzapp-crm')
            );
        }

        $id = ContactService::create($data);
        Audit::log('contact.created', 'contact', $id, ['email' => $email]);

        $listId = $this->intInput('list_id');
        if ($listId > 0) {
            ListService::assign($listId, [$id], Capabilities::currentUserId());
        }

        $this->redirect(
            'techzapp-crm-contact',
            ['contact' => $id],
            __('Contact added.', 'techzapp-crm')
        );
    }
}
