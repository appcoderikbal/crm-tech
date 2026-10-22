<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Integration;

use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Services\CampaignService;
use Techzapp\Crm\Services\ContactService;
use Techzapp\Crm\Services\ListService;
use Techzapp\Crm\Services\ReportService;
use Techzapp\Crm\Tests\IntegrationTestCase;

/**
 * Search and filtering, executed against the real database.
 *
 * These exist because a multi-column search clause reused one named
 * placeholder, which PDO rejects when prepare emulation is disabled. Service
 * tests that never passed a search term could not see it; every search path now
 * has a test that actually runs the query.
 */
final class SearchAndFilterTest extends IntegrationTestCase
{
    private int $listId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listId = ListService::create('Prospect list', 'High intent prospects', null);

        $ids = [
            $this->makeContact('ada@northwind.test', [
                'first_name' => 'Ada', 'last_name' => 'Lovelace',
                'company_name' => 'Northwind Trading', 'country' => 'United Kingdom',
            ]),
            $this->makeContact('grace@hopper.test', [
                'first_name' => 'Grace', 'last_name' => 'Hopper',
                'company_name' => 'Hopper Systems', 'country' => 'United States',
            ]),
            $this->makeContact('alan@bletchley.test', [
                'first_name' => 'Alan', 'last_name' => 'Turing',
                'company_name' => 'Bletchley Labs', 'country' => 'United Kingdom',
            ]),
        ];

