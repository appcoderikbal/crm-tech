<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Integration;

use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Services\CampaignSender;
use Techzapp\Crm\Services\CampaignService;
use Techzapp\Crm\Services\ListService;
use Techzapp\Crm\Services\Mail\Message;
use Techzapp\Crm\Services\Mail\SendResult;
use Techzapp\Crm\Services\Mail\Mailer;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Tests\IntegrationTestCase;
use Techzapp\Crm\Tests\Support\RecordingTransport;

final class CampaignSendingTest extends IntegrationTestCase
{
    private int $listId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listId = ListService::create('Send list', '', null);
    }

    /** @return list<int> contact ids */
    private function seedContacts(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->makeContact("send{$i}@example.com", ['first_name' => "Person{$i}"]);
        }
        ListService::assign($this->listId, $ids, null);
        return $ids;
    }

    public function testScheduledCampaignSendsWhenDueWithoutAnyBrowser(): void
    {
        $this->seedContacts(3);
        $campaignId = $this->makeCampaign($this->listId);

        // Schedule in the past the way a cron run would find it.
        CampaignService::schedule(
            $campaignId,
            gmdate('Y-m-d H:i:s', time() + 30),
            'UTC',
            null,
            null
        );
        Database::execute(
            'UPDATE campaigns SET scheduled_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = :id',
            ['id' => $campaignId]
        );

        $queued = CampaignSender::queueDueCampaigns();
        self::assertSame([$campaignId], $queued);

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);

        $result = CampaignSender::processCampaign($campaignId, microtime(true) + 15);

        self::assertSame(3, $result['sent']);
        self::assertCount(3, $transport->sent);
        self::assertSame('completed', CampaignService::findOrFail($campaignId)['status']);
    }

    public function testCancelledCampaignIsNotPickedUpWhenDue(): void
    {
        $this->seedContacts(2);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::schedule($campaignId, gmdate('Y-m-d H:i:s', time() + 60), 'UTC', null, null);
        CampaignService::cancel($campaignId, null);

        Database::execute(
            'UPDATE campaigns SET scheduled_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = :id',
            ['id' => $campaignId]
        );

        self::assertSame([], CampaignSender::queueDueCampaigns());

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);
        $result = CampaignSender::processCampaign($campaignId, microtime(true) + 5);

        self::assertSame(0, $result['sent']);
        self::assertCount(0, $transport->sent);
        self::assertSame('cancelled', CampaignService::findOrFail($campaignId)['status']);
    }

    public function testSuppressedRecipientsAreNeverSentTo(): void
    {
        $this->seedContacts(4);
        SuppressionService::suppress('send2@example.com', 'hard_bounce', 'test');
        SuppressionService::suppress('send3@example.com', 'unsubscribe', 'test');

        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);
        CampaignSender::processCampaign($campaignId, microtime(true) + 15);

        $recipients = array_map(
            static fn (Message $message): string => $message->toEmail,
            $transport->sent
        );
        sort($recipients);

        self::assertSame(['send1@example.com', 'send4@example.com'], $recipients);
        self::assertSame(2, (int) Database::scalar(
            "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = :id AND status = 'suppressed'",
            ['id' => $campaignId]
        ));
    }

    public function testSuppressionAddedAfterQueueingStillBlocksTheSend(): void
    {
        $this->seedContacts(3);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        // Build the queue while everyone is still eligible…
        CampaignSender::buildRecipients($campaignId);
        self::assertSame(3, (int) Database::scalar(
            "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = :id AND status = 'pending'",
            ['id' => $campaignId]
        ));

        // …then opt one out before the sender reaches them.
        SuppressionService::suppress('send2@example.com', 'unsubscribe', 'late');

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);
        CampaignSender::processCampaign($campaignId, microtime(true) + 15);

        $recipients = array_map(static fn (Message $m): string => $m->toEmail, $transport->sent);
        self::assertNotContains('send2@example.com', $recipients);
        self::assertCount(2, $recipients);
    }

    public function testRepeatedRunsNeverSendTwice(): void
    {
        $this->seedContacts(5);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);

        for ($run = 0; $run < 4; $run++) {
            CampaignSender::processCampaign($campaignId, microtime(true) + 10);
        }

        self::assertCount(5, $transport->sent, 'Five recipients, five messages, however many runs.');

        $attempts = Database::all(
            'SELECT attempts FROM campaign_recipients WHERE campaign_id = :id',
            ['id' => $campaignId]
        );
        foreach ($attempts as $row) {
            self::assertSame(1, (int) $row['attempts']);
        }
    }

    public function testCompletedCampaignCannotBeRestartedByAnotherCronRun(): void
    {
        $this->seedContacts(2);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        Mailer::setTransport(new RecordingTransport());
        CampaignSender::processCampaign($campaignId, microtime(true) + 10);
        self::assertSame('completed', CampaignService::findOrFail($campaignId)['status']);

        // Whatever a stray cron run tries, a completed campaign stays completed.
        self::assertFalse(CampaignService::transition($campaignId, 'sending'));
        self::assertFalse(CampaignService::transition($campaignId, 'queued'));
        self::assertSame('completed', CampaignService::findOrFail($campaignId)['status']);
    }

    /**
     * Four cron processes start on the same campaign at the same instant.
     * This is the overlapping-cron scenario, run for real.
     */
    public function testConcurrentCronRunsDoNotDuplicateSends(): void
    {
        $this->seedContacts(60);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        Settings::set('max_sends_per_cron_run', 200);
        Settings::set('max_send_rate_per_minute', 600);
        Database::execute(
            'UPDATE campaigns SET send_rate_per_minute = 600 WHERE id = :id',
            ['id' => $campaignId]
        );

        $startAt = (microtime(true) + 1.5) * 1000;
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->runWorker(
                __DIR__ . '/../workers/send_worker.php',
                [$campaignId, 12, (int) $startAt]
            );
        }

        $outcomes = [];
        foreach ($workers as $worker) {
            $outcomes[] = $this->waitForWorker($worker);
        }

        foreach ($outcomes as $outcome) {
            self::assertSame(0, $outcome['exit_code'], 'Worker failed: ' . $outcome['stderr']);
        }

        $rows = Database::all(
            'SELECT status, attempts, ses_message_id FROM campaign_recipients WHERE campaign_id = :id',
            ['id' => $campaignId]
        );

        self::assertCount(60, $rows, 'Recipient rows must not be duplicated by parallel builders.');

        $messageIds = [];
        foreach ($rows as $row) {
            self::assertSame('accepted', $row['status']);
            self::assertSame(1, (int) $row['attempts'], 'A recipient was attempted more than once.');
            $messageIds[] = $row['ses_message_id'];
        }

        self::assertCount(60, array_unique($messageIds), 'Each recipient must have one distinct message id.');

        // The log transport writes one line per message actually handed over.
        $logged = (int) Database::scalar(
            "SELECT COUNT(*) FROM email_events WHERE campaign_id = :id AND event_type = 'send'",
            ['id' => $campaignId]
        );
        self::assertSame(60, $logged, 'Exactly one send event per recipient.');
    }

    public function testKillSwitchStopsTheSender(): void
    {
        $this->seedContacts(3);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        Settings::set('kill_switch_engaged', true);
        Settings::set('kill_switch_reason', 'test');

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);
        $result = CampaignSender::processCampaign($campaignId, microtime(true) + 5);

        self::assertSame('sending_disabled', $result['reason']);
        self::assertCount(0, $transport->sent);
    }

    public function testKillSwitchBlocksScheduling(): void
    {
        $this->seedContacts(2);
        $campaignId = $this->makeCampaign($this->listId);
        Settings::set('kill_switch_engaged', true);

        $this->expectException(ApiException::class);
        CampaignService::sendNow($campaignId, null);
    }

    public function testUnverifiedSenderIdentityStopsTheCampaign(): void
    {
        $this->seedContacts(2);
        $campaignId = $this->makeCampaign($this->listId, ['from_email' => 'sender@example.com']);
        CampaignService::sendNow($campaignId, null);

        // Simulate the identity being revoked after the campaign was queued.
        Database::execute(
            'UPDATE campaigns SET from_email = :email WHERE id = :id',
            ['email' => 'nobody@unverified.test', 'id' => $campaignId]
        );

        $transport = new RecordingTransport();
        $transport->usableIdentities = ['sender@example.com'];
        Mailer::setTransport($transport);

        $result = CampaignSender::processCampaign($campaignId, microtime(true) + 10);

        self::assertSame('sender_unverified', $result['reason']);
        self::assertCount(0, $transport->sent);
        self::assertSame('failed', CampaignService::findOrFail($campaignId)['status']);
        self::assertSame(1, (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE category = 'sender_identity' AND status = 'active'"
        ));
    }

    public function testTransientFailureIsRetriedWithBackoff(): void
    {
        $this->seedContacts(1);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        $transport = new RecordingTransport();
        $transport->resultFactory = static fn (): SendResult => SendResult::transientFailure('Throttling', 'slow down');
        Mailer::setTransport($transport);

        CampaignSender::processCampaign($campaignId, microtime(true) + 10);

        $row = Database::first(
            'SELECT status, attempts, next_attempt_at FROM campaign_recipients WHERE campaign_id = :id',
            ['id' => $campaignId]
        );

        self::assertSame('pending', $row['status'], 'A throttled message must go back in the queue.');
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['next_attempt_at'], 'Backoff must be scheduled.');
        self::assertNotSame('completed', CampaignService::findOrFail($campaignId)['status']);
    }

    public function testPermanentRejectionIsNotRetried(): void
    {
        $this->seedContacts(1);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        $transport = new RecordingTransport();
        $transport->resultFactory = static fn (): SendResult => SendResult::permanentFailure('MessageRejected', 'bad address');
        Mailer::setTransport($transport);

        CampaignSender::processCampaign($campaignId, microtime(true) + 10);
        CampaignSender::processCampaign($campaignId, microtime(true) + 10);

        self::assertCount(1, $transport->sent, 'A permanent rejection must not be retried.');
        self::assertSame('failed', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE campaign_id = :id',
            ['id' => $campaignId]
        ));
    }

    public function testUncertainOutcomeIsParkedForReconciliationRatherThanResent(): void
    {
        $this->seedContacts(1);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        $transport = new RecordingTransport();
        $transport->resultFactory = static fn (): SendResult => SendResult::uncertain('ConnectionFailed', 'timeout');
        Mailer::setTransport($transport);

        CampaignSender::processCampaign($campaignId, microtime(true) + 10);

        self::assertSame('needs_reconciliation', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE campaign_id = :id',
            ['id' => $campaignId]
        ));

        // A later run must not resend it.
        CampaignSender::processCampaign($campaignId, microtime(true) + 10);
        self::assertCount(1, $transport->sent);

        // With no provider evidence, reconciliation gives up rather than risk a duplicate.
        Database::execute(
            'UPDATE campaign_recipients SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE campaign_id = :id',
            ['id' => $campaignId]
        );
        $outcome = CampaignSender::reconcileStale();

        self::assertSame(1, $outcome['failed']);
        self::assertCount(1, $transport->sent, 'Reconciliation must never resend.');
    }

    public function testStaleClaimWithNoMessageIdIsRequeued(): void
    {
        $this->seedContacts(1);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);
        CampaignSender::buildRecipients($campaignId);

        // Simulate a worker killed between claiming and sending.
        Database::execute(
            "UPDATE campaign_recipients
                SET status = 'sending', claimed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
              WHERE campaign_id = :id",
            ['id' => $campaignId]
        );

        $outcome = CampaignSender::reconcileStale();

        self::assertSame(1, $outcome['requeued']);
        self::assertSame('pending', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE campaign_id = :id',
            ['id' => $campaignId]
        ));
    }

    public function testClaimBatchHandsOutDisjointSets(): void
    {
        $this->seedContacts(10);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);
        CampaignSender::buildRecipients($campaignId);

        $first = CampaignSender::claimBatch($campaignId, 4);
        $second = CampaignSender::claimBatch($campaignId, 4);
        $third = CampaignSender::claimBatch($campaignId, 4);

        $ids = array_merge(
            array_column($first, 'id'),
            array_column($second, 'id'),
            array_column($third, 'id')
        );

        self::assertCount(10, $ids);
        self::assertCount(10, array_unique($ids), 'A claimed recipient must not be handed out twice.');
        self::assertSame([], CampaignSender::claimBatch($campaignId, 4));
    }

    public function testDailySendCapIsEnforced(): void
    {
        $this->seedContacts(5);
        Settings::set('max_sends_per_day', 2);

        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);
        CampaignSender::processCampaign($campaignId, microtime(true) + 10);

        self::assertCount(2, $transport->sent, 'The daily cap must stop the run.');
        self::assertNotSame('completed', CampaignService::findOrFail($campaignId)['status']);
    }

    public function testPauseAndResume(): void
    {
        $this->seedContacts(3);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        CampaignService::pause($campaignId, null);
        self::assertSame('paused', CampaignService::findOrFail($campaignId)['status']);

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);
        $result = CampaignSender::processCampaign($campaignId, microtime(true) + 5);
        self::assertSame('status_paused', $result['reason']);
        self::assertCount(0, $transport->sent);

        CampaignService::resume($campaignId, null);
        self::assertSame('queued', CampaignService::findOrFail($campaignId)['status']);

        CampaignSender::processCampaign($campaignId, microtime(true) + 10);
        self::assertCount(3, $transport->sent);
    }

    public function testLiveCampaignContentCannotBeEdited(): void
    {
        $this->seedContacts(1);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::sendNow($campaignId, null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/Only draft, scheduled or failed/');

        CampaignService::update($campaignId, ['subject' => 'Changed mid-flight']);
    }

    public function testEditingAScheduledCampaignReturnsItToDraft(): void
    {
        $this->seedContacts(1);
        $campaignId = $this->makeCampaign($this->listId);
        CampaignService::schedule($campaignId, gmdate('Y-m-d H:i:s', time() + 3600), 'UTC', null, null);

        CampaignService::update($campaignId, ['subject' => 'New subject']);

        $campaign = CampaignService::findOrFail($campaignId);
        self::assertSame('draft', $campaign['status']);
        self::assertNull($campaign['scheduled_at_utc']);
    }
}
