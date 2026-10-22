<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\CampaignRenderer;
use TechzappCrm\Services\CampaignService;
use TechzappCrm\Services\ListService;
use TechzappCrm\Services\Mail\Mailer;
use TechzappCrm\Services\Mail\Message;
use TechzappCrm\Support\EmailAddress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create or edit one campaign, then schedule or send it.
 *
 * Content is only editable while a campaign is draft, scheduled or failed —
 * CampaignService::update enforces that, and this screen reflects it by
 * rendering a read-only summary instead of a form once sending has begun.
 */
final class CampaignEdit extends Screen
{
    public function render(): void
    {
        $id = $this->intInput('campaign');
        $campaign = $id > 0 ? CampaignService::find($id) : null;

        if ($id > 0 && $campaign === null) {
            throw CrmException::notFound(__('That campaign no longer exists.', 'techzapp-crm'));
        }

        if ($campaign === null) {
            $campaign = $this->blankCampaign();
        }

        $isNew = $id === 0;
        $editable = $isNew || in_array((string) $campaign['status'], ['draft', 'scheduled', 'failed'], true);

        View::open(
            $isNew ? __('New campaign', 'techzapp-crm') : __('Edit campaign', 'techzapp-crm'),
            Menu::url('techzapp-crm-campaigns'),
            __('All campaigns', 'techzapp-crm')
        );

        if (!$editable) {
            View::notice(
                sprintf(
                    /* translators: %s: campaign status */
                    __('This campaign is %s, so its content can no longer be changed. Duplicate it to make an edited copy.', 'techzapp-crm'),
                    (string) $campaign['status']
                ),
                'info'
            );
            printf(
                '<p><a href="%s" class="button">%s</a></p>',
                esc_url(Menu::url('techzapp-crm-report', ['campaign' => $id])),
                esc_html__('View report', 'techzapp-crm')
            );
            View::close();
            return;
        }

        $lists = ListService::all();

        if ($lists === []) {
            View::notice(
                __('Create a list and assign some contacts to it before building a campaign.', 'techzapp-crm'),
                'warning'
            );
            printf(
                '<p><a href="%s" class="button button-primary">%s</a></p>',
                esc_url(Menu::url('techzapp-crm-lists')),
                esc_html__('Create a list', 'techzapp-crm')
            );
            View::close();
            return;
        }

        $this->renderForm($campaign, $lists, $isNew);

        if (!$isNew) {
            $this->renderReadiness($campaign);
            $this->renderPreview($campaign);
            $this->renderSendPanel($campaign);
        }

        View::close();
    }

    /** @return array<string,mixed> */
    private function blankCampaign(): array
    {
        $preselected = $this->intInput('list');

        return [
            'id'                   => 0,
            'name'                 => '',
            'list_id'              => $preselected > 0 ? $preselected : null,
            'from_name'            => (string) get_bloginfo('name'),
            'from_email'           => '',
            'reply_to'             => '',
            'subject'              => '',
            'preview_text'         => '',
            'html_body'            => '',
            'text_body'            => '',
            'status'               => 'draft',
            'timezone'             => Settings::timezone(),
            'send_rate_per_minute' => Settings::int('max_send_rate_per_minute', 120),
            'track_opens'          => true,
            'track_clicks'         => true,
        ];
    }

