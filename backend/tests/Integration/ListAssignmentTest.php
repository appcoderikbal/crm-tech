<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Integration;

use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Services\ContactService;
use Techzapp\Crm\Services\ListService;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Tests\IntegrationTestCase;

final class ListAssignmentTest extends IntegrationTestCase
{
    public function testAssignedContactDisappearsFromTheUnassignedPage(): void
    {
        $contactId = $this->makeContact('one@example.com');
        $this->makeContact('two@example.com');
        $listId = ListService::create('Prospects', '', null);

        self::assertSame(2, ContactService::paginate(['assignment' => 'unassigned'])['total']);

        ListService::assign($listId, [$contactId], null);

        $unassigned = ContactService::paginate(['assignment' => 'unassigned']);
        self::assertSame(1, $unassigned['total']);
        self::assertSame('two@example.com', $unassigned['items'][0]['email']);
        self::assertSame(1, ContactService::counts()['assigned']);
    }

    public function testMovingAContactReplacesItsPreviousMembership(): void
    {
        $contactId = $this->makeContact('mover@example.com');
        $listA = ListService::create('List A', '', null);
        $listB = ListService::create('List B', '', null);

        ListService::assign($listA, [$contactId], null);
        $result = ListService::assign($listB, [$contactId], null);

        self::assertSame(1, $result['moved']);
        self::assertSame(0, $result['assigned']);
        self::assertSame(1, (int) Database::scalar(
            'SELECT COUNT(*) FROM list_contacts WHERE contact_id = :id',
            ['id' => $contactId]
        ));
        self::assertSame(0, ListService::find($listA)['contact_count']);
        self::assertSame(1, ListService::find($listB)['contact_count']);
    }

    public function testReassigningToTheSameListIsANoOp(): void
    {
        $contactId = $this->makeContact('same@example.com');
        $listId = ListService::create('List', '', null);

        ListService::assign($listId, [$contactId], null);
        $second = ListService::assign($listId, [$contactId], null);

        self::assertSame(1, $second['unchanged']);
        self::assertSame(0, $second['assigned']);
        self::assertSame(0, $second['moved']);
    }

    public function testRemovingAContactReturnsItToTheUnassignedPool(): void
    {
        $contactId = $this->makeContact('back@example.com');
        $listId = ListService::create('List', '', null);
        ListService::assign($listId, [$contactId], null);

        $removed = ListService::removeContacts($listId, [$contactId]);

        self::assertSame(1, $removed);
        self::assertSame(1, ContactService::paginate(['assignment' => 'unassigned'])['total']);
    }

    public function testDeletingAListReleasesContactsWithoutDeletingThem(): void
    {
        $ids = [$this->makeContact('a@example.com'), $this->makeContact('b@example.com')];
        $listId = ListService::create('Doomed', '', null);
        ListService::assign($listId, $ids, null);

        $result = ListService::delete($listId, true);

        self::assertSame(2, $result['released']);
        self::assertSame(2, ContactService::counts()['total'], 'Contacts must survive list deletion.');
        self::assertSame(2, ContactService::counts()['unassigned']);
        self::assertNull(ListService::find($listId));
    }

    public function testDeletingAListRequiresExplicitConfirmation(): void
    {
        $listId = ListService::create('Guarded', '', null);
        ListService::assign($listId, [$this->makeContact('c@example.com')], null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/Confirm to proceed/');

        ListService::delete($listId, false);
    }

    public function testDeletingAListUsedByACampaignIsRefused(): void
    {
        $listId = ListService::create('Used', '', null);
        ListService::assign($listId, [$this->makeContact('d@example.com')], null);
        $this->makeCampaign($listId);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/campaign/i');

        ListService::delete($listId, true);
    }

    public function testSuppressionIsIndependentOfListMembership(): void
    {
        $contactId = $this->makeContact('suppressed@example.com');
        $listId = ListService::create('List', '', null);

        SuppressionService::suppress('suppressed@example.com', 'complaint', 'test');
        ListService::assign($listId, [$contactId], null);
        ListService::removeContacts($listId, [$contactId]);

        self::assertTrue(
            SuppressionService::isSuppressed('suppressed@example.com'),
            'Moving a contact between lists must not clear its suppression.'
        );
    }

    public function testAssignByFilterTargetsOnlyMatchingContacts(): void
    {
        $this->makeContact('uk1@example.com', ['country' => 'United Kingdom']);
        $this->makeContact('uk2@example.com', ['country' => 'United Kingdom']);
        $this->makeContact('fr1@example.com', ['country' => 'France']);
        $listId = ListService::create('UK', '', null);

        $result = ListService::assignByFilter($listId, ['country' => 'United Kingdom'], null);

        self::assertSame(2, $result['assigned']);
        self::assertSame(2, ListService::find($listId)['contact_count']);
    }

    public function testDatabaseRejectsASecondMembershipDirectly(): void
    {
        $contactId = $this->makeContact('constrained@example.com');
        $listA = ListService::create('A', '', null);
        $listB = ListService::create('B', '', null);
        ListService::assign($listA, [$contactId], null);

        // Bypass the service entirely: the guarantee has to come from the
        // schema, not from application code being well behaved.
        $this->expectExceptionMessageMatches('/Duplicate entry|Integrity constraint/i');

        Database::execute(
            'INSERT INTO list_contacts (list_id, contact_id) VALUES (:list, :contact)',
            ['list' => $listB, 'contact' => $contactId]
        );
    }

    /**
     * Eight separate processes assign the same contacts to eight different
     * lists at the same moment. Whatever order they land in, each contact must
     * end up with exactly one membership.
     */
    public function testConcurrentAssignmentsCannotCreateTwoMemberships(): void
    {
        $contactIds = [];
        for ($i = 1; $i <= 25; $i++) {
            $contactIds[] = $this->makeContact("race{$i}@example.com");
        }

        $listIds = [];
        for ($i = 1; $i <= 8; $i++) {
            $listIds[] = ListService::create("Race list {$i}", '', null);
        }

        $startAt = (microtime(true) + 1.5) * 1000;
        $workers = [];
        foreach ($listIds as $listId) {
            $workers[] = $this->runWorker(
                __DIR__ . '/../workers/assign_worker.php',
                [$listId, implode(',', $contactIds), (int) $startAt]
            );
        }

        $failures = [];
        foreach ($workers as $worker) {
            $outcome = $this->waitForWorker($worker);
            if ($outcome['exit_code'] !== 0) {
                $failures[] = $outcome['stderr'] !== '' ? $outcome['stderr'] : $outcome['stdout'];
            }
        }

        self::assertSame([], $failures, 'Every worker should complete without error.');

        $duplicates = Database::all(
            'SELECT contact_id, COUNT(*) AS memberships
               FROM list_contacts
           GROUP BY contact_id
             HAVING COUNT(*) > 1'
        );
        self::assertSame([], $duplicates, 'A contact ended up on more than one list.');

        $total = (int) Database::scalar('SELECT COUNT(*) FROM list_contacts');
        self::assertSame(25, $total, 'Every contact should hold exactly one membership.');

        // The counts the UI shows must still add up after the race.
        $counts = ContactService::counts();
        self::assertSame(25, $counts['assigned']);
        self::assertSame(0, $counts['unassigned']);

        $sumOfListCounts = 0;
        foreach ($listIds as $listId) {
            $list = ListService::find($listId);
            $sumOfListCounts += $list === null ? 0 : $list['contact_count'];
        }
        self::assertSame(25, $sumOfListCounts, 'Per-list counts must sum to the number of contacts.');
    }
}
