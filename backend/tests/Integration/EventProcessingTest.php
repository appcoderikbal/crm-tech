<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Integration;

use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Services\AlertService;
use Techzapp\Crm\Services\CampaignSender;
use Techzapp\Crm\Services\CampaignService;
use Techzapp\Crm\Services\EventService;
use Techzapp\Crm\Services\ListService;
use Techzapp\Crm\Services\Mail\Mailer;
use Techzapp\Crm\Services\ReportService;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Tests\IntegrationTestCase;
use Techzapp\Crm\Tests\Support\RecordingTransport;

final class EventProcessingTest extends IntegrationTestCase
{
    private int $campaignId;
    /** @var list<array<string,mixed>> */
    private array $recipients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $listId = ListService::create('Events', '', null);
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->makeContact("event{$i}@example.com");
        }
        ListService::assign($listId, $ids, null);

        $this->campaignId = $this->makeCampaign($listId);
        CampaignService::sendNow($this->campaignId, null);

        Mailer::setTransport(new RecordingTransport());
        CampaignSender::processCampaign($this->campaignId, microtime(true) + 15);

        $this->recipients = Database::all(
            'SELECT * FROM campaign_recipients WHERE campaign_id = :id ORDER BY id',
            ['id' => $this->campaignId]
        );
        self::assertCount(5, $this->recipients);
    }

    /** Builds an SNS envelope around an SES event. */
    private function notification(string $snsMessageId, string $eventType, array $recipient, array $extra = []): string
    {
        $event = array_merge([
            'eventType' => $eventType,
            'mail'      => [
                'messageId' => (string) $recipient['ses_message_id'],
                'timestamp' => gmdate('c'),
                'tags'      => [
                    'campaign_id'  => [(string) $this->campaignId],
                    'recipient_id' => [(string) $recipient['id']],
                ],
                'destination' => [(string) $recipient['recipient_email_snapshot']],
            ],
        ], $extra);

        return (string) json_encode([
            'Type'      => 'Notification',
            'MessageId' => $snsMessageId,
            'TopicArn'  => 'arn:aws:sns:eu-west-1:123456789012:ses-events',
            'Message'   => json_encode($event),
        ]);
    }

    public function testSesAcceptanceIsNotCountedAsDelivery(): void
    {
        $metrics = ReportService::computeCampaignMetrics($this->campaignId);

        self::assertSame(5, $metrics['accepted'], 'All five were accepted by the API.');
        self::assertSame(0, $metrics['delivered'], 'None are delivered until a delivery event arrives.');
        self::assertSame(
            0.0,
            ReportService::rates($metrics)['delivery_rate'],
            'Delivery rate must be 0%, not 100%, until delivery events arrive.'
        );
    }

    public function testDeliveryEventMarksTheRecipientDelivered(): void
    {
        EventService::applyNotification(
            'sns-1',
            $this->notification('sns-1', 'Delivery', $this->recipients[0], [
                'delivery' => ['timestamp' => gmdate('c'), 'smtpResponse' => '250 OK'],
            ])
        );

        self::assertSame('delivered', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE id = :id',
            ['id' => $this->recipients[0]['id']]
        ));

        $metrics = ReportService::computeCampaignMetrics($this->campaignId);
        self::assertSame(1, $metrics['delivered']);
        self::assertSame(20.0, ReportService::rates($metrics)['delivery_rate']);
    }

    public function testDuplicateNotificationsDoNotInflateCounts(): void
    {
        $payload = $this->notification('sns-dup', 'Delivery', $this->recipients[0], [
            'delivery' => ['timestamp' => gmdate('c')],
        ]);

        for ($i = 0; $i < 5; $i++) {
            EventService::applyNotification('sns-dup', $payload);
        }

        self::assertSame(1, (int) Database::scalar(
            "SELECT COUNT(*) FROM email_events WHERE event_type = 'delivery'"
        ));
        self::assertSame(1, ReportService::computeCampaignMetrics($this->campaignId)['delivered']);
    }

    public function testQueuedNotificationsAreDeduplicatedAtIngestion(): void
    {
        $payload = $this->notification('sns-queue', 'Delivery', $this->recipients[0], [
            'delivery' => ['timestamp' => gmdate('c')],
        ]);

        self::assertTrue(EventService::queueNotification('sns-queue', 'arn:topic', $payload));
        self::assertFalse(
            EventService::queueNotification('sns-queue', 'arn:topic', $payload),
            'An SNS redelivery must not be queued twice.'
        );

        $result = EventService::processPending();

        self::assertSame(1, $result['processed']);
        self::assertSame(1, (int) Database::scalar(
            "SELECT COUNT(*) FROM email_events WHERE event_type = 'delivery'"
        ));
    }

    public function testHardBounceSuppressesTheAddress(): void
    {
        $recipient = $this->recipients[1];

        EventService::applyNotification('sns-bounce', $this->notification('sns-bounce', 'Bounce', $recipient, [
            'bounce' => [
                'bounceType'    => 'Permanent',
                'bounceSubType' => 'General',
                'timestamp'     => gmdate('c'),
                'bouncedRecipients' => [[
                    'emailAddress'   => $recipient['recipient_email_snapshot'],
                    'diagnosticCode' => 'smtp; 550 5.1.1 user unknown',
                ]],
            ],
        ]));

        self::assertSame('bounced', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE id = :id',
            ['id' => $recipient['id']]
        ));
        self::assertTrue(SuppressionService::isSuppressed((string) $recipient['recipient_email_snapshot']));
        self::assertSame('hard_bounce', Database::scalar(
            'SELECT reason FROM suppressions WHERE normalized_email = :e',
            ['e' => $recipient['recipient_email_snapshot']]
        ));
        self::assertSame(1, ReportService::computeCampaignMetrics($this->campaignId)['hard_bounces']);
    }

    public function testSoftBounceDoesNotSuppressImmediately(): void
    {
        $recipient = $this->recipients[2];

        EventService::applyNotification('sns-soft', $this->notification('sns-soft', 'Bounce', $recipient, [
            'bounce' => [
                'bounceType'    => 'Transient',
                'bounceSubType' => 'MailboxFull',
                'timestamp'     => gmdate('c'),
                'bouncedRecipients' => [['emailAddress' => $recipient['recipient_email_snapshot']]],
            ],
        ]));

        self::assertFalse(
            SuppressionService::isSuppressed((string) $recipient['recipient_email_snapshot']),
            'A single soft bounce is not a reason to stop mailing someone.'
        );

        $metrics = ReportService::computeCampaignMetrics($this->campaignId);
        self::assertSame(1, $metrics['soft_bounces']);
        self::assertSame(0, $metrics['hard_bounces']);
    }

    public function testComplaintSuppressesAndOutranksAnEarlierUnsubscribe(): void
    {
        $recipient = $this->recipients[3];
        $email = (string) $recipient['recipient_email_snapshot'];

        SuppressionService::suppress($email, 'unsubscribe', 'test');

        EventService::applyNotification('sns-complaint', $this->notification('sns-complaint', 'Complaint', $recipient, [
            'complaint' => ['complaintFeedbackType' => 'abuse', 'timestamp' => gmdate('c')],
        ]));

        self::assertSame('complained', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE id = :id',
            ['id' => $recipient['id']]
        ));
        self::assertSame('complaint', Database::scalar(
            'SELECT reason FROM suppressions WHERE normalized_email = :e',
            ['e' => $email]
        ));
    }

    public function testDeliveryEventCannotOverwriteABounce(): void
    {
        $recipient = $this->recipients[4];

        EventService::applyNotification('sns-b2', $this->notification('sns-b2', 'Bounce', $recipient, [
            'bounce' => [
                'bounceType' => 'Permanent', 'bounceSubType' => 'General', 'timestamp' => gmdate('c'),
                'bouncedRecipients' => [['emailAddress' => $recipient['recipient_email_snapshot']]],
            ],
        ]));

        // A late/out-of-order delivery event must not undo the bounce.
        EventService::applyNotification('sns-d2', $this->notification('sns-d2', 'Delivery', $recipient, [
            'delivery' => ['timestamp' => gmdate('c')],
        ]));

        self::assertSame('bounced', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE id = :id',
            ['id' => $recipient['id']]
        ));
    }

    public function testProviderOpenAndClickEventsAreRecorded(): void
    {
        $recipient = $this->recipients[0];

        EventService::applyNotification('sns-open', $this->notification('sns-open', 'Open', $recipient, [
            'open' => ['timestamp' => gmdate('c'), 'userAgent' => 'Mozilla/5.0', 'ipAddress' => '203.0.113.5'],
        ]));
        EventService::applyNotification('sns-click', $this->notification('sns-click', 'Click', $recipient, [
            'click' => ['timestamp' => gmdate('c'), 'link' => 'https://techzapp.test/offer'],
        ]));

        $metrics = ReportService::computeCampaignMetrics($this->campaignId);
        self::assertSame(1, $metrics['unique_opens']);
        self::assertSame(1, $metrics['unique_clicks']);

        $row = Database::first(
            'SELECT first_opened_at, first_clicked_at FROM campaign_recipients WHERE id = :id',
            ['id' => $recipient['id']]
        );
        self::assertNotNull($row['first_opened_at']);
        self::assertNotNull($row['first_clicked_at']);
    }

    public function testUnknownEventTypesAreIgnoredSafely(): void
    {
        $payload = $this->notification('sns-unknown', 'SomethingNew', $this->recipients[0]);

        self::assertFalse(EventService::applyNotification('sns-unknown', $payload));

        // Only the five local 'send' records from setUp should exist; an
        // unrecognised provider event adds nothing.
        self::assertSame(0, (int) Database::scalar(
            "SELECT COUNT(*) FROM email_events WHERE event_type <> 'send'"
        ));
    }

    public function testEventsForUnknownMessagesAreStillRecorded(): void
    {
        $payload = (string) json_encode([
            'Type'      => 'Notification',
            'MessageId' => 'sns-orphan',
            'Message'   => json_encode([
                'eventType' => 'Bounce',
                'mail'      => ['messageId' => 'not-a-message-we-sent', 'timestamp' => gmdate('c')],
                'bounce'    => [
                    'bounceType' => 'Permanent', 'bounceSubType' => 'General', 'timestamp' => gmdate('c'),
                    'bouncedRecipients' => [['emailAddress' => 'stranger@example.com']],
                ],
            ]),
        ]);

        self::assertTrue(EventService::applyNotification('sns-orphan', $payload));
        self::assertSame(1, (int) Database::scalar(
            "SELECT COUNT(*) FROM email_events WHERE event_type = 'bounce' AND recipient_id IS NULL"
        ));
    }

    public function testFailedNotificationsStopRetryingAndRaiseAnAlert(): void
    {
        EventService::queueNotification('sns-broken', 'arn:topic', 'this is not json at all');
        $result = EventService::processPending();

        // Unparseable payloads are "ignored", not retried forever.
        self::assertSame(1, $result['ignored']);
        self::assertSame('ignored', Database::scalar(
            'SELECT status FROM provider_notifications WHERE message_id = :id',
            ['id' => 'sns-broken']
        ));
    }

    public function testBounceRateCrossingTheCriticalThresholdRaisesARedAlert(): void
    {
        Settings::set('alert_min_sample_size', 1);
        Settings::set('bounce_rate_critical_pct', 5.0);

        foreach ([$this->recipients[0], $this->recipients[1]] as $index => $recipient) {
            EventService::applyNotification("sns-hb{$index}", $this->notification("sns-hb{$index}", 'Bounce', $recipient, [
                'bounce' => [
                    'bounceType' => 'Permanent', 'bounceSubType' => 'General', 'timestamp' => gmdate('c'),
                    'bouncedRecipients' => [['emailAddress' => $recipient['recipient_email_snapshot']]],
                ],
            ]));
        }

        AlertService::evaluateAll();

        $alert = Database::first("SELECT severity, status FROM alerts WHERE alert_key = 'bounce_rate'");
        self::assertNotNull($alert, 'A 40% bounce rate must raise an alert.');
        self::assertSame('critical', $alert['severity']);
        self::assertSame('active', $alert['status']);
        self::assertGreaterThan(0, AlertService::criticalCount());

        self::assertSame('red', AlertService::health()['deliverability']['status']);
    }

    public function testComplaintSpikePausesSendingAutomatically(): void
    {
        Settings::set('alert_min_sample_size', 1);
        Settings::set('complaint_spike_auto_pause', true);

        EventService::applyNotification('sns-c1', $this->notification('sns-c1', 'Complaint', $this->recipients[0], [
            'complaint' => ['complaintFeedbackType' => 'abuse', 'timestamp' => gmdate('c')],
        ]));

        self::assertTrue(Settings::sendingAllowed(), 'Still sending before the check runs.');

        AlertService::evaluateAll();
        Settings::flush();

        self::assertFalse(
            Settings::sendingAllowed(),
            'A complaint spike must pause sending at the warning level, not wait for critical.'
        );
        self::assertStringContainsString('complaint rate', Settings::string('kill_switch_reason'));
    }

    public function testMonitoringWithoutEnoughDataReportsUnknownNotHealthy(): void
    {
        Settings::set('alert_min_sample_size', 10000);

        $health = AlertService::health();

        self::assertSame('unknown', $health['deliverability']['status']);
        self::assertStringContainsString('below the', $health['deliverability']['detail']);
    }

    public function testStaleCronHeartbeatIsReportedAsRed(): void
    {
        Database::execute(
            "INSERT INTO cron_heartbeats (job_name, last_run_at, last_status)
             VALUES ('process_campaigns', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR), 'ok')"
        );

        $health = AlertService::health();
        self::assertSame('red', $health['cron']['status']);

        AlertService::evaluateAll();
        self::assertSame(1, (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE alert_key = 'cron_stale:process_campaigns' AND status = 'active'"
        ));
    }

    public function testOverdueScheduledCampaignRaisesAnAlert(): void
    {
        Database::execute(
            "UPDATE campaigns
                SET status = 'scheduled', scheduled_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)
              WHERE id = :id",
            ['id' => $this->campaignId]
        );

        AlertService::evaluateAll();

        $alert = Database::first(
            "SELECT severity, title FROM alerts WHERE alert_key = :key AND status = 'active'",
            ['key' => 'campaign_overdue:' . $this->campaignId]
        );
        self::assertNotNull($alert);
        self::assertSame('critical', $alert['severity']);
    }
}
