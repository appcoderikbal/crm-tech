<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Support\Csv;

final class CsvTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function write(string $contents): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'crmcsv');
        file_put_contents($this->file, $contents);
        return $this->file;
    }

    // ------------------------------------------------- formula injection

    #[DataProvider('dangerousCells')]
    public function testFormulaInjectionIsNeutralised(string $input): void
    {
        $escaped = Csv::escapeField($input);

        self::assertStringStartsWith("'", $escaped, 'Dangerous cell must be prefixed.');
        self::assertSame("'" . $input, $escaped);
    }

    public static function dangerousCells(): array
    {
        return [
            'equals'        => ['=1+1'],
            'cmd injection' => ['=cmd|\' /C calc\'!A0'],
            'plus'          => ['+1+1'],
            'minus'         => ['-1+1'],
            'at sign'       => ['@SUM(A1:A9)'],
            'tab'           => ["\tSUM(A1)"],
            'carriage'      => ["\rSUM(A1)"],
        ];
    }

    public function testOrdinaryCellsAreUnchanged(): void
    {
        self::assertSame('Acme Ltd', Csv::escapeField('Acme Ltd'));
        self::assertSame('person@example.com', Csv::escapeField('person@example.com'));
        self::assertSame('', Csv::escapeField(null));
        self::assertSame('1', Csv::escapeField(true));
    }

    public function testFormatRowQuotesAndEscapes(): void
    {
        $row = Csv::formatRow(['plain', 'has,comma', 'has"quote', "has\nnewline"]);

        self::assertSame("\"plain\",\"has,comma\",\"has\"\"quote\",\"has\nnewline\"\r\n", $row);
    }

    public function testFormatRowEscapesFormulaCells(): void
    {
        self::assertSame("\"'=1+1\"\r\n", Csv::formatRow(['=1+1']));
    }

    // ------------------------------------------------------------ reading

    public function testDetectsDelimiter(): void
    {
        self::assertSame(',', Csv::detectDelimiter($this->write("a,b,c\n1,2,3\n")));
        self::assertSame(';', Csv::detectDelimiter($this->write("a;b;c\n1;2;3\n")));
        self::assertSame("\t", Csv::detectDelimiter($this->write("a\tb\tc\n1\t2\t3\n")));
    }

    public function testReadsHeaderStrippingBom(): void
    {
        $path = $this->write("\xEF\xBB\xBFEmail,First Name\nx@y.com,Jo\n");

        self::assertSame(['Email', 'First Name'], Csv::readHeader($path));
    }

    public function testPreviewSkipsHeaderRow(): void
    {
        $path = $this->write("email,name\na@b.com,A\nc@d.com,C\n");

        self::assertSame([['a@b.com', 'A'], ['c@d.com', 'C']], Csv::preview($path, ',', true, 10));
    }

    public function testCountDataRowsIgnoresHeaderAndEmbeddedNewlines(): void
    {
        $path = $this->write("email,notes\na@b.com,\"line one\nline two\"\nc@d.com,ok\n");

        self::assertSame(2, Csv::countDataRows($path, ',', true));
    }

    public function testStreamBatchResumesFromRecordedOffset(): void
    {
        $rows = "email\n";
        for ($i = 1; $i <= 10; $i++) {
            $rows .= "person{$i}@example.com\n";
        }
        $path = $this->write($rows);

        $first = [];
        $batchOne = Csv::streamBatch($path, 0, 0, 4, ',', true, static function (array $row, int $number) use (&$first): void {
            $first[] = [$number, $row[0]];
        });

        self::assertCount(4, $first);
        self::assertSame([1, 'person1@example.com'], $first[0]);
        self::assertFalse($batchOne['eof']);

        $second = [];
        $batchTwo = Csv::streamBatch(
            $path,
            $batchOne['offset'],
            $batchOne['row_number'],
            100,
            ',',
            true,
            static function (array $row, int $number) use (&$second): void {
                $second[] = [$number, $row[0]];
            }
        );

        self::assertCount(6, $second, 'Resume must continue, not restart.');
        self::assertSame([5, 'person5@example.com'], $second[0]);
        self::assertSame([10, 'person10@example.com'], $second[5]);
        self::assertTrue($batchTwo['eof']);
    }

    public function testStreamBatchStopsAtDeadline(): void
    {
        $rows = "email\n";
        for ($i = 1; $i <= 50; $i++) {
            $rows .= "person{$i}@example.com\n";
        }
        $path = $this->write($rows);

        $seen = 0;
        $result = Csv::streamBatch(
            $path,
            0,
            0,
            50,
            ',',
            true,
            static function () use (&$seen): void {
                $seen++;
            },
            microtime(true) - 1 // deadline already passed
        );

        self::assertSame(0, $seen);
        self::assertSame(0, $result['rows']);
    }

    public function testCleanCellStripsControlCharacters(): void
    {
        self::assertSame('Acme', Csv::cleanCell("Acme\x00\x07"));
        self::assertSame('Email', Csv::cleanCell("\xEF\xBB\xBFEmail"));
    }
}