    /**
     * @param array<string,mixed>            $campaign
     * @param list<array<string,mixed>>      $lists
     */
    private function renderForm(array $campaign, array $lists, bool $isNew): void
    {
        $canManage = Capabilities::canManage();

        echo '<form method="post" id="techzapp-campaign-form">';
        View::formFields('techzapp-crm-campaign', 'save', 'techzapp_crm_campaign_save');

        if (!$isNew) {
            printf('<input type="hidden" name="campaign" value="%d">', (int) $campaign['id']);
        }

        View::cardOpen(
            __('Basics', 'techzapp-crm'),
            __('The internal name is for your reference only. Recipients never see it.', 'techzapp-crm')
        );

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->textRow('name', __('Campaign name', 'techzapp-crm'), (string) $campaign['name'], [
            'required' => true,
            'hint'     => __('For example: "October newsletter".', 'techzapp-crm'),
        ]);

        echo '<tr><th scope="row"><label for="list_id">' . esc_html__('Send to list', 'techzapp-crm') . '</label></th><td>';
        echo '<select id="list_id" name="list_id" required>';
        printf('<option value="">%s</option>', esc_html__('Choose a list', 'techzapp-crm'));

        foreach ($lists as $list) {
            printf(
                '<option value="%d"%s>%s</option>',
                (int) $list['id'],
                selected((int) ($campaign['list_id'] ?? 0), (int) $list['id'], false),
                esc_html(sprintf(
                    /* translators: 1: list name, 2: number of sendable contacts */
                    __('%1$s (%2$s sendable)', 'techzapp-crm'),
                    (string) $list['name'],
                    View::number((int) $list['sendable_count'])
                ))
            );
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__('Suppressed and unsubscribed contacts on the list are skipped automatically.', 'techzapp-crm') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        View::cardClose();

        View::cardOpen(
            __('Sender', 'techzapp-crm'),
            __('The From address must be an identity you have verified in Amazon SES, or the send will be refused.', 'techzapp-crm')
        );

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->textRow('from_name', __('From name', 'techzapp-crm'), (string) $campaign['from_name'], [
            'hint' => __('The display name recipients see, e.g. your company name.', 'techzapp-crm'),
        ]);

        $identities = Settings::array('ses_verified_identities', []);
        echo '<tr><th scope="row"><label for="from_email">' . esc_html__('From address', 'techzapp-crm') . '</label></th><td>';
        printf(
            '<input type="email" id="from_email" name="from_email" class="regular-text" required value="%s" list="techzapp-identities">',
            esc_attr((string) $campaign['from_email'])
        );

        if ($identities !== []) {
            echo '<datalist id="techzapp-identities">';
            foreach ($identities as $identity) {
                printf('<option value="%s">', esc_attr((string) $identity));
            }
            echo '</datalist>';
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: comma-separated list of verified identities */
                __('Verified in SES: %s', 'techzapp-crm'),
                implode(', ', array_map('strval', $identities))
            )) . '</p>';
        } else {
            echo '<p class="description tone-warn">' . esc_html__('No verified SES identities are recorded in Settings yet. Add them before sending.', 'techzapp-crm') . '</p>';
        }

        echo '</td></tr>';

        $this->textRow('reply_to', __('Reply-to', 'techzapp-crm'), (string) ($campaign['reply_to'] ?? ''), [
            'type' => 'email',
            'hint' => __('Optional. Where replies go, if not the From address.', 'techzapp-crm'),
        ]);

        echo '</tbody></table>';
        View::cardClose();

        View::cardOpen(__('Content', 'techzapp-crm'));

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->textRow('subject', __('Subject', 'techzapp-crm'), (string) $campaign['subject'], [
            'required' => true,
            'class'    => 'large-text',
        ]);

        $this->textRow('preview_text', __('Preview text', 'techzapp-crm'), (string) ($campaign['preview_text'] ?? ''), [
            'class' => 'large-text',
            'hint'  => __('The snippet shown next to the subject in most inboxes. Optional but recommended.', 'techzapp-crm'),
        ]);

        echo '</tbody></table>';

        echo '<p><strong>' . esc_html__('HTML body', 'techzapp-crm') . '</strong></p>';

        wp_editor(
            (string) ($campaign['html_body'] ?? ''),
            'html_body',
            [
                'textarea_name' => 'html_body',
                'textarea_rows' => 18,
                'media_buttons' => true,
                'teeny'         => false,
                // The editor is a convenience; the stored HTML is sanitised
                // server-side by EmailHtml regardless of what is typed here.
                'tinymce'       => ['toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,alignleft,aligncenter,undo,redo'],
            ]
        );

        echo '<p class="description">' . esc_html__('Personalisation: use {{first_name}}, {{last_name}}, {{email}} or {{company}}. A missing value falls back to the text set in Settings. JavaScript and forms are stripped — most inboxes block them anyway.', 'techzapp-crm') . '</p>';

