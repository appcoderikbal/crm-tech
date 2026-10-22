<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Integration;

use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Services\CampaignRenderer;
use Techzapp\Crm\Services\CampaignSender;
use Techzapp\Crm\Services\CampaignService;
use Techzapp\Crm\Services\ListService;
use Techzapp\Crm\Services\Mail\Mailer;
use Techzapp\Crm\Services\ReportService;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Services\TrackingService;
use Techzapp\Crm\Support\Tokens;
use Techzapp\Crm\Tests\IntegrationTestCase;
use Techzapp\Crm\Tests\Support\RecordingTransport;

final class TrackingAndUnsubscribeTest extends IntegrationTestCase
{
    private int $campaignId;
    /** @var array<string,mixed> */
    private array $recipient;
    private int $linkId;

    protected function setUp(): void
    {
        parent::setUp();

        $listId = ListService::create('Tracking', '', null);
        ListService::assign($listId, [$this->makeContact('tracked@example.com')], null);

        $this->campaignId = $this->makeCampaign($listId);
        CampaignService::sendNow($this->campaignId, null);

        Mailer::setTransport(new RecordingTransport());
        CampaignSender::processCampaign($this->campaignId, microtime(true) + 15);

        $this->recipient = (array) Database::first(
            'SELECT r.*, c.track_opens, c.track_clicks
               FROM campaign_recipients r JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.campaign_id = :id',
            ['id' => $this->campaignId]
        );

        $this->linkId = (int) Database::scalar(
            'SELECT id FROM campaign_links WHERE campaign_id = :id LIMIT 1',
            ['id' => $this->campaignId]
        );
    }

    private function token(): string
    {
        return (string) $this->recipient['tracking_token'];
    }

    // ------------------------------------------------------------- open pixel

    public function testValidPixelRequestRecordsAnOpen(): void
    {
        $signature = Tokens::sign(TrackingService::PURPOSE_OPEN, $this->token());
        $resolved = TrackingService::resolveRecipient($this->token(), $signature, TrackingService::PURPOSE_OPEN);

        self::assertNotNull($resolved);
        TrackingService::recordOpen($resolved, 'Mozilla/5.0', '203.0.113.4');

        self::assertSame(1, ReportService::computeCampaignMetrics($this->campaignId)['unique_opens']);
        self::assertNotNull(Database::scalar(
            'SELECT first_opened_at FROM campaign_recipients WHERE id = :id',
            ['id' => $this->recipient['id']]
        ));
    }

    public function testForgedOrAlteredTokensResolveToNothing(): void
    {
        $signature = Tokens::sign(TrackingService::PURPOSE_OPEN, $this->token());

        self::assertNull(
            TrackingService::resolveRecipient($this->token(), 'forged-signature', TrackingService::PURPOSE_OPEN),
            'A wrong signature must not resolve.'
        );
        self::assertNull(
            TrackingService::resolveRecipient(str_repeat('a', 32), $signature, TrackingService::PURPOSE_OPEN),
            'A different recipient token must not resolve with this signature.'
        );
        self::assertNull(
            TrackingService::resolveRecipient('not-a-token', $signature, TrackingService::PURPOSE_OPEN),
            'A malformed token must be rejected before any query.'
        );
    }

    public function testOpenSignatureCannotBeReusedForClickTracking(): void
    {
        $openSignature = Tokens::sign(TrackingService::PURPOSE_OPEN, $this->token());

        self::assertNull(TrackingService::resolveRecipient(
            $this->token(),
            $openSignature,
            TrackingService::PURPOSE_CLICK,
            (string) $this->linkId
        ));
    }

    public function testRepeatedOpensInTheSameMinuteCountOnce(): void
    {
        $signature = Tokens::sign(TrackingService::PURPOSE_OPEN, $this->token());
        $resolved = (array) TrackingService::resolveRecipient($this->token(), $signature, TrackingService::PURPOSE_OPEN);

        for ($i = 0; $i < 10; $i++) {
            TrackingService::recordOpen($resolved, 'Mozilla/5.0', '203.0.113.4');
        }

        $metrics = ReportService::computeCampaignMetrics($this->campaignId);
        self::assertSame(1, $metrics['unique_opens']);
        self::assertSame(1, $metrics['total_opens'], 'Repeated renders in one minute collapse to one row.');
    }

    // ---------------------------------------------------------------- clicks

    public function testClickRedirectsToTheRegisteredDestination(): void
    {
        $signature = Tokens::sign(TrackingService::PURPOSE_CLICK, $this->token(), (string) $this->linkId);
        $resolved = (array) TrackingService::resolveRecipient(
            $this->token(),
            $signature,
            TrackingService::PURPOSE_CLICK,
            (string) $this->linkId
        );

        $destination = TrackingService::recordClick($resolved, $this->linkId, 'Mozilla/5.0', '203.0.113.4');

        self::assertSame('https://techzapp.test/offer', $destination);
        self::assertSame(1, ReportService::computeCampaignMetrics($this->campaignId)['unique_clicks']);
    }

