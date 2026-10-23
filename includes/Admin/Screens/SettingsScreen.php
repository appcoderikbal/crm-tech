<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\View;
use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\Config;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Cron;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Jobs;
use TechzappCrm\Core\Runner;
use TechzappCrm\Core\Settings;
use TechzappCrm\Core\Storage;
use TechzappCrm\Core\Updater;
use TechzappCrm\Rest\Routes;
use TechzappCrm\Services\AlertService;
use TechzappCrm\Services\Mail\Mailer;
use TechzappCrm\Support\EmailAddress;

if (!defined('ABSPATH')) {
    exit;
}

/** Settings, the kill switch, provider configuration and worker status. */
final class SettingsScreen extends Screen
{
    public function render(): void
    {
        $settings = Settings::all();
        $canEdit = Capabilities::canManageSettings();

        View::open(__('Techzapp CRM settings', 'techzapp-crm'));

        if (!$canEdit) {
            View::notice(__('Only administrators can change these settings.', 'techzapp-crm'), 'info');
        }

        \TechzappCrm\Admin\Guide::render();

        $this->killSwitchCard($canEdit);

        echo '<form method="post">';
        View::formFields('techzapp-crm-settings', 'save', 'techzapp_crm_settings');

        $this->sendingCard($settings, $canEdit);
        $this->thresholdsCard($settings, $canEdit);
        $this->providerCard($settings, $canEdit);
        $this->campaignDefaultsCard($settings, $canEdit);

        if ($canEdit) {
            submit_button(__('Save settings', 'techzapp-crm'));
        }

        echo '</form>';

        $this->diagnosticsCard($canEdit);
        $this->endpointsCard();
        $this->workersCard($canEdit);
        $this->environmentCard();

        View::close();
    }

    // ------------------------------------------------------------- rendering

    /**
     * Proves whether Amazon SES is actually usable from this server.
     *
     * Settings being filled in is not the same as sending working: the
     * credentials can be wrong, the region can be wrong, the identity can be
     * unverified, the account can be in the sandbox, or the host can block
     * outbound HTTPS. Each of those fails differently, and none of them is
     * visible until something tries to send. This tries, and reports exactly
     * what came back.
     */
    private function diagnosticsCard(bool $canEdit): void
    {
        View::cardOpen(
            __('Test Amazon SES', 'techzapp-crm'),
            __('Check the connection and send yourself a real message before trusting a campaign to it.', 'techzapp-crm')
        );

        $transport = Mailer::transport();

        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__('Transport:', 'techzapp-crm'),
            esc_html($transport->name())
        );

        if ($transport->name() === 'log') {
            View::notice(
                __('The transport is set to "log": messages are written to the plugin log and nothing is actually sent. Switch it to SES above to send for real.', 'techzapp-crm'),
                'warning'
            );
        }

        if (!$canEdit) {
            View::cardClose();
            return;
        }

        echo '<form method="post" class="techzapp-inline-form">';
        View::formFields('techzapp-crm-settings', 'test_connection', 'techzapp_crm_test_connection');
        submit_button(__('Check connection', 'techzapp-crm'), 'secondary', 'submit', false);
        echo '<p class="description">'
            . esc_html__('Asks SES for the account status. Confirms the credentials, the region and whether the account is still in the sandbox, without sending anything.', 'techzapp-crm')
            . '</p>';
        echo '</form>';

        $identities = Settings::array('ses_verified_identities', []);

        echo '<form method="post" class="techzapp-inline-form">';
        View::formFields('techzapp-crm-settings', 'test_email', 'techzapp_crm_test_email');

        printf(
            '<label for="test_from"><strong>%s</strong></label> ',
            esc_html__('From', 'techzapp-crm')
        );
        printf(
            '<input type="email" id="test_from" name="test_from" class="regular-text" value="%s" list="techzapp-test-identities" required> ',
            esc_attr((string) ($identities[0] ?? ''))
        );

        if ($identities !== []) {
            echo '<datalist id="techzapp-test-identities">';
            foreach ($identities as $identity) {
                printf('<option value="%s">', esc_attr((string) $identity));
            }
            echo '</datalist>';
        }

        printf(
            '<br><label for="test_to"><strong>%s</strong></label> ',
            esc_html__('To', 'techzapp-crm')
        );
        printf(
            '<input type="email" id="test_to" name="test_to" class="regular-text" placeholder="%s" required> ',
            esc_attr__('you@example.com', 'techzapp-crm')
        );

        submit_button(__('Send test email', 'techzapp-crm'), 'primary', 'submit', false);

        echo '<p class="description">'
            . esc_html__('Sends one real message through SES and reports the message ID, or the exact error. Nothing is recorded against any campaign. While the account is in the sandbox, the recipient must also be a verified identity.', 'techzapp-crm')
            . '</p>';
        echo '</form>';