        ListService::assign($this->listId, [$ids[0]], null);
    }

    public function testContactSearchMatchesAcrossEveryColumn(): void
    {
        // Each of these hits a different column of the multi-column clause.
        self::assertSame(1, ContactService::paginate(['assignment' => 'any', 'search' => 'Grace'])['total']);
        self::assertSame(1, ContactService::paginate(['assignment' => 'any', 'search' => 'Turing'])['total']);
        self::assertSame(1, ContactService::paginate(['assignment' => 'any', 'search' => 'Northwind'])['total']);
        self::assertSame(2, ContactService::paginate(['assignment' => 'any', 'search' => 'United Kingdom'])['total']);
        self::assertSame(1, ContactService::paginate(['assignment' => 'any', 'search' => 'bletchley'])['total']);
    }

    public function testContactSearchIsCombinedWithTheAssignmentFilter(): void
    {
        // Ada is on a list, so the unassigned page must not return her.
        self::assertSame(0, ContactService::paginate(['assignment' => 'unassigned', 'search' => 'Ada'])['total']);
        self::assertSame(1, ContactService::paginate(['assignment' => 'assigned', 'search' => 'Ada'])['total']);
    }

    public function testExactEmailSearchTakesTheIndexedPath(): void
    {
        $result = ContactService::paginate(['assignment' => 'any', 'search' => 'GRACE@Hopper.test']);

        self::assertSame(1, $result['total']);
        self::assertSame('grace@hopper.test', $result['items'][0]['email']);
    }

    public function testPartialEmailSearchStillMatches(): void
    {
        self::assertSame(1, ContactService::paginate(['assignment' => 'any', 'search' => 'hopper.test'])['total']);
    }

    public function testSearchTermsAreTreatedAsLiteralsNotSql(): void
    {
        $injection = ContactService::paginate(['assignment' => 'any', 'search' => "' OR 1=1 --"]);

        self::assertSame(0, $injection['total'], 'An injection attempt must match nothing, not everything.');
        self::assertSame(3, (int) Database::scalar('SELECT COUNT(*) FROM contacts'), 'And must not alter data.');
    }

    public function testWildcardCharactersInTheSearchAreEscaped(): void
    {
        $this->makeContact('literal@percent.test', ['company_name' => '100% Cotton']);

        // A bare "%" must not behave as "match everything".
        self::assertSame(1, ContactService::paginate(['assignment' => 'any', 'search' => '%'])['total']);
        self::assertSame(1, ContactService::paginate(['assignment' => 'any', 'search' => '100%'])['total']);
        self::assertSame(0, ContactService::paginate(['assignment' => 'any', 'search' => '_'])['total']);
    }

    public function testContactFiltersCombine(): void
    {
        $result = ContactService::paginate([
            'assignment' => 'any',
            'country'    => 'United Kingdom',
            'status'     => 'active',
            'suppressed' => 'no',
        ]);

        self::assertSame(2, $result['total']);
    }

    public function testContactSortingWorksForEverySortableColumn(): void
    {
        foreach (array_keys(ContactService::SORTABLE) as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $result = ContactService::paginate([
                    'assignment' => 'any',
                    'sort'       => $column,
                    'direction'  => $direction,
                ]);
                self::assertSame(3, $result['total'], "Sorting by {$column} {$direction} failed.");
            }
        }
    }

    public function testUnknownSortColumnFallsBackInsteadOfInjecting(): void
    {
        $result = ContactService::paginate([
            'assignment' => 'any',
            'sort'       => 'created_at; DROP TABLE contacts',
        ]);

        self::assertSame(3, $result['total']);
        self::assertSame(3, (int) Database::scalar('SELECT COUNT(*) FROM contacts'));
    }

    public function testListSearchMatchesNameAndDescription(): void
    {
        ListService::create('Customers', 'Paying accounts', null);

        self::assertCount(1, ListService::all('Prospect'));
        self::assertCount(1, ListService::all('high intent'), 'Description must be searched too.');
        self::assertCount(2, ListService::all(''));
        self::assertCount(0, ListService::all('nothing matches this'));
    }

    public function testCampaignSearchMatchesNameAndSubject(): void
    {
        $this->makeCampaign($this->listId, ['name' => 'Spring launch', 'subject' => 'Our new release']);
        $this->makeCampaign($this->listId, ['name' => 'Autumn update', 'subject' => 'Quarterly digest']);

        self::assertSame(1, CampaignService::paginate(['search' => 'Spring'])['total']);
        self::assertSame(1, CampaignService::paginate(['search' => 'Quarterly'])['total'], 'Subject must be searched too.');
        self::assertSame(2, CampaignService::paginate([])['total']);
    }

    public function testCampaignStatusFilterAcceptsMultipleStatuses(): void
    {
        $draft = $this->makeCampaign($this->listId, ['name' => 'Still a draft']);
        $cancelled = $this->makeCampaign($this->listId, ['name' => 'Cancelled one']);
        CampaignService::cancel($cancelled, null);

        self::assertSame(1, CampaignService::paginate(['status' => ['draft']])['total']);
        self::assertSame(2, CampaignService::paginate(['status' => ['draft', 'cancelled']])['total']);
        self::assertSame(0, CampaignService::paginate(['status' => ['sending']])['total']);

        // An unknown status must not silently widen the result set.
        self::assertSame(0, CampaignService::paginate(['status' => ['nonsense']])['total']);
        self::assertNotNull(CampaignService::find($draft));
    }

    public function testCampaignRecipientSearchAndFilters(): void
    {
        $campaignId = $this->makeCampaign($this->listId);
        Database::execute(
            "INSERT INTO campaign_recipients (campaign_id, contact_id, recipient_email_snapshot, tracking_token, status)
             VALUES (:c1, NULL, 'one@example.com', :t1, 'delivered'),
                    (:c2, NULL, 'two@example.com', :t2, 'bounced')",
            [
                'c1' => $campaignId, 't1' => str_repeat('a', 32),
                'c2' => $campaignId, 't2' => str_repeat('b', 32),
            ]
        );

        self::assertSame(2, ReportService::campaignRecipients($campaignId, [])['total']);
        self::assertSame(1, ReportService::campaignRecipients($campaignId, ['status' => 'bounced'])['total']);
        self::assertSame(1, ReportService::campaignRecipients($campaignId, ['search' => 'one@'])['total']);
        self::assertSame(0, ReportService::campaignRecipients($campaignId, ['search' => "' OR 1=1 --"])['total']);
    }

    public function testDistinctFilterValuesAreRestrictedToKnownColumns(): void
    {
        self::assertContains('United Kingdom', ContactService::distinctValues('country'));
        self::assertContains('phpunit', ContactService::distinctValues('source'));

        // Anything else returns nothing rather than interpolating into SQL.
        self::assertSame([], ContactService::distinctValues('password_hash'));
        self::assertSame([], ContactService::distinctValues('id FROM users --'));
    }

    public function testPaginationIsStableAcrossPages(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->makeContact("page{$i}@example.com");
        }

        $first = ContactService::paginate(['assignment' => 'any', 'per_page' => 10, 'page' => 1, 'sort' => 'email', 'direction' => 'asc']);
        $second = ContactService::paginate(['assignment' => 'any', 'per_page' => 10, 'page' => 2, 'sort' => 'email', 'direction' => 'asc']);

        self::assertSame(33, $first['total']);
        self::assertSame(4, $first['pages']);
        self::assertCount(10, $first['items']);

        $firstIds = array_column($first['items'], 'id');
        $secondIds = array_column($second['items'], 'id');
        self::assertSame([], array_intersect($firstIds, $secondIds), 'Pages must not overlap.');
    }
}