    public function testClickingAlsoCountsAsAnOpen(): void
    {
        $signature = Tokens::sign(TrackingService::PURPOSE_CLICK, $this->token(), (string) $this->linkId);
        $resolved = (array) TrackingService::resolveRecipient(
            $this->token(),
            $signature,
            TrackingService::PURPOSE_CLICK,
            (string) $this->linkId
        );
        TrackingService::recordClick($resolved, $this->linkId, '', '');

        self::assertNotNull(Database::scalar(
            'SELECT first_opened_at FROM campaign_recipients WHERE id = :id',
            ['id' => $this->recipient['id']]
        ));
    }

    public function testALinkFromAnotherCampaignCannotBeRedirectedTo(): void
    {
        $otherList = ListService::create('Other', '', null);
        ListService::assign($otherList, [$this->makeContact('other@example.com')], null);
        $otherCampaign = $this->makeCampaign($otherList);
        $foreignLinkId = CampaignRenderer::registerLink($otherCampaign, 'https://evil.test/phish');

        $signature = Tokens::sign(TrackingService::PURPOSE_CLICK, $this->token(), (string) $foreignLinkId);
        $resolved = (array) TrackingService::resolveRecipient(
            $this->token(),
            $signature,
            TrackingService::PURPOSE_CLICK,
            (string) $foreignLinkId
        );

        self::assertNull(
            TrackingService::recordClick($resolved, $foreignLinkId, '', ''),
            'Link ids are scoped to their campaign.'
        );
    }

    public function testAnUnsafeStoredDestinationIsNotRedirectedTo(): void
    {
        // Even if a dangerous URL somehow reached the table, the endpoint
        // re-validates before redirecting.
        Database::execute(
            'UPDATE campaign_links SET url = :url WHERE id = :id',
            ['url' => 'javascript:alert(1)', 'id' => $this->linkId]
        );

        $signature = Tokens::sign(TrackingService::PURPOSE_CLICK, $this->token(), (string) $this->linkId);
        $resolved = (array) TrackingService::resolveRecipient(
            $this->token(),
            $signature,
            TrackingService::PURPOSE_CLICK,
            (string) $this->linkId
        );

        self::assertNull(TrackingService::recordClick($resolved, $this->linkId, '', ''));
    }

    public function testTrackingUrlsContainNoDatabaseIdentifiers(): void
    {
        $openUrl = TrackingService::openPixelUrl($this->token());
        $clickUrl = TrackingService::clickUrl($this->token(), $this->linkId);
        $unsubscribeUrl = TrackingService::unsubscribeUrl($this->token());

        foreach ([$openUrl, $unsubscribeUrl] as $url) {
            self::assertStringNotContainsString('tracked@example.com', $url);
            self::assertStringNotContainsString('contact_id', $url);
            self::assertStringNotContainsString('=' . $this->recipient['id'] . '&', $url);
        }

        self::assertStringContainsString($this->token(), $clickUrl);
        self::assertStringNotContainsString('techzapp.test/offer', $clickUrl, 'The destination is never in the URL.');
    }

    public function testIpAddressesAreTruncatedBeforeStorage(): void
    {
        self::assertSame('203.0.113.0', TrackingService::anonymiseIp('203.0.113.42'));
        self::assertSame('2001:db8:1234::', TrackingService::anonymiseIp('2001:db8:1234:5678::1'));
        self::assertSame('', TrackingService::anonymiseIp('not-an-ip'));
    }