        View::cardClose();
    }

    private function killSwitchCard(bool $canEdit): void
    {
        $engaged = Settings::bool('kill_switch_engaged');

        View::cardOpen(
            __('Global sending kill switch', 'techzapp-crm'),
            __('Stops the background worker from sending anything, immediately.', 'techzapp-crm')
        );

        if ($engaged) {
            View::notice(
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__('Sending is stopped.', 'techzapp-crm'),
                    esc_html(Settings::string('kill_switch_reason', __('No reason recorded.', 'techzapp-crm')))
                ),
                'error'
            );
        } else {
            View::notice(__('Sending is enabled. Campaigns send according to their schedules.', 'techzapp-crm'), 'success');
        }

        if ($canEdit) {
            echo '<form method="post" class="techzapp-killswitch">';
            View::formFields('techzapp-crm-settings', 'kill_switch', 'techzapp_crm_kill_switch');
            printf('<input type="hidden" name="engage" value="%s">', $engaged ? '0' : '1');

            if (!$engaged) {
                echo '<p><label for="kill_reason">' . esc_html__('Why are you stopping sending?', 'techzapp-crm') . '</label><br>';
                echo '<input type="text" id="kill_reason" name="reason" class="regular-text" required '
                    . 'placeholder="' . esc_attr__('Recorded in the audit log and shown on the dashboard', 'techzapp-crm') . '"></p>';
                submit_button(__('Stop all sending', 'techzapp-crm'), 'delete', 'submit', false);
            } else {
                submit_button(__('Resume sending', 'techzapp-crm'), 'primary', 'submit', false);
            }

            echo '</form>';
        }

        View::cardClose();
    }

    private function sendingCard(array $settings, bool $canEdit): void
    {
        View::cardOpen(
            __('Sending limits', 'techzapp-crm'),
            __('Hard caps the worker respects, whatever an individual campaign asks for.', 'techzapp-crm')
        );

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->selectRow(
            'processing_mode',
            __('Processing mode', 'techzapp-crm'),
            (string) ($settings['processing_mode'] ?? 'manual'),
            [
                'manual'  => __('Manual - nothing runs on a schedule', 'techzapp-crm'),
                'wp_cron' => __('Scheduled - background jobs run automatically', 'techzapp-crm'),
            ],
            $canEdit
        );

        echo '<tr><th scope="row"></th><td><p class="description">'
            . esc_html__('Manual is the lighter option on shared hosting: no recurring jobs, and you send each campaign yourself from its page with the tab open. Scheduled runs four background jobs for you - two of them every minute - which also lets you schedule a campaign for a future time and processes bounce and complaint feedback on its own.', 'techzapp-crm')
            . '</p></td></tr>';

        $this->checkboxRow('sending_enabled', __('Sending enabled', 'techzapp-crm'), (bool) $settings['sending_enabled'], $canEdit,
            __('A softer control than the kill switch; both must be on for anything to send.', 'techzapp-crm'));

        $this->numberRow('max_send_rate_per_minute', __('Max messages per minute', 'techzapp-crm'), (int) $settings['max_send_rate_per_minute'], $canEdit,
            __('Account-wide ceiling. Must not exceed your SES send rate.', 'techzapp-crm'), 1);

        $this->numberRow('max_sends_per_day', __('Max messages per day', 'techzapp-crm'), (int) $settings['max_sends_per_day'], $canEdit,
            __('0 disables the daily cap.', 'techzapp-crm'), 0);

        $this->numberRow('max_sends_per_cron_run', __('Max messages per worker run', 'techzapp-crm'), (int) $settings['max_sends_per_cron_run'], $canEdit,
            __('Keeps each run inside the host\'s time limit.', 'techzapp-crm'), 1);

        $this->numberRow('recipient_build_batch', __('Recipient build batch', 'techzapp-crm'), (int) $settings['recipient_build_batch'], $canEdit,
            __('Rows inserted per queue-building pass.', 'techzapp-crm'), 100);

        $this->numberRow('soft_bounce_max_attempts', __('Retry attempts', 'techzapp-crm'), (int) $settings['soft_bounce_max_attempts'], $canEdit,
            __('Transient failures before a recipient is marked failed.', 'techzapp-crm'), 1, 10);

        echo '</tbody></table>';
        View::cardClose();
    }

    private function thresholdsCard(array $settings, bool $canEdit): void
    {
        View::cardOpen(
            __('Deliverability thresholds', 'techzapp-crm'),
            __('Alert levels for this plugin — not guaranteed-safe limits for your SES account.', 'techzapp-crm')
        );

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->numberRow('bounce_rate_warning_pct', __('Bounce warning %', 'techzapp-crm'), (float) $settings['bounce_rate_warning_pct'], $canEdit, '', 0, null, '0.01');
        $this->numberRow('bounce_rate_critical_pct', __('Bounce critical %', 'techzapp-crm'), (float) $settings['bounce_rate_critical_pct'], $canEdit, '', 0, null, '0.01');
        $this->numberRow('complaint_rate_warning_pct', __('Complaint warning %', 'techzapp-crm'), (float) $settings['complaint_rate_warning_pct'], $canEdit, '', 0, null, '0.001');
        $this->numberRow('complaint_rate_critical_pct', __('Complaint critical %', 'techzapp-crm'), (float) $settings['complaint_rate_critical_pct'], $canEdit, '', 0, null, '0.001');

        $this->checkboxRow(
            'complaint_spike_auto_pause',
            __('Pause automatically on a complaint spike', 'techzapp-crm'),
            (bool) $settings['complaint_spike_auto_pause'],
            $canEdit,
            __('Engages the kill switch as soon as the complaint WARNING level is reached, not the critical one. A delayed campaign is cheap; a damaged sending domain is not.', 'techzapp-crm')
        );

        $this->numberRow('alert_rolling_window_hours', __('Rolling window (hours)', 'techzapp-crm'), (int) $settings['alert_rolling_window_hours'], $canEdit, '', 1);
        $this->numberRow('alert_min_sample_size', __('Minimum sample size', 'techzapp-crm'), (int) $settings['alert_min_sample_size'], $canEdit,
            __('Below this many attempted recipients the status is reported as "unknown" rather than healthy.', 'techzapp-crm'), 1);
        $this->numberRow('cron_stale_minutes', __('Worker stale after (minutes)', 'techzapp-crm'), (int) $settings['cron_stale_minutes'], $canEdit, '', 5);
        $this->numberRow('campaign_overdue_minutes', __('Campaign overdue after (minutes)', 'techzapp-crm'), (int) $settings['campaign_overdue_minutes'], $canEdit,
            __('Raise this above your cron interval, or healthy campaigns are flagged as overdue.', 'techzapp-crm'), 1);

        echo '</tbody></table>';
        View::cardClose();
    }

    private function providerCard(array $settings, bool $canEdit): void
    {
        $source = Config::credentialSource();

        View::cardOpen(
            __('Amazon SES', 'techzapp-crm'),
            __('Credentials belong in wp-config.php. Values stored here are readable by anything with database access.', 'techzapp-crm')
        );

        if ($source === 'database') {
            View::notice(
                __('SES credentials are currently stored in the database. For production, move them to wp-config.php using the constants shown below.', 'techzapp-crm'),
                'warning'
            );
        } elseif ($source === 'none' && Config::mailTransport() === 'ses') {
            View::notice(__('No SES credentials are configured, so nothing can be sent.', 'techzapp-crm'), 'error');
        }

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Credential source', 'techzapp-crm') . '</th><td>';
        echo esc_html(match ($source) {
            'constant'    => __('wp-config.php constant (recommended)', 'techzapp-crm'),
            'environment' => __('Environment variable', 'techzapp-crm'),
            'database'    => __('Stored in this screen', 'techzapp-crm'),
            default       => __('Not configured', 'techzapp-crm'),
        });
        echo '<p class="description"><code>define( \'TECHZAPP_CRM_AWS_ACCESS_KEY_ID\', \'…\' );</code><br>'
            . '<code>define( \'TECHZAPP_CRM_AWS_SECRET_ACCESS_KEY\', \'…\' );</code></p>';
        echo '</td></tr>';

        $this->selectRow('mail_transport', __('Transport', 'techzapp-crm'), (string) $settings['mail_transport'], [
            'ses' => __('Amazon SES (real delivery)', 'techzapp-crm'),
            'log' => __('Log only (writes to the plugin log, sends nothing)', 'techzapp-crm'),
        ], $canEdit);

        $this->textRow('ses_region', __('Region', 'techzapp-crm'), (string) $settings['ses_region'], $canEdit, 'us-east-1');
        $this->textRow('ses_configuration_set', __('Configuration set', 'techzapp-crm'), (string) $settings['ses_configuration_set'], $canEdit,
            '', __('Required for delivery, bounce and complaint events.', 'techzapp-crm'));

        $this->textareaRow(
            'ses_verified_identities',
            __('Verified sender identities', 'techzapp-crm'),
            implode("\n", array_map('strval', (array) $settings['ses_verified_identities'])),
            $canEdit,
            __('One address or domain per line. A campaign whose From address is not listed here refuses to send.', 'techzapp-crm')
        );

        if ($source !== 'constant' && $source !== 'environment') {
            $this->textRow('ses_access_key_id', __('Access key id', 'techzapp-crm'), (string) $settings['ses_access_key_id'], $canEdit);

            echo '<tr><th scope="row"><label for="ses_secret_access_key">' . esc_html__('Secret access key', 'techzapp-crm') . '</label></th><td>';
            printf(
                '<input type="password" id="ses_secret_access_key" name="ses_secret_access_key" class="regular-text" autocomplete="new-password" placeholder="%s"%s>',
                Settings::string('ses_secret_access_key') !== '' ? esc_attr__('(stored — leave blank to keep)', 'techzapp-crm') : '',
                $canEdit ? '' : ' disabled'
            );
            echo '<p class="description">' . esc_html__('Never displayed back. Leave blank to keep the stored value.', 'techzapp-crm') . '</p>';
            echo '</td></tr>';
        }

        $this->textareaRow(
            'sns_allowed_topic_arns',
            __('Allowed SNS topic ARNs', 'techzapp-crm'),
            implode("\n", array_map('strval', (array) $settings['sns_allowed_topic_arns'])),
            $canEdit,
            __('One per line. Notifications from any other topic are rejected. Leave empty to accept any topic (not recommended).', 'techzapp-crm')
        );

        $this->checkboxRow('sns_verify_signature', __('Verify SNS signatures', 'techzapp-crm'), (bool) $settings['sns_verify_signature'], $canEdit,
            __('Leave on. Turn off only when replaying captured payloads in a test environment.', 'techzapp-crm'));

        echo '</tbody></table>';

        $account = AlertService::providerSnapshot();
        if ($account !== null) {
            echo '<p><strong>' . esc_html__('Account status', 'techzapp-crm') . ':</strong> ';
            echo esc_html(sprintf(
                /* translators: 1: enabled/disabled, 2: enforcement status, 3: sent, 4: quota */
                __('sending %1$s, enforcement %2$s, %3$s of %4$s used in the last 24 hours.', 'techzapp-crm'),
                $account['sending_enabled'] ? __('enabled', 'techzapp-crm') : __('disabled', 'techzapp-crm'),
                $account['enforcement_status'],
                View::number((int) $account['sent_last_24_hours']),
                View::number((int) $account['max_24_hour'])
            ));
            echo '</p>';
        }

        View::cardClose();
    }

    private function campaignDefaultsCard(array $settings, bool $canEdit): void
    {
        View::cardOpen(
            __('Email layout', 'techzapp-crm'),
            __('How the message is framed. Without a container it stretches to the full width of the reading pane, which on a desktop client means very long lines.', 'techzapp-crm')
        );

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->checkboxRow('email_layout_enabled', __('Centred container', 'techzapp-crm'),
            (bool) ($settings['email_layout_enabled'] ?? true), $canEdit,
            __('Wraps the message in a centred card. Turn off only if your content already carries its own full layout.', 'techzapp-crm'));

        $this->numberRow('email_container_width', __('Content width', 'techzapp-crm'),
            (int) ($settings['email_container_width'] ?? 600), $canEdit,
            __('Pixels. 600 is the long-standing convention and is safe in every client.', 'techzapp-crm'), 320);

        $this->textRow('email_background_color', __('Page background', 'techzapp-crm'),
            (string) ($settings['email_background_color'] ?? '#f4f5f7'), $canEdit,
            '#f4f5f7',
            __('Hex colour behind the card.', 'techzapp-crm'));

        $this->textRow('email_surface_color', __('Card background', 'techzapp-crm'),
            (string) ($settings['email_surface_color'] ?? '#ffffff'), $canEdit,
            '#ffffff',
            __('Hex colour of the card itself.', 'techzapp-crm'));

        $this->textRow('footer_unsubscribe_label', __('Unsubscribe link text', 'techzapp-crm'),
            (string) ($settings['footer_unsubscribe_label'] ?? 'Unsubscribe'), $canEdit,
            'Unsubscribe',
            __('The wording of the link. Required in every campaign; it cannot be removed.', 'techzapp-crm'));

        $this->textRow('footer_permission_text', __('Permission line', 'techzapp-crm'),
            (string) ($settings['footer_permission_text'] ?? ''), $canEdit,
            __('You are receiving this email because...', 'techzapp-crm'),
            __('Shown above the unsubscribe link. Leave blank to show nothing but the link.', 'techzapp-crm'));

        $this->numberRow('email_corner_radius', __('Corner radius', 'techzapp-crm'),
            (int) ($settings['email_corner_radius'] ?? 8), $canEdit,
            __('Pixels. Outlook squares the corners off regardless; everything else honours it.', 'techzapp-crm'), 0);

        echo '</tbody></table>';
        View::cardClose();

        View::cardOpen(__('Campaign defaults', 'techzapp-crm'));

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->timezoneRow((string) $settings['default_timezone'], $canEdit);

        $this->textRow('tracking_base_url', __('Tracking base URL', 'techzapp-crm'), (string) $settings['tracking_base_url'], $canEdit,
            home_url(), __('Leave blank to use the site URL.', 'techzapp-crm'));

        $this->textareaRow('company_postal_address', __('Company postal address', 'techzapp-crm'), (string) $settings['company_postal_address'], $canEdit,
            __('Added to the unsubscribe footer. Required by CAN-SPAM and similar laws.', 'techzapp-crm'));

        $this->textareaRow(
            'test_send_allowed_domains',
            __('Test send allowed domains', 'techzapp-crm'),
            implode("\n", array_map('strval', (array) $settings['test_send_allowed_domains'])),
            $canEdit,
            __('One per line. Test messages may always go to your own address or another CRM user.', 'techzapp-crm')
        );

        $this->numberRow('import_retention_hours', __('Import file retention (hours)', 'techzapp-crm'), (int) $settings['import_retention_hours'], $canEdit, '', 1);
        $this->numberRow('import_max_file_mb', __('Max import file size (MB)', 'techzapp-crm'), (int) $settings['import_max_file_mb'], $canEdit,
            sprintf(
                /* translators: %s: PHP upload_max_filesize */
                __('PHP on this server accepts at most %s.', 'techzapp-crm'),
                (string) ini_get('upload_max_filesize')
            ),
            1
        );
        $this->numberRow('import_batch_rows', __('Import batch rows', 'techzapp-crm'), (int) $settings['import_batch_rows'], $canEdit, '', 50);

        echo '</tbody></table>';
        View::cardClose();
    }

    private function endpointsCard(): void
    {
        View::cardOpen(
            __('Endpoints to configure in AWS', 'techzapp-crm'),
            __('Point your SES configuration set at the SNS topic, and the topic at this webhook.', 'techzapp-crm')
        );

        echo '<table class="widefat striped"><tbody>';
        foreach ([
            __('SNS webhook', 'techzapp-crm')   => Routes::webhookUrl(),
            __('Open pixel', 'techzapp-crm')    => Routes::trackingUrl('/t/o'),
            __('Click redirect', 'techzapp-crm') => Routes::trackingUrl('/t/c'),
            __('Unsubscribe', 'techzapp-crm')   => Routes::trackingUrl('/u'),
        ] as $label => $url) {
            printf(
                '<tr><th scope="row">%s</th><td><code class="techzapp-url">%s</code></td></tr>',
                esc_html($label),
                esc_html($url)
            );
        }
        echo '</tbody></table>';

        if (Config::usingSaltDerivedSecret()) {
            echo '<p class="description">'
                . esc_html__('Tracking links are signed with a WordPress salt. Changing the salts in wp-config.php will invalidate unsubscribe links in email already delivered. Define TECHZAPP_CRM_SECRET to decouple them.', 'techzapp-crm')
                . '</p>';
        }

        View::cardClose();
    }

    private function workersCard(bool $canEdit): void
    {
        View::cardOpen(
            __('Background workers', 'techzapp-crm'),
            __('Each job reports in after every run. Scheduled campaigns do not send without them.', 'techzapp-crm')
        );

        if (Cron::wpCronDisabled()) {
            View::notice(
                __('WP-Cron is disabled on this site, so a real system cron must be calling WP-CLI. See the plugin documentation for the exact command.', 'techzapp-crm'),
                'info'
            );
        } else {
            View::notice(
                __('WP-Cron is in use. It only runs when someone visits the site, so a quiet site sends late. For anything beyond small volumes, disable WP-Cron and use a real system cron.', 'techzapp-crm'),
                'info'
            );
        }

        $heartbeats = [];
        foreach (Database::all('SELECT job_name, last_run_at, last_status, last_message, duration_ms, run_count FROM {cron_heartbeats}') as $row) {
            $heartbeats[(string) $row['job_name']] = $row;
        }

        $next = Cron::nextRuns();

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Job', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Last run', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Status', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Next run', 'techzapp-crm') . '</th>';
        echo '<th class="num">' . esc_html__('Runs', 'techzapp-crm') . '</th>';
        if ($canEdit) {
            echo '<th></th>';
        }
        echo '</tr></thead><tbody>';

        foreach (array_keys(Cron::JOBS) as $job) {
            $beat = $heartbeats[$job] ?? null;
            $status = $beat === null ? 'unknown' : ((string) $beat['last_status'] === 'ok' ? 'green' : 'red');

            echo '<tr>';
            printf('<td><code>%s</code></td>', esc_html($job));
            printf(
                '<td>%s</td>',
                $beat === null
                    ? '<span class="description">' . esc_html__('never', 'techzapp-crm') . '</span>'
                    : esc_html(View::relative((string) $beat['last_run_at']))
            );
            printf(
                '<td>%s %s</td>',
                View::statusDot($status),
                esc_html($beat === null ? __('no data', 'techzapp-crm') : (string) $beat['last_status'])
            );
            printf(
                '<td>%s</td>',
                ($next[$job] ?? false) === false
                    ? '<span class="description">' . esc_html__('not scheduled', 'techzapp-crm') . '</span>'
                    : esc_html(View::relative(gmdate('Y-m-d H:i:s', (int) $next[$job])))
            );
            printf('<td class="num">%s</td>', esc_html(View::number($beat === null ? 0 : (int) $beat['run_count'])));

            if ($canEdit) {
                printf(
                    '<td><a class="button button-small" href="%s">%s</a></td>',
                    esc_url(View::actionUrl('techzapp-crm-settings', 'run_job', 'techzapp_crm_run_job', ['job' => $job])),
                    esc_html__('Run now', 'techzapp-crm')
                );
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
        View::cardClose();
    }

    private function environmentCard(): void
    {
        View::cardOpen(__('Hosting environment', 'techzapp-crm'));

        echo '<table class="widefat striped"><tbody>';
        foreach ([
            __('PHP', 'techzapp-crm')               => PHP_VERSION,
            __('MySQL', 'techzapp-crm')             => (string) Database::scalar('SELECT VERSION()'),
            __('Memory limit', 'techzapp-crm')      => (string) ini_get('memory_limit'),
            __('Max execution time', 'techzapp-crm') => (string) ini_get('max_execution_time') . 's',
            __('Max upload size', 'techzapp-crm')   => (string) ini_get('upload_max_filesize'),
            __('Worker time budget', 'techzapp-crm') => Config::maxRuntimeSeconds() . 's',
            __('Mail transport', 'techzapp-crm')    => Mailer::transport()->name(),
            __('Plugin version', 'techzapp-crm')    => $this->versionLine(),
        ] as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html($value));
        }
        echo '</tbody></table>';

        if (!Storage::htaccessLikelyHonoured()) {
            View::notice(
                __('This server does not appear to be Apache or LiteSpeed, so the .htaccess protecting uploaded import files may be ignored. The files use unguessable names, but consider adding a server rule denying access to the techzapp-crm uploads folder.', 'techzapp-crm'),
                'warning'
            );
        }

        if (Capabilities::canManageSettings()) {
            printf(
                '<p><a href="%s">%s</a></p>',
                esc_url(Menu::url('techzapp-crm-audit')),
                esc_html__('View the audit log', 'techzapp-crm')
            );
        }

        View::cardClose();
    }

    /** Installed version, plus whatever the updater last saw on GitHub. */
    private function versionLine(): string
    {
        $status = Updater::status();

        if ($status['update_ready']) {
            return sprintf(
                /* translators: 1: installed version, 2: available version */
                __('%1$s — version %2$s is available on the Plugins screen', 'techzapp-crm'),
                $status['installed'],
                $status['latest']
            );
        }

        if ($status['latest'] === null) {
            return sprintf(
                /* translators: %s: installed version */
                __('%s — no published release found yet', 'techzapp-crm'),
                $status['installed']
            );
        }

        return sprintf(
            /* translators: %s: installed version */
            __('%s — up to date', 'techzapp-crm'),
            $status['installed']
        );
    }

    // ------------------------------------------------------------- field rows

    private function textRow(string $key, string $label, string $value, bool $canEdit, string $placeholder = '', string $help = ''): void
    {
        printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr($key), esc_html($label));
        printf(
            '<input type="text" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s"%s>',
            esc_attr($key),
            esc_attr($key),
            esc_attr($value),
            esc_attr($placeholder),
            $canEdit ? '' : ' disabled'
        );
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private function textareaRow(string $key, string $label, string $value, bool $canEdit, string $help = ''): void
    {
        printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr($key), esc_html($label));
        printf(
            '<textarea id="%s" name="%s" rows="4" class="large-text code"%s>%s</textarea>',
            esc_attr($key),
            esc_attr($key),
            $canEdit ? '' : ' disabled',
            esc_textarea($value)
        );
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private function numberRow(string $key, string $label, int|float $value, bool $canEdit, string $help = '', ?int $min = null, ?int $max = null, string $step = '1'): void
    {
        printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr($key), esc_html($label));
        printf(
            '<input type="number" id="%s" name="%s" value="%s" class="small-text" step="%s"%s%s%s>',
            esc_attr($key),
            esc_attr($key),
            esc_attr((string) $value),
            esc_attr($step),
            $min === null ? '' : ' min="' . esc_attr((string) $min) . '"',
            $max === null ? '' : ' max="' . esc_attr((string) $max) . '"',
            $canEdit ? '' : ' disabled'
        );
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private function checkboxRow(string $key, string $label, bool $value, bool $canEdit, string $help = ''): void
    {
        printf('<tr><th scope="row">%s</th><td>', esc_html($label));
        printf(
            '<label><input type="checkbox" name="%s" value="1"%s%s> %s</label>',
            esc_attr($key),
            checked($value, true, false),
            $canEdit ? '' : ' disabled',
            esc_html__('Enabled', 'techzapp-crm')
        );
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    /**
     * Timezone picker.
     *
     * US zones are listed first because that is this install's audience; the
     * full IANA list follows for anyone sending elsewhere.
     */
    private function timezoneRow(string $value, bool $canEdit): void
    {
        echo '<tr><th scope="row"><label for="default_timezone">'
            . esc_html__('Default timezone', 'techzapp-crm') . '</label></th><td>';

        printf('<select id="default_timezone" name="default_timezone"%s>', $canEdit ? '' : ' disabled');

        echo '<optgroup label="' . esc_attr__('United States', 'techzapp-crm') . '">';
        foreach (Settings::commonTimezones() as $zone => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($zone),
                selected($value, $zone, false),
                esc_html($label)
            );
        }
        echo '</optgroup>';

        echo '<optgroup label="' . esc_attr__('All timezones', 'techzapp-crm') . '">';
        foreach (timezone_identifiers_list() as $zone) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($zone),
                selected($value, $zone, false),
                esc_html($zone)
            );
        }
        echo '</optgroup>';

        echo '</select>';
        echo '<p class="description">'
            . esc_html__('Campaigns are scheduled in this zone and stored in UTC. For US business audiences, Tuesday to Thursday 9-11am performs best.', 'techzapp-crm')
            . '</p>';
        echo '</td></tr>';
    }

    /** @param array<string,string> $options */
    private function selectRow(string $key, string $label, string $value, array $options, bool $canEdit): void
    {
        printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr($key), esc_html($label));
        printf('<select id="%s" name="%s"%s>', esc_attr($key), esc_attr($key), $canEdit ? '' : ' disabled');

        foreach ($options as $optionValue => $optionLabel) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($optionValue),
                selected($value, $optionValue, false),
                esc_html($optionLabel)
            );
        }

        echo '</select></td></tr>';
    }

    // --------------------------------------------------------------- actions

    public function handle(string $action): void
    {
        match ($action) {
            'save'        => $this->handleSave(),
            'kill_switch' => $this->handleKillSwitch(),
            'run_job'     => $this->handleRunJob(),
            'test_connection' => $this->handleTestConnection(),
            'test_email'      => $this->handleTestEmail(),
            default       => null,
        };
    }

    private function handleSave(): void
    {
        $this->authorise('techzapp_crm_settings', true);

        $values = [];

        foreach (['max_send_rate_per_minute', 'max_sends_per_day', 'max_sends_per_cron_run',
                  'recipient_build_batch', 'soft_bounce_max_attempts', 'alert_rolling_window_hours',
                  'alert_min_sample_size', 'cron_stale_minutes', 'campaign_overdue_minutes',
                  'import_retention_hours', 'import_max_file_mb', 'import_batch_rows'] as $key) {
            $values[$key] = max(0, $this->intInput($key));
        }

        foreach (['bounce_rate_warning_pct', 'bounce_rate_critical_pct',
                  'complaint_rate_warning_pct', 'complaint_rate_critical_pct'] as $key) {
            $values[$key] = max(0.0, (float) $this->input($key, '0'));
        }

        foreach (['sending_enabled', 'complaint_spike_auto_pause', 'sns_verify_signature'] as $key) {
            $values[$key] = $this->input($key) === '1';
        }

        foreach (['ses_region', 'ses_configuration_set', 'company_postal_address'] as $key) {
            $values[$key] = $this->input($key);
        }

        $transport = $this->input('mail_transport', 'ses');
        $values['mail_transport'] = in_array($transport, ['ses', 'log'], true) ? $transport : 'ses';

        $mode = $this->input('processing_mode', 'manual');
        $values['processing_mode'] = in_array($mode, ['manual', 'wp_cron'], true) ? $mode : 'manual';

        $values['footer_permission_text'] = $this->input('footer_permission_text');

        // The link itself is not optional: an unlabelled unsubscribe is an
        // invisible one, which is both a CAN-SPAM problem and a fast route to
        // being reported as spam instead.
        $label = trim($this->input('footer_unsubscribe_label'));
        $values['footer_unsubscribe_label'] = $label !== '' ? $label : 'Unsubscribe';

        $values['email_layout_enabled'] = $this->input('email_layout_enabled') !== '';
        $values['email_container_width'] = max(320, min(1200, $this->intInput('email_container_width', 600)));
        $values['email_corner_radius'] = max(0, min(32, $this->intInput('email_corner_radius')));

        foreach (['email_background_color' => '#f4f5f7', 'email_surface_color' => '#ffffff'] as $key => $fallback) {
            $colour = trim($this->input($key));
            if ($colour !== '' && preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $colour) !== 1) {
                throw CrmException::validation([$key => __('Enter a hex colour such as #ffffff.', 'techzapp-crm')]);
            }
            $values[$key] = $colour !== '' ? $colour : $fallback;
        }

        $timezone = $this->input('default_timezone');
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
            throw CrmException::validation(['default_timezone' => __('Unknown timezone.', 'techzapp-crm')]);
        }
        $values['default_timezone'] = $timezone;

        $trackingUrl = $this->input('tracking_base_url');
        if ($trackingUrl !== '' && !preg_match('#^https?://[^\s/]+#i', $trackingUrl)) {
            throw CrmException::validation(['tracking_base_url' => __('Enter a full http(s) URL, or leave blank.', 'techzapp-crm')]);
        }
        $values['tracking_base_url'] = $trackingUrl;

        $values['ses_verified_identities'] = $this->lines('ses_verified_identities');
        $values['sns_allowed_topic_arns'] = $this->lines('sns_allowed_topic_arns');
        $values['test_send_allowed_domains'] = $this->lines('test_send_allowed_domains');

        if (Config::credentialSource() !== 'constant' && Config::credentialSource() !== 'environment') {
            $values['ses_access_key_id'] = $this->input('ses_access_key_id');

            // A blank secret means "keep what is stored", so the field can be
            // rendered empty without wiping the credential on every save.
            $secret = isset($_POST['ses_secret_access_key'])
                ? trim((string) wp_unslash($_POST['ses_secret_access_key']))
                : '';
            if ($secret !== '') {
                $values['ses_secret_access_key'] = $secret;
            }
        }

        Settings::update($values);

        // Apply the mode straight away rather than waiting for the next
        // request: switching to manual must actually clear the scheduled
        // events, and switching back must re-create them.
        Cron::schedule();

        $logged = $values;
        unset($logged['ses_secret_access_key']);
        Audit::log('settings.updated', 'settings', '', array_keys($logged));

        $this->redirect('techzapp-crm-settings', [], __('Settings saved.', 'techzapp-crm'));
    }

    private function handleKillSwitch(): void
    {
        $this->authorise('techzapp_crm_kill_switch', true);

        $engage = $this->input('engage') === '1';
        $reason = $this->input('reason');

        if ($engage && $reason === '') {
            throw CrmException::validation(['reason' => __('Explain why sending is being stopped.', 'techzapp-crm')]);
        }

        Settings::update([
            'kill_switch_engaged' => $engage,
            'kill_switch_reason'  => $engage ? $reason : '',
        ]);

        Audit::log(
            $engage ? 'sending.kill_switch_engaged' : 'sending.kill_switch_released',
            'system',
            'kill_switch',
            ['reason' => $reason]
        );

        if ($engage) {
            AlertService::raise(
                'kill_switch',
                'sending',
                'critical',
                __('Sending is stopped', 'techzapp-crm'),
                $reason
            );
        } else {
            AlertService::resolve('kill_switch', 'released from the settings screen');
            AlertService::resolve('auto_pause_complaints', 'released from the settings screen');
        }

        $this->redirect(
            'techzapp-crm-settings',
            [],
            $engage
                ? __('Sending stopped. The worker will not send anything until this is released.', 'techzapp-crm')
                : __('Sending re-enabled.', 'techzapp-crm'),
            $engage ? 'warning' : 'success'
        );
    }

    /** Runs one background job immediately, for setup and troubleshooting. */
    /**
     * Asks SES who we are, without sending.
     *
     * Reports the provider's own answer verbatim where it fails, because the
     * distinction between a bad key, a wrong region and a blocked outbound
     * connection lives entirely in that message.
     */
    private function handleTestConnection(): void
    {
        $this->authorise('techzapp_crm_test_connection', true);

        $transport = Mailer::transport();
        $status = $transport->accountStatus();

        if ($status === null) {
            throw CrmException::unprocessable(sprintf(
                /* translators: %s: transport name */
                __('Could not read the account status from %s. The exact provider error was written to the plugin log — check Tools or wp-content/debug.log. Common causes: wrong access key or secret, wrong region, or the host blocking outbound HTTPS.', 'techzapp-crm'),
                $transport->name()
            ));
        }

        $parts = [];
        foreach ($status as $key => $value) {
            $parts[] = sprintf(
                '%s: %s',
                ucwords(str_replace('_', ' ', (string) $key)),
                is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value
            );
        }

        Audit::log('settings.ses_connection_tested', 'settings', '', ['transport' => $transport->name()]);

        $this->redirect(
            'techzapp-crm-settings',
            [],
            sprintf(
                /* translators: %s: account details reported by the provider */
                __('Connection works. %s', 'techzapp-crm'),
                implode(' | ', $parts)
            )
        );
    }

    /**
     * Sends one real message and reports precisely what happened.
     *
     * Not recorded against any campaign: this is a connectivity check, and a
     * test send has no place in the figures describing a real audience.
     */
    private function handleTestEmail(): void
    {
        $this->authorise('techzapp_crm_test_email', true);

        $from = $this->input('test_from');
        $to = $this->input('test_to');

        if ($from === '' || !EmailAddress::isValid($from)) {
            throw CrmException::validation(['test_from' => __('Enter a valid From address.', 'techzapp-crm')]);
        }
        if ($to === '' || !EmailAddress::isValid($to)) {
            throw CrmException::validation(['test_to' => __('Enter a valid recipient address.', 'techzapp-crm')]);
        }

        $transport = Mailer::transport();
        $sentAt = gmdate('Y-m-d H:i:s');

        $result = $transport->send(new \TechzappCrm\Services\Mail\Message(
            $to,
            $from,
            (string) get_bloginfo('name'),
            '',
            __('Techzapp CRM test message', 'techzapp-crm'),
            '<p>' . esc_html__('This is a test message from Techzapp CRM.', 'techzapp-crm') . '</p>'
                . '<p>' . esc_html(sprintf(
                    /* translators: 1: site URL, 2: UTC timestamp */
                    __('Sent from %1$s at %2$s UTC.', 'techzapp-crm'),
                    home_url('/'),
                    $sentAt
                )) . '</p>'
                . '<p>' . esc_html__('If you are reading this, Amazon SES is configured correctly.', 'techzapp-crm') . '</p>',
            sprintf(
                /* translators: 1: site URL, 2: UTC timestamp */
                __("This is a test message from Techzapp CRM.\n\nSent from %1\$s at %2\$s UTC.\n\nIf you are reading this, Amazon SES is configured correctly.", 'techzapp-crm'),
                home_url('/'),
                $sentAt
            ),
            [],
            [],
            Settings::string('ses_configuration_set', '')
        ));

        Audit::log('settings.test_email_sent', 'settings', '', [
            'to'       => $to,
            'from'     => $from,
            'accepted' => $result->accepted,
            'error'    => $result->errorCode,
        ]);

        if (!$result->accepted) {
            // The provider's own words, not a paraphrase: "Email address is
            // not verified" and "Could not connect" need entirely different
            // fixes, and only the original text distinguishes them.
            throw CrmException::unprocessable(sprintf(
                /* translators: 1: error code, 2: error message */
                __('SES refused the message. %1$s: %2$s', 'techzapp-crm'),
                $result->errorCode !== '' ? $result->errorCode : __('Error', 'techzapp-crm'),
                $result->errorMessage !== '' ? $result->errorMessage : __('no detail was returned', 'techzapp-crm')
            ));
        }

        $this->redirect(
            'techzapp-crm-settings',
            [],
            sprintf(
                /* translators: 1: recipient, 2: provider message id */
                __('Test message accepted by %1$s. Message ID: %2$s. If it does not arrive, check the spam folder and the SES suppression list — acceptance is not the same as delivery.', 'techzapp-crm'),
                $to,
                $result->messageId ?? '—'
            )
        );
    }

    private function handleRunJob(): void
    {
        $this->authorise('techzapp_crm_run_job', true);

        $job = $this->input('job');
        if (!array_key_exists($job, Cron::JOBS)) {
            throw CrmException::notFound(__('Unknown job.', 'techzapp-crm'));
        }

        $summary = Cron::run($job, static fn (Runner $runner): array => match ($job) {
            'process_campaigns' => Jobs::processCampaigns($runner),
            'process_events'    => Jobs::processEvents($runner),
            'process_imports'   => Jobs::processImports($runner),
            default             => Jobs::maintenance($runner),
        });

        $this->redirect(
            'techzapp-crm-settings',
            [],
            sprintf(
                /* translators: 1: job name, 2: JSON summary */
                __('Ran %1$s: %2$s', 'techzapp-crm'),
                $job,
                '<code>' . esc_html((string) wp_json_encode($summary)) . '</code>'
            )
        );
    }

    /** @return list<string> non-empty trimmed lines from a textarea */
    private function lines(string $key): array
    {
        if (!isset($_POST[$key])) {
            return [];
        }

        $raw = sanitize_textarea_field(wp_unslash((string) $_POST[$key]));
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $line): bool => $line !== ''));
    }
}
