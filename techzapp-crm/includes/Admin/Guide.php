<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

use TechzappCrm\Core\Config;
use TechzappCrm\Core\Cron;
use TechzappCrm\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Setup guidance shown inside the plugin.
 *
 * Written for a sender whose recipients are in the United States, because
 * that is what this install targets: the defaults, the suggested timezone and
 * the legal notes all assume a US audience and CAN-SPAM.
 *
 * Every recommendation says what the setting is, what to set it to, and why —
 * a number without a reason is just a number someone will change later.
 */
final class Guide
{
    /**
     * @return list<array{
     *     title:string,
     *     what:string,
     *     recommended:string,
     *     why:string,
     *     status?:array{0:string,1:string}
     * }>
     */
    public static function items(): array
    {
        return [
            [
                'title' => __('Timezone', 'techzapp-crm'),
                'what'  => __('The zone campaign schedules are entered in. Times are stored in UTC and shown back in this zone.', 'techzapp-crm'),
                'recommended' => __('America/New_York for a US audience — Eastern covers roughly half the US population, and a 10am Eastern send still lands before lunch on the West Coast.', 'techzapp-crm'),
                'why'   => __('Scheduling in a named zone rather than UTC means daylight saving is handled for you: a campaign set for 10am stays at 10am local across the March and November changes.', 'techzapp-crm'),
                'status' => self::timezoneStatus(),
            ],
            [
                'title' => __('SES region', 'techzapp-crm'),
                'what'  => __('Which Amazon SES endpoint sends your mail.', 'techzapp-crm'),
                'recommended' => __('us-east-1 (N. Virginia) for US recipients. Use the region where your SES identity is verified — an identity verified in one region does not exist in another.', 'techzapp-crm'),
                'why'   => __('us-east-1 has the largest SES quotas, the lowest latency to most US inboxes, and gets new features first.', 'techzapp-crm'),
                'status' => [Settings::string('ses_region', 'us-east-1'), 'neutral'],
            ],
            [
                'title' => __('Verified sender identity', 'techzapp-crm'),
                'what'  => __('The From addresses this plugin is allowed to send from.', 'techzapp-crm'),
                'recommended' => __('Verify the whole domain in SES rather than a single address, and enable DKIM signing. Then list the domain here, for example example.com.', 'techzapp-crm'),
                'why'   => __('Domain verification with DKIM lets you send from any address on the domain and is what Gmail and Yahoo require from bulk senders. A campaign whose From address is not listed here refuses to send, which is deliberate: an empty list means an unfinished setup, not permission to send from anything.', 'techzapp-crm'),
                'status' => self::identityStatus(),
            ],
            [
                'title' => __('Physical postal address', 'techzapp-crm'),
                'what'  => __('A real mailing address, added to the unsubscribe footer of every campaign.', 'techzapp-crm'),
                'recommended' => __('Set it before your first send. A street address or a registered PO box both qualify.', 'techzapp-crm'),
                'why'   => __('US CAN-SPAM requires a valid physical postal address in every commercial message. Omitting it is the single most common compliance failure, and it is the one thing on this page the law is explicit about.', 'techzapp-crm'),
                'status' => Settings::string('company_postal_address') !== ''
                    ? [__('set', 'techzapp-crm'), 'ok']
                    : [__('not set — required before sending', 'techzapp-crm'), 'danger'],
            ],
            [
                'title' => __('Send rate and daily cap', 'techzapp-crm'),
                'what'  => __('How fast the worker hands messages to SES, and the most it will send in a day.', 'techzapp-crm'),
                'recommended' => __('Set the rate at or below your SES "max send rate" (a new production account is often 14/second, so 120/minute is comfortable). Set the daily cap at or below your 24-hour quota.', 'techzapp-crm'),
                'why'   => __('Exceeding the SES rate produces throttling errors, which this plugin retries with backoff — but a cap you set yourself is a cheaper way to find out you have run out of quota than a campaign that half-sends.', 'techzapp-crm'),
                'status' => [sprintf(
                    /* translators: 1: per-minute rate, 2: daily cap */
                    __('%1$s/min, %2$s/day', 'techzapp-crm'),
                    number_format_i18n(Settings::int('max_send_rate_per_minute')),
                    number_format_i18n(Settings::int('max_sends_per_day'))
                ), 'neutral'],
            ],
            [
                'title' => __('Warming up a new domain', 'techzapp-crm'),
                'what'  => __('The volume you send in the first few weeks on a new sending domain.', 'techzapp-crm'),
                'recommended' => __('Start around 500 a day to your most engaged contacts and roughly double every two or three days, watching the bounce and complaint rates on the Reports screen before each increase.', 'techzapp-crm'),
                'why'   => __('Mailbox providers judge a new domain on its first impressions. Sending 40,000 messages on day one from a domain with no history is the fastest way to land in spam folders, and no configuration here can undo it.', 'techzapp-crm'),
            ],
            [
                'title' => __('Bounce and complaint thresholds', 'techzapp-crm'),
                'what'  => __('When this plugin raises a warning or a critical alert.', 'techzapp-crm'),
                'recommended' => __('Leave the defaults: bounce 2% warning / 5% critical, complaints 0.05% warning / 0.1% critical.', 'techzapp-crm'),
                'why'   => __('They mirror the levels AWS itself talks about — SES reviews accounts above roughly 5% bounces and 0.1% complaints. These are this plugin\'s alert levels, not a guarantee: Amazon applies its own criteria and does not publish exact triggers, so watch the SES console too.', 'techzapp-crm'),
            ],
            [
                'title' => __('Automatic pause on complaints', 'techzapp-crm'),
                'what'  => __('Engages the kill switch as soon as the complaint warning level is reached.', 'techzapp-crm'),
                'recommended' => __('Leave it on.', 'techzapp-crm'),
                'why'   => __('It pauses at the warning level rather than waiting for critical, which is deliberate. A delayed campaign costs you an afternoon; a sending domain with a complaint history costs you months.', 'techzapp-crm'),
                'status' => Settings::bool('complaint_spike_auto_pause')
                    ? [__('on', 'techzapp-crm'), 'ok']
                    : [__('off', 'techzapp-crm'), 'warn'],
            ],
            [
                'title' => __('Background workers', 'techzapp-crm'),
                'what'  => __('The scheduled jobs that actually send campaigns and process delivery events.', 'techzapp-crm'),
                'recommended' => __('On anything but a very busy site, set DISABLE_WP_CRON in wp-config.php and run WP-CLI from a real system cron every minute.', 'techzapp-crm'),
                'why'   => __('WP-Cron only fires when somebody loads a page. On a quiet site a campaign scheduled for 3am sends when the first visitor arrives in the morning — which is both late and the wrong time of day.', 'techzapp-crm'),
                'status' => self::cronStatus(),
            ],
            [
                'title' => __('Event publishing', 'techzapp-crm'),
                'what'  => __('An SES configuration set that forwards delivery, bounce, complaint, open and click events to this site.', 'techzapp-crm'),
                'recommended' => __('Create a configuration set, add an SNS destination, and subscribe it to the webhook URL shown below. Select every event type, including Delivery.', 'techzapp-crm'),
                'why'   => __('Without it the plugin only knows that SES accepted a message, never that it arrived. Delivery, bounce and complaint figures stay at zero, and the reports will say so rather than showing a healthy-looking 100%.', 'techzapp-crm'),
                'status' => Settings::string('ses_configuration_set') !== ''
                    ? [Settings::string('ses_configuration_set'), 'ok']
                    : [__('not configured — no delivery data', 'techzapp-crm'), 'warn'],
            ],
            [
                'title' => __('Credentials', 'techzapp-crm'),
                'what'  => __('The AWS access key used to call SES.', 'techzapp-crm'),
                'recommended' => __('Create an IAM user limited to ses:SendEmail and ses:GetAccount, and put the key in wp-config.php rather than in this screen.', 'techzapp-crm'),
                'why'   => __('Anything stored in the settings screen lives in the database, which means it is in every database backup and readable by any plugin. A constant in wp-config.php is not.', 'techzapp-crm'),
                'status' => self::credentialStatus(),
            ],
            [
                'title' => __('Best time to send', 'techzapp-crm'),
                'what'  => __('Not a setting — a scheduling habit.', 'techzapp-crm'),
                'recommended' => __('For US business audiences, Tuesday to Thursday, 9–11am in the recipient\'s own timezone. Avoid Monday mornings and Friday afternoons.', 'techzapp-crm'),
                'why'   => __('It is a starting point, not a law. Your own open and click data after three or four campaigns beats any general advice, which is why those numbers are on the campaign report.', 'techzapp-crm'),
            ],
        ];
    }