    public function testPublicEndpointsAreRateLimited(): void
    {
        // A small limit injected for the test: hammering the real 1000-hit
        // budget takes long enough that the 60-second window can roll over
        // mid-loop, which would reset the counter and make this flaky.
        $limit = 5;
        $window = 60;
        $this->withRateLimit($limit, $window, function () use ($limit, $window): void {
            $ip = '203.0.113.' . random_int(1, 254);

            // Retry once if the window boundary falls inside the burst.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $windowBefore = (int) floor(time() / $window);

                $allowed = 0;
                for ($i = 0; $i < $limit * 2; $i++) {
                    if (TrackingService::allowPublicRequest('open', $ip)) {
                        $allowed++;
                    }
                }

                if ((int) floor(time() / $window) === $windowBefore) {
                    self::assertSame($limit, $allowed, 'Exactly the budgeted number of requests should pass.');
                    self::assertFalse(
                        TrackingService::allowPublicRequest('open', $ip),
                        'Further requests in the same window must be refused.',
                    );
                    return;
                }

                $ip = '203.0.113.' . random_int(1, 254);
            }

            self::markTestSkipped('Could not complete a burst inside one rate-limit window.');
        });
    }

    public function testRateLimitIsPerIpNotGlobal(): void
    {
        $this->withRateLimit(3, 60, function (): void {
            for ($i = 0; $i < 5; $i++) {
                TrackingService::allowPublicRequest('open', '198.51.100.1');
            }

            self::assertFalse(TrackingService::allowPublicRequest('open', '198.51.100.1'));
            self::assertTrue(
                TrackingService::allowPublicRequest('open', '198.51.100.2'),
                'One noisy client must not lock everyone else out.',
            );
        });
    }

    public function testRateLimitScopesAreIndependent(): void
    {
        $this->withRateLimit(3, 60, function (): void {
            for ($i = 0; $i < 5; $i++) {
                TrackingService::allowPublicRequest('open', '198.51.100.3');
            }

            self::assertFalse(TrackingService::allowPublicRequest('open', '198.51.100.3'));
            self::assertTrue(
                TrackingService::allowPublicRequest('click', '198.51.100.3'),
                'Exhausting the pixel budget must not block click tracking.',
            );
        });
    }

    /** Runs $work with a temporary public rate limit, then restores the config. */
    private function withRateLimit(int $hits, int $window, callable $work): void
    {
        $config = \Techzapp\Crm\Core\Config::load();
        $values = [
            'app'      => $config->array('app'),
            'database' => $config->array('database'),
            'session'  => $config->array('session'),
            'security' => $config->array('security') + [],
            'ses'      => $config->array('ses'),
            'sns'      => $config->array('sns'),
            'storage'  => $config->array('storage'),
            'cron'     => $config->array('cron'),
        ];
        $values['security']['public_rate_limit'] = ['hits' => $hits, 'window' => $window];

        \Techzapp\Crm\Core\Config::setInstance(\Techzapp\Crm\Core\Config::fromArray($values));

        try {
            $work();
        } finally {
            \Techzapp\Crm\Core\Config::setInstance($config);
        }
    }

    // ----------------------------------------------------------- unsubscribe

    public function testUnsubscribeSuppressesGloballyAndStopsFutureSends(): void
    {
        $email = (string) $this->recipient['recipient_email_snapshot'];

        SuppressionService::suppress($email, 'unsubscribe', 'one_click_unsubscribe', $this->campaignId);

        self::assertTrue(SuppressionService::isSuppressed($email));

        // A brand-new campaign to the same person must skip them.
        $newList = ListService::create('After opt-out', '', null);
        $contactId = (int) Database::scalar(
            'SELECT id FROM contacts WHERE normalized_email = :e',
            ['e' => $email]
        );
        ListService::assign($newList, [$contactId], null);

        $nextCampaign = $this->makeCampaign($newList);
        Database::execute("UPDATE campaigns SET status = 'queued' WHERE id = :id", ['id' => $nextCampaign]);

        $transport = new RecordingTransport();
        Mailer::setTransport($transport);
        CampaignSender::processCampaign($nextCampaign, microtime(true) + 10);

        self::assertCount(0, $transport->sent, 'An unsubscribed contact must never be emailed again.');
        self::assertSame('suppressed', Database::scalar(
            'SELECT status FROM campaign_recipients WHERE campaign_id = :id',
            ['id' => $nextCampaign]
        ));
    }

    public function testContactScopedUnsubscribeTokenAlsoWorks(): void
    {
        $contactToken = (string) Database::scalar(
            'SELECT unsubscribe_token FROM contacts WHERE normalized_email = :e',
            ['e' => 'tracked@example.com']
        );

        $url = TrackingService::contactUnsubscribeUrl($contactToken);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertTrue(Tokens::verify(
            (string) $query['s'],
            TrackingService::PURPOSE_UNSUBSCRIBE,
            'c:' . $contactToken
        ));
        self::assertSame($contactToken, $query['c']);
    }

    public function testSuppressionHistoryIsAuditable(): void
    {
        $email = 'history@example.com';

        SuppressionService::suppress($email, 'unsubscribe', 'landing_page', null, 'opted out');
        SuppressionService::suppress($email, 'complaint', 'ses_event', null, 'marked as spam');
        SuppressionService::release($email, null, 'customer asked to be re-added');

        $history = SuppressionService::history($email);

        self::assertCount(3, $history);
        self::assertSame('removed', $history[0]['action']);
        self::assertSame('reaffirmed', $history[1]['action']);
        self::assertSame('added', $history[2]['action']);
    }

    public function testReleasingASuppressionClearsTheContactOptOutFlag(): void
    {
        $email = (string) $this->recipient['recipient_email_snapshot'];
        SuppressionService::suppress($email, 'unsubscribe', 'test');

        self::assertNotNull(Database::scalar(
            'SELECT unsubscribed_at FROM contacts WHERE normalized_email = :e',
            ['e' => $email]
        ));

        SuppressionService::release($email, null, 'explicit admin action');

        self::assertFalse(SuppressionService::isSuppressed($email));
        self::assertNull(Database::scalar(
            'SELECT unsubscribed_at FROM contacts WHERE normalized_email = :e',
            ['e' => $email]
        ));
    }

    public function testSuppressionSurvivesContactDeletion(): void
    {
        $email = 'deleted@example.com';
        $contactId = $this->makeContact($email);
        SuppressionService::suppress($email, 'complaint', 'test');

        Database::execute('DELETE FROM contacts WHERE id = :id', ['id' => $contactId]);

        self::assertTrue(
            SuppressionService::isSuppressed($email),
            'Suppression keys on the address, so deleting the contact cannot clear it.'
        );
    }
}
