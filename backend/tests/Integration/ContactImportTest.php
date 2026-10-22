<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Services\ContactService;
use Techzapp\Crm\Services\ImportService;
use Techzapp\Crm\Services\ListService;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Tests\IntegrationTestCase;

final class ContactImportTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }

    private function writeCsv(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'crmimport');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;
        return $path;
    }

    /** Creates an import job for an already-staged file (skips the HTTP upload). */
    private function makeJob(string $path, string $duplicateMode = 'skip', ?int $listId = null, array $mapping = null): int
    {
        Database::execute(
            "INSERT INTO import_jobs (original_filename, stored_path, file_size_bytes, file_checksum,
                                      delimiter_char, has_header, status)
             VALUES (:name, :path, :size, :checksum, ',', 1, 'uploaded')",
            [
                'name'     => basename($path),
                'path'     => $path,
                'size'     => (int) filesize($path),
                'checksum' => (string) hash_file('sha256', $path),
            ]
        );
        $jobId = Database::lastInsertId();

        ImportService::start(
            $jobId,
            $mapping ?? ['email' => 0, 'first_name' => 1, 'last_name' => 2, 'company_name' => 3],
            $duplicateMode,
            $listId,
            true,
            [],
            null
        );

        return $jobId;
    }

    private function runToCompletion(int $jobId, int $maxChunks = 500): array
    {
        $job = [];
        for ($i = 0; $i < $maxChunks; $i++) {
            $job = ImportService::processChunk($jobId, microtime(true) + 20);
            if (in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
                break;
            }
        }
        return $job;
    }

    public function testImportsRowsAndReportsCounts(): void
    {
        $path = $this->writeCsv(
            "email,first,last,company\n"
            . "a@example.com,Ann,Smith,Acme\n"
            . "b@example.com,Ben,Jones,Globex\n"
            . "c@example.com,Cal,Brown,Initech\n"
        );

        $job = $this->runToCompletion($this->makeJob($path));

        self::assertSame('completed', $job['status']);
        self::assertSame(3, $job['total_rows']);
        self::assertSame(3, $job['inserted_rows']);
        self::assertSame(0, $job['failed_rows']);
        self::assertSame(3, ContactService::counts()['total']);

        $contact = ContactService::findByEmail('b@example.com');
        self::assertSame('Ben', $contact['first_name']);
        self::assertSame('Globex', $contact['company_name']);
    }

    public function testInvalidRowsAreReportedNotImported(): void
    {
        $path = $this->writeCsv(
            "email,first,last,company\n"
            . "good@example.com,Ann,Smith,Acme\n"
            . "not-an-email,Ben,Jones,Globex\n"
            . ",Cal,Brown,Initech\n"
            . "also bad@,Dee,Green,Hooli\n"
        );

        $job = $this->runToCompletion($this->makeJob($path));

        self::assertSame(1, $job['inserted_rows']);
        self::assertSame(3, $job['failed_rows']);
        self::assertSame(1, ContactService::counts()['total']);

        $errors = ImportService::errors($job['id']);
        self::assertCount(3, $errors);

        $codes = array_column($errors, 'error_code');
        self::assertContains('invalid_email', $codes);
        self::assertContains('missing_email', $codes);

        // Every error row carries the line number so the operator can fix it.
        foreach ($errors as $error) {
            self::assertGreaterThan(0, (int) $error['csv_row_number']);
        }
    }

    public function testDuplicateEmailsAreNeverInsertedTwice(): void
    {
        $path = $this->writeCsv(
            "email,first,last,company\n"
            . "dup@example.com,First,Entry,One\n"
            . "DUP@Example.com,Second,Entry,Two\n"
            . " dup@example.com ,Third,Entry,Three\n"
        );

        $job = $this->runToCompletion($this->makeJob($path));

        self::assertSame(1, $job['inserted_rows']);
        self::assertSame(2, $job['skipped_rows']);
        self::assertSame(1, (int) Database::scalar('SELECT COUNT(*) FROM contacts'));
    }

    public function testSkipModeLeavesExistingContactsUntouched(): void
    {
        $this->makeContact('existing@example.com', ['first_name' => 'Original', 'company_name' => 'Original Co']);

        $path = $this->writeCsv("email,first,last,company\nexisting@example.com,Changed,Name,Changed Co\n");
        $job = $this->runToCompletion($this->makeJob($path, 'skip'));

        self::assertSame(1, $job['skipped_rows']);
        self::assertSame(0, $job['updated_rows']);
        self::assertSame('Original', ContactService::findByEmail('existing@example.com')['first_name']);
    }

    public function testUpdateModeRefreshesExistingContacts(): void
    {
        $this->makeContact('existing@example.com', ['first_name' => 'Original', 'company_name' => 'Original Co']);

        $path = $this->writeCsv("email,first,last,company\nexisting@example.com,Changed,Name,Changed Co\n");
        $job = $this->runToCompletion($this->makeJob($path, 'update'));

        self::assertSame(1, $job['updated_rows']);
        self::assertSame(0, $job['inserted_rows']);

        $contact = ContactService::findByEmail('existing@example.com');
        self::assertSame('Changed', $contact['first_name']);
        self::assertSame('Changed Co', $contact['company_name']);
    }

    public function testUpdateModeDoesNotOverwriteStoredValuesWithBlankColumns(): void
    {
        $this->makeContact('keep@example.com', ['first_name' => 'Keep', 'company_name' => 'Keep Co']);

        $path = $this->writeCsv("email,first,last,company\nkeep@example.com,,Updated,\n");
        $this->runToCompletion($this->makeJob($path, 'update'));

        $contact = ContactService::findByEmail('keep@example.com');
        self::assertSame('Keep', $contact['first_name'], 'A blank cell must not erase stored data.');
        self::assertSame('Updated', $contact['last_name']);
        self::assertSame('Keep Co', $contact['company_name']);
    }

    public function testReimportingASuppressedAddressDoesNotResurrectIt(): void
    {
        $this->makeContact('optout@example.com');
        SuppressionService::suppress('optout@example.com', 'complaint', 'test');

        $path = $this->writeCsv("email,first,last,company\noptout@example.com,New,Name,New Co\n");
        $this->runToCompletion($this->makeJob($path, 'update'));

        self::assertTrue(
            SuppressionService::isSuppressed('optout@example.com'),
            'An opt-out must survive re-import.'
        );
        self::assertNotNull(
            Database::scalar('SELECT unsubscribed_at FROM contacts WHERE normalized_email = :e', ['e' => 'optout@example.com']),
            'The contact must stay marked as opted out.'
        );
    }

    public function testImportCanAssignEveryRowToAList(): void
    {
        $listId = ListService::create('Imported', '', null);
        $path = $this->writeCsv(
            "email,first,last,company\n"
            . "l1@example.com,A,B,C\nl2@example.com,D,E,F\nl3@example.com,G,H,I\n"
        );

        $this->runToCompletion($this->makeJob($path, 'skip', $listId));

        self::assertSame(3, ListService::find($listId)['contact_count']);
        self::assertSame(0, ContactService::counts()['unassigned']);
    }

    public function testImportResumesAfterAnInterruptedChunk(): void
    {
        $rows = "email,first,last,company\n";
        for ($i = 1; $i <= 120; $i++) {
            $rows .= "resume{$i}@example.com,First{$i},Last,Co\n";
        }
        $path = $this->writeCsv($rows);

        Settings::set('import_batch_rows', 60);
        $jobId = $this->makeJob($path);

        // One chunk only, as if the request ended there.
        $afterOne = ImportService::processChunk($jobId, microtime(true) + 20);
        self::assertSame(60, $afterOne['processed_rows']);
        self::assertSame('processing', $afterOne['status']);
        self::assertSame(60, (int) Database::scalar('SELECT COUNT(*) FROM contacts'));

        $final = $this->runToCompletion($jobId);

        self::assertSame('completed', $final['status']);
        self::assertSame(120, $final['processed_rows'], 'Resume must continue, not restart.');
        self::assertSame(120, $final['inserted_rows']);
        self::assertSame(120, (int) Database::scalar('SELECT COUNT(*) FROM contacts'));
    }

    public function testCustomFieldsAreCaptured(): void
    {
        $path = $this->writeCsv("email,plan,seats\ncustom@example.com,Pro,12\n");

        $jobId = $this->makeJob($path, 'skip', null, ['email' => 0]);
        Database::execute(
            "UPDATE import_jobs SET options_json = :options WHERE id = :id",
            ['options' => json_encode(['custom_fields' => ['plan' => 1, 'seats' => 2]]), 'id' => $jobId]
        );

        $this->runToCompletion($jobId);

        $contact = ContactService::findByEmail('custom@example.com');
        self::assertSame(['plan' => 'Pro', 'seats' => '12'], $contact['custom_fields']);
    }

    public function testCsvContentIsStoredAsInertText(): void
    {
        $path = $this->writeCsv(
            "email,first,last,company\n"
            . "xss@example.com,\"<script>alert(1)</script>\",\"=cmd|'/C calc'!A0\",\"Acme\"\n"
        );

        $this->runToCompletion($this->makeJob($path));

        $contact = ContactService::findByEmail('xss@example.com');
        // Stored verbatim as data — never executed, and escaped at every output
        // boundary (HTML rendering and CSV export are covered by unit tests).
        self::assertSame('<script>alert(1)</script>', $contact['first_name']);
        self::assertSame("=cmd|'/C calc'!A0", $contact['last_name']);
    }

    /**
     * The headline acceptance criterion: 40,000 rows, imported in bounded
     * chunks, with a flat memory profile.
     */
    #[Group('slow')]
    public function testFortyThousandRowImport(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'crmbig');
        $this->temporaryFiles[] = $path;

        $handle = fopen($path, 'wb');
        fwrite($handle, "email,first,last,company\n");
        for ($i = 1; $i <= 40000; $i++) {
            // Every 500th row is a duplicate of an earlier one, and every
            // 1000th is invalid, so the counts are not trivially 40000.
            $email = $i % 500 === 0 ? 'bulk' . ($i - 1) . '@example.com' : "bulk{$i}@example.com";
            if ($i % 1000 === 0) {
                $email = 'not-an-email-' . $i;
            }
            fwrite($handle, "{$email},First{$i},Last{$i},Company{$i}\n");
        }
        fclose($handle);

        Settings::set('import_batch_rows', 1000);
        $jobId = $this->makeJob($path);

        self::assertSame(40000, ImportService::findOrFail($jobId)['total_rows']);

        $baselineMemory = memory_get_usage(true);
        $chunks = 0;

        do {
            $job = ImportService::processChunk($jobId, microtime(true) + 60);
            $chunks++;
            self::assertLessThan(
                $baselineMemory + (64 * 1024 * 1024),
                memory_get_usage(true),
                'Memory must stay flat across chunks — the file is never fully loaded.'
            );
        } while ($job['status'] === 'processing' && $chunks < 100);

        self::assertSame('completed', $job['status']);
        self::assertSame(40000, $job['processed_rows']);
        self::assertGreaterThan(1, $chunks, 'A 40k import must take more than one chunk.');

        // 40 invalid rows (every 1000th), 80 duplicates (every 500th, minus the
        // 40 that were overwritten by the invalid rule), 39880 inserted.
        self::assertSame(40, $job['failed_rows']);
        self::assertSame(40, $job['skipped_rows']);
        self::assertSame(39920, $job['inserted_rows']);
        self::assertSame(39920, (int) Database::scalar('SELECT COUNT(*) FROM contacts'));

        // No duplicates reached the table.
        self::assertSame(
            39920,
            (int) Database::scalar('SELECT COUNT(DISTINCT normalized_email) FROM contacts')
        );

        // And pagination over the result set is still cheap.
        $started = microtime(true);
        $page = ContactService::paginate(['assignment' => 'unassigned', 'page' => 200, 'per_page' => 25]);
        self::assertCount(25, $page['items']);
        self::assertLessThan(5.0, microtime(true) - $started, 'Deep pagination should stay responsive.');
    }

    public function testExpiredImportFilesAreDeletedButHistoryIsKept(): void
    {
        $path = $this->writeCsv("email\nretain@example.com\n");
        $jobId = $this->makeJob($path, 'skip', null, ['email' => 0]);
        $this->runToCompletion($jobId);

        Database::execute(
            'UPDATE import_jobs SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY) WHERE id = :id',
            ['id' => $jobId]
        );

        $result = ImportService::cleanupExpiredFiles();

        self::assertSame(1, $result['files_deleted']);
        self::assertFileDoesNotExist($path);
        self::assertSame('completed', ImportService::findOrFail($jobId)['status'], 'History row must remain.');
    }
}