    /** Renders the guide as a collapsible panel. */
    public static function render(): void
    {
        $items = self::items();

        echo '<details class="techzapp-guide" open>';
        echo '<summary><strong>' . esc_html__('Recommended configuration', 'techzapp-crm') . '</strong> — '
            . esc_html__('what each setting is, what to set it to, and why', 'techzapp-crm') . '</summary>';

        echo '<div class="techzapp-guide__body">';
        echo '<p class="description">'
            . esc_html__('These recommendations assume your recipients are in the United States.', 'techzapp-crm')
            . '</p>';

        foreach ($items as $item) {
            echo '<div class="techzapp-guide__item">';

            echo '<h4>' . esc_html($item['title']);
            if (isset($item['status'])) {
                [$label, $tone] = $item['status'];
                echo ' ' . View::badge($label, $tone);
            }
            echo '</h4>';

            printf(
                '<p><span class="techzapp-guide__tag">%s</span> %s</p>',
                esc_html__('What it is', 'techzapp-crm'),
                esc_html($item['what'])
            );
            printf(
                '<p><span class="techzapp-guide__tag tag-rec">%s</span> %s</p>',
                esc_html__('Recommended', 'techzapp-crm'),
                esc_html($item['recommended'])
            );
            printf(
                '<p class="description"><span class="techzapp-guide__tag">%s</span> %s</p>',
                esc_html__('Why', 'techzapp-crm'),
                esc_html($item['why'])
            );

            echo '</div>';
        }

        echo '<p class="description">'
            . esc_html__('Compliance note: this plugin adds an unsubscribe link and the List-Unsubscribe headers automatically, and refuses to send to a suppressed address. It cannot verify that your recipients consented — that remains your responsibility, and it is the part CAN-SPAM and your sending reputation both care about most.', 'techzapp-crm')
            . '</p>';

        echo '</div></details>';
    }