        echo '<p><label for="text_body"><strong>' . esc_html__('Plain-text body', 'techzapp-crm') . '</strong></label></p>';
        printf(
            '<textarea id="text_body" name="text_body" class="large-text code" rows="8">%s</textarea>',
            esc_textarea((string) ($campaign['text_body'] ?? ''))
        );
        echo '<p class="description">' . esc_html__('Optional. Left empty, a plain-text version is generated from the HTML. Including one improves deliverability.', 'techzapp-crm') . '</p>';

        View::cardClose();

        View::cardOpen(__('Delivery', 'techzapp-crm'));

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Tracking', 'techzapp-crm') . '</th><td><fieldset>';
        printf(
            '<label><input type="checkbox" name="track_opens" value="1"%s> %s</label><br>',
            checked(!empty($campaign['track_opens']), true, false),
            esc_html__('Track opens', 'techzapp-crm')
        );
        printf(
            '<label><input type="checkbox" name="track_clicks" value="1"%s> %s</label>',
            checked(!empty($campaign['track_clicks']), true, false),
            esc_html__('Track clicks', 'techzapp-crm')
        );
        echo '</fieldset><p class="description">' . esc_html__('Open tracking uses a 1-pixel image many clients block, so open rates read low. Click tracking is the more reliable signal.', 'techzapp-crm') . '</p></td></tr>';