    // ---------------------------------------------------------- live status

    /** @return array{0:string,1:string} */
    private static function timezoneStatus(): array
    {
        $zone = Settings::timezone();
        $isUs = str_starts_with($zone, 'America/') || str_starts_with($zone, 'Pacific/Honolulu');

        return [$zone, $isUs ? 'ok' : 'warn'];
    }

    /** @return array{0:string,1:string} */
    private static function identityStatus(): array
    {
        $identities = Settings::array('ses_verified_identities');

        if ($identities === []) {
            return [__('none — sending will be refused', 'techzapp-crm'), 'danger'];
        }

        return [sprintf(
            /* translators: %d: number of identities */
            _n('%d identity', '%d identities', count($identities), 'techzapp-crm'),
            count($identities)
        ), 'ok'];
    }

    /** @return array{0:string,1:string} */
    private static function cronStatus(): array
    {
        return Cron::wpCronDisabled()
            ? [__('WP-Cron disabled — using system cron', 'techzapp-crm'), 'ok']
            : [__('using WP-Cron', 'techzapp-crm'), 'warn'];
    }

    /** @return array{0:string,1:string} */
    private static function credentialStatus(): array
    {
        return match (Config::credentialSource()) {
            'constant', 'environment' => [__('in wp-config.php', 'techzapp-crm'), 'ok'],
            'database'                => [__('stored in the database', 'techzapp-crm'), 'warn'],
            default                   => [__('not configured', 'techzapp-crm'), 'danger'],
        };
    }
}