        $maxRate = max(1, Settings::int('max_send_rate_per_minute', 120));
        echo '<tr><th scope="row"><label for="send_rate_per_minute">' . esc_html__('Send rate', 'techzapp-crm') . '</label></th><td>';
        printf(
            '<input type="number" id="send_rate_per_minute" name="send_rate_per_minute" class="small-text" min="1" max="%1$d" value="%2$d"> %3$s',
            $maxRate,
            CampaignService::clampRate($campaign['send_rate_per_minute'] ?? null),
            esc_html__('messages per minute', 'techzapp-crm')
        );
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: the configured maximum send rate */
            __('Capped at %s per minute by Settings, and by your SES account limit — whichever is lower. Lower rates protect a new sending domain.', 'techzapp-crm'),
            View::number($maxRate)
        )) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="timezone">' . esc_html__('Timezone', 'techzapp-crm') . '</label></th><td>';
        $zones = Settings::commonTimezones();
        $current = (string) $campaign['timezone'];

        // A campaign may carry a zone outside the shortlist. Without this the
        // select would silently rewrite it to the first option on save.
        if ($current !== '' && !isset($zones[$current])) {
            $zones = [$current => $current] + $zones;
        }

        echo '<select id="timezone" name="timezone">';
        foreach ($zones as $zone => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $zone),
                selected($current, (string) $zone, false),
                esc_html((string) $label)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Used to interpret the scheduled send time below.', 'techzapp-crm') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        View::cardClose();

        if ($canManage) {
            submit_button(
                $isNew ? __('Create draft', 'techzapp-crm') : __('Save changes', 'techzapp-crm'),
                'primary',
                'submit',
                false
            );

            if (!$isNew && (string) $campaign['status'] === 'scheduled') {
                echo ' <span class="description">'
                    . esc_html__('Saving a scheduled campaign returns it to draft so you can re-confirm the send time.', 'techzapp-crm')
                    . '</span>';
            }
        }

        echo '</form>';
    }

    /** @param array<string,mixed> $campaign */
    private function renderReadiness(array $campaign): void
    {
        $readiness = CampaignService::readiness($campaign);
        $counts = $readiness['counts'];

        View::cardOpen(__('Pre-flight check', 'techzapp-crm'));

        View::statTiles([
            [
                'label' => __('Will receive this', 'techzapp-crm'),
                'value' => View::number($counts['eligible']),
                'tone'  => $counts['eligible'] > 0 ? 'ok' : 'danger',
            ],
            ['label' => __('On the list', 'techzapp-crm'), 'value' => View::number($counts['list_total'])],
            ['label' => __('Suppressed', 'techzapp-crm'), 'value' => View::number($counts['suppressed'])],
            ['label' => __('Unsubscribed', 'techzapp-crm'), 'value' => View::number($counts['unsubscribed'])],
            ['label' => __('Invalid', 'techzapp-crm'), 'value' => View::number($counts['invalid'])],
            [
                'label' => __('Estimated time', 'techzapp-crm'),
                'value' => View::duration((int) $readiness['estimate']['estimated_minutes']),
                'hint'  => sprintf(
                    /* translators: %s: send rate per minute */
                    __('at %s/min', 'techzapp-crm'),
                    View::number((int) $readiness['estimate']['send_rate_per_minute'])
                ),
            ],
        ]);

        if ($readiness['problems'] !== []) {
            echo '<div class="notice notice-error inline"><p><strong>'
                . esc_html__('This campaign cannot be sent yet:', 'techzapp-crm')
                . '</strong></p><ul class="ul-disc">';
            foreach ($readiness['problems'] as $problem) {
                echo '<li>' . esc_html((string) $problem['message']) . '</li>';
            }
            echo '</ul></div>';
        } else {
            echo '<div class="notice notice-success inline"><p>'
                . esc_html__('Ready to send.', 'techzapp-crm')
                . '</p></div>';
        }

        if ($readiness['warnings'] !== []) {
            echo '<div class="notice notice-warning inline"><p><strong>'
                . esc_html__('Worth knowing:', 'techzapp-crm')
                . '</strong></p><ul class="ul-disc">';
            foreach ($readiness['warnings'] as $warning) {
                echo '<li>' . esc_html((string) $warning) . '</li>';
            }
            echo '</ul></div>';
        }

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %s: transport name */
                __('Transport: %s', 'techzapp-crm'),
                (string) $readiness['estimate']['transport']
            ))
        );

        View::cardClose();
    }

    /** @param array<string,mixed> $campaign */
    private function renderPreview(array $campaign): void
    {
        if (trim((string) ($campaign['html_body'] ?? '')) === ''
            && trim((string) ($campaign['text_body'] ?? '')) === ''
        ) {
            return;
        }

        View::cardOpen(
            __('Preview', 'techzapp-crm'),
            __('Rendered with sample personalisation values. Tracking links are disabled here.', 'techzapp-crm')
        );

        try {
            $preview = CampaignRenderer::renderPreview($campaign);
        } catch (\Throwable $e) {
            View::notice(
                __('The preview could not be rendered. Check the HTML body.', 'techzapp-crm'),
                'error'
            );
            View::cardClose();
            return;
        }

        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__('Subject:', 'techzapp-crm'),
            esc_html((string) $preview['subject'])
        );

        // srcdoc in a sandboxed iframe: campaign HTML is sanitised on save,
        // but rendering it inside the admin document would still let its CSS
        // leak into wp-admin.
        printf(
            '<iframe class="techzapp-preview-frame" sandbox="" srcdoc="%s" title="%s"></iframe>',
            esc_attr((string) $preview['html']),
            esc_attr__('Email preview', 'techzapp-crm')
        );

        if (!empty($preview['missing'])) {
            echo '<p class="description tone-warn">' . esc_html(sprintf(
                /* translators: %s: comma-separated variable names */
                __('No value for: %s. The fallback from Settings is used.', 'techzapp-crm'),
                implode(', ', array_map('strval', $preview['missing']))
            )) . '</p>';
        }

        View::cardClose();
    }

    /** @param array<string,mixed> $campaign */
    private function renderSendPanel(array $campaign): void
    {
        if (!Capabilities::canManage()) {
            return;
        }

        $id = (int) $campaign['id'];
        $status = (string) $campaign['status'];

        View::cardOpen(__('Test, schedule or send', 'techzapp-crm'));

        echo '<form method="post" class="techzapp-inline-form">';
        View::formFields('techzapp-crm-campaign', 'test_send', 'techzapp_crm_campaign_test_send');
        printf('<input type="hidden" name="campaign" value="%d">', $id);
        printf(
            '<label for="test_email"><strong>%s</strong></label> ',
            esc_html__('Send a test to', 'techzapp-crm')
        );
        printf(
            '<input type="email" id="test_email" name="test_email" class="regular-text" placeholder="%s" required> ',
            esc_attr__('you@example.com', 'techzapp-crm')
        );
        submit_button(__('Send test', 'techzapp-crm'), 'secondary', 'submit', false);

        $allowed = Settings::array('test_send_allowed_domains', []);
        echo '<p class="description">' . esc_html(
            $allowed === []
                ? __('A test send is not recorded in the campaign report and does not consume the list. Restrict the permitted domains in Settings.', 'techzapp-crm')
                : sprintf(
                    /* translators: %s: comma-separated domains */
                    __('Test sends are restricted to: %s', 'techzapp-crm'),
                    implode(', ', array_map('strval', $allowed))
                )
        ) . '</p>';
        echo '</form>';

        echo '<hr>';

        if (!in_array($status, ['draft', 'scheduled', 'failed'], true)) {
            echo '<p class="description">' . esc_html__('This campaign is already under way.', 'techzapp-crm') . '</p>';
            View::cardClose();
            return;
        }

        echo '<form method="post" class="techzapp-inline-form">';
        View::formFields('techzapp-crm-campaign', 'schedule', 'techzapp_crm_campaign_schedule');
        printf('<input type="hidden" name="campaign" value="%d">', $id);
        printf(
            '<label for="scheduled_at"><strong>%s</strong></label> ',
            esc_html__('Schedule for', 'techzapp-crm')
        );
        printf(
            '<input type="datetime-local" id="scheduled_at" name="scheduled_at" value="%s" required> ',
            esc_attr($this->scheduledLocalValue($campaign))
        );
        submit_button(__('Schedule', 'techzapp-crm'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: timezone identifier */
            __('Interpreted in %s. Sending starts on the first worker run after that time, so allow a few minutes.', 'techzapp-crm'),
            (string) $campaign['timezone']
        )) . '</p>';
        echo '</form>';

        echo '<form method="post" class="techzapp-inline-form">';
        View::formFields('techzapp-crm-campaign', 'send_now', 'techzapp_crm_campaign_send_now');
        printf('<input type="hidden" name="campaign" value="%d">', $id);
        printf(
            '<button type="submit" class="button button-primary techzapp-confirm" data-confirm="%s">%s</button>',
            esc_attr__('Queue this campaign to send now? This cannot be undone once messages start going out.', 'techzapp-crm'),
            esc_html__('Send now', 'techzapp-crm')
        );
        echo '<p class="description">' . esc_html__('Queues every eligible recipient immediately. You can still pause or cancel while it runs.', 'techzapp-crm') . '</p>';
        echo '</form>';

        View::cardClose();
    }

    /**
     * Pre-fills the scheduler with the stored time, or an hour from now.
     *
     * @param array<string,mixed> $campaign
     */
    private function scheduledLocalValue(array $campaign): string
    {
        try {
            $zone = new \DateTimeZone((string) $campaign['timezone']);
        } catch (\Exception $e) {
            $zone = new \DateTimeZone('UTC');
        }

        $when = $campaign['scheduled_at_utc'] ?? null;

        try {
            $moment = $when !== null && $when !== ''
                ? new \DateTimeImmutable((string) $when, new \DateTimeZone('UTC'))
                : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 hour');
        } catch (\Exception $e) {
            return '';
        }

        return $moment->setTimezone($zone)->format('Y-m-d\TH:i');
    }

    /** @param array<string,mixed> $options */
    private function textRow(string $name, string $label, string $value, array $options = []): void
    {
        printf(
            '<tr><th scope="row"><label for="%s">%s</label></th><td>',
            esc_attr($name),
            esc_html($label)
        );
        printf(
            '<input type="%s" id="%s" name="%s" class="%s" value="%s"%s maxlength="%d">',
            esc_attr((string) ($options['type'] ?? 'text')),
            esc_attr($name),
            esc_attr($name),
            esc_attr((string) ($options['class'] ?? 'regular-text')),
            esc_attr($value),
            !empty($options['required']) ? ' required' : '',
            (int) ($options['maxlength'] ?? 998)
        );

        if (!empty($options['hint'])) {
            echo '<p class="description">' . esc_html((string) $options['hint']) . '</p>';
        }

        echo '</td></tr>';
    }

    public function handle(string $action): void
    {
        match ($action) {
            'save'      => $this->handleSave(),
            'schedule'  => $this->handleSchedule(),
            'send_now'  => $this->handleSendNow(),
            'test_send' => $this->handleTestSend(),
            default     => null,
        };
    }

    /** @return array<string,mixed> */
    private function collectInput(): array
    {
        $listId = $this->intInput('list_id');

        return [
            'name'                 => $this->input('name'),
            'list_id'              => $listId > 0 ? $listId : null,
            'from_name'            => $this->input('from_name'),
            'from_email'           => $this->input('from_email'),
            'reply_to'             => $this->input('reply_to'),
            'subject'              => $this->input('subject'),
            'preview_text'         => $this->input('preview_text'),
            'html_body'            => $this->rawPost('html_body'),
            'text_body'            => $this->rawPost('text_body'),
            'timezone'             => $this->input('timezone'),
            'send_rate_per_minute' => $this->intInput('send_rate_per_minute'),
            'track_opens'          => $this->input('track_opens') !== '',
            'track_clicks'         => $this->input('track_clicks') !== '',
        ];
    }

    /**
     * The HTML body must survive unslashed and unsanitised at this layer —
     * CampaignService::update runs it through EmailHtml::sanitize, which is
     * the single place that decides what markup an email may contain.
     */
    private function rawPost(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        return (string) wp_unslash($_POST[$key]);
    }

    private function handleSave(): void
    {
        $this->authorise('techzapp_crm_campaign_save');

        $data = $this->collectInput();
        $errors = [];

        if (trim((string) $data['name']) === '') {
            $errors['name'] = __('Give the campaign an internal name.', 'techzapp-crm');
        }
        if ($data['list_id'] === null) {
            $errors['list_id'] = __('Choose a recipient list.', 'techzapp-crm');
        } elseif (ListService::find((int) $data['list_id']) === null) {
            $errors['list_id'] = __('That list no longer exists.', 'techzapp-crm');
        }
        if (trim((string) $data['subject']) === '') {
            $errors['subject'] = __('A subject line is required.', 'techzapp-crm');
        }
        if ((string) $data['from_email'] === '' || !EmailAddress::isValid((string) $data['from_email'])) {
            $errors['from_email'] = __('Enter a valid From address.', 'techzapp-crm');
        }
        if ((string) $data['reply_to'] !== '' && !EmailAddress::isValid((string) $data['reply_to'])) {
            $errors['reply_to'] = __('Reply-to must be a valid email address.', 'techzapp-crm');
        }

        if ($errors !== []) {
            throw CrmException::validation($errors);
        }

        $id = $this->intInput('campaign');

        if ($id > 0) {
            CampaignService::update($id, $data);
            Audit::log('campaign.updated', 'campaign', $id, ['name' => $data['name']]);

            $this->redirect(
                'techzapp-crm-campaign',
                ['campaign' => $id],
                __('Campaign saved.', 'techzapp-crm')
            );
        }

        $id = CampaignService::create($data, Capabilities::currentUserId());
        Audit::log('campaign.created', 'campaign', $id, ['name' => $data['name']]);

        $this->redirect(
            'techzapp-crm-campaign',
            ['campaign' => $id],
            __('Draft created. Review the pre-flight check below, then schedule or send it.', 'techzapp-crm')
        );
    }

    private function handleSchedule(): void
    {
        $this->authorise('techzapp_crm_campaign_schedule');

        $id = $this->intInput('campaign');
        $campaign = CampaignService::findOrFail($id);

        $local = $this->input('scheduled_at');
        if ($local === '') {
            throw CrmException::validation(['scheduled_at' => __('Choose a date and time.', 'techzapp-crm')]);
        }

        $timezone = (string) $campaign['timezone'];

        try {
            $moment = new \DateTimeImmutable($local, new \DateTimeZone($timezone));
        } catch (\Exception $e) {
            throw CrmException::validation(['scheduled_at' => __('That is not a valid date and time.', 'techzapp-crm')]);
        }

        $utc = $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        CampaignService::schedule(
            $id,
            $utc,
            $timezone,
            $this->intInput('send_rate_per_minute') ?: null,
            Capabilities::currentUserId()
        );

        $this->redirect(
            'techzapp-crm-campaigns',
            [],
            sprintf(
                /* translators: 1: date and time, 2: timezone */
                __('Campaign scheduled for %1$s (%2$s).', 'techzapp-crm'),
                $moment->format('j M Y, H:i'),
                $timezone
            )
        );
    }

    private function handleSendNow(): void
    {
        $this->authorise('techzapp_crm_campaign_send_now');

        $id = $this->intInput('campaign');
        CampaignService::sendNow($id, Capabilities::currentUserId());

        $this->redirect(
            'techzapp-crm-report',
            ['campaign' => $id],
            __('Campaign queued. Sending begins on the next worker run — this page shows progress.', 'techzapp-crm')
        );
    }

    /**
     * A test send goes straight through the transport. It deliberately does
     * not create a recipient row: the campaign report must describe the real
     * audience, not the operator's own inbox.
     */
    private function handleTestSend(): void
    {
        $this->authorise('techzapp_crm_campaign_test_send');

        $id = $this->intInput('campaign');
        $campaign = CampaignService::findOrFail($id);

        $to = $this->input('test_email');
        if ($to === '' || !EmailAddress::isValid($to)) {
            throw CrmException::validation(['test_email' => __('Enter a valid email address.', 'techzapp-crm')]);
        }

        $allowed = array_filter(array_map(
            static fn ($domain): string => strtolower(trim((string) $domain)),
            Settings::array('test_send_allowed_domains', [])
        ));

        if ($allowed !== []) {
            $domain = strtolower((string) substr(strrchr($to, '@') ?: '', 1));

            if (!in_array($domain, $allowed, true)) {
                throw CrmException::validation([
                    'test_email' => sprintf(
                        /* translators: %s: comma-separated list of domains */
                        __('Test sends are restricted to these domains: %s', 'techzapp-crm'),
                        implode(', ', $allowed)
                    ),
                ]);
            }
        }

        $fromEmail = (string) $campaign['from_email'];
        if ($fromEmail === '' || !EmailAddress::isValid($fromEmail)) {
            throw CrmException::validation(['from_email' => __('Set a valid From address before sending a test.', 'techzapp-crm')]);
        }

        $preview = CampaignRenderer::renderPreview($campaign);

        $result = Mailer::transport()->send(new Message(
            $to,
            $fromEmail,
            (string) $campaign['from_name'],
            (string) ($campaign['reply_to'] ?? ''),
            '[TEST] ' . (string) $preview['subject'],
            (string) $preview['html'],
            (string) $preview['text'],
            [],
            [],
            Settings::string('ses_configuration_set', '')
        ));

        Audit::log('campaign.test_sent', 'campaign', $id, [
            'to'       => $to,
            'accepted' => $result->accepted,
        ]);

        if (!$result->accepted) {
            throw CrmException::unprocessable(sprintf(
                /* translators: %s: error message from the mail transport */
                __('The test could not be sent: %s', 'techzapp-crm'),
                $result->errorMessage !== '' ? $result->errorMessage : __('unknown error', 'techzapp-crm')
            ));
        }

        $this->redirect(
            'techzapp-crm-campaign',
            ['campaign' => $id],
            sprintf(
                /* translators: %s: email address */
                __('Test sent to %s. It is not recorded in the campaign report.', 'techzapp-crm'),
                $to
            )
        );
    }
}
