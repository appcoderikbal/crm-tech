<?php

declare(strict_types=1);

namespace TechzappCrm\Support;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Streaming CSV reading and injection-safe CSV writing.
 *
 * The reader never holds more than one row in memory and exposes the byte
 * offset after each batch, which is what lets a 40k-row import resume on the
 * next cron run instead of restarting.
 */
final class Csv
{
    public const CHARACTERS_TO_ESCAPE = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Neutralises spreadsheet formula injection on export.
     *
     * Excel/Sheets execute a cell starting with = + - @ (or tab/CR). Prefixing
     * with a single quote makes the cell literal text in every major spreadsheet
     * while remaining valid CSV.
     */
    public static function escapeField(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            $value = $value === true ? '1' : ($value === false ? '0' : '');
        }
        $string = (string) $value;
        if ($string === '') {
            return '';
        }

        $first = $string[0];
        if (in_array($first, self::CHARACTERS_TO_ESCAPE, true)) {
            return "'" . $string;
        }
        return $string;
    }

    /** @param list<mixed> $fields */
    public static function formatRow(array $fields, string $delimiter = ','): string
    {
        $escaped = [];
        foreach ($fields as $field) {
            $value = self::escapeField($field);
            // Always quote: a value containing the delimiter, a quote or a
            // newline must not be able to forge extra columns.
            $escaped[] = '"' . str_replace('"', '""', $value) . '"';
        }
        return implode($delimiter, $escaped) . "\r\n";
    }

    /** Guesses the delimiter from the first non-empty line. */
    public static function detectDelimiter(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ',';
        }

        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
        $linesRead = 0;
        while ($linesRead < 5 && ($line = fgets($handle, 65536)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            foreach ($candidates as $character => $count) {
                $candidates[$character] = $count + substr_count($line, $character);
            }
            $linesRead++;
        }
        fclose($handle);

        arsort($candidates);
        $best = array_key_first($candidates);
        return $candidates[$best] > 0 ? (string) $best : ',';
    }

    /** Removes a UTF-8 BOM and normalises encoding of a header cell. */
    public static function cleanCell(string $value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252, ISO-8859-1');
            $value = is_string($converted) ? $converted : '';
        }
        // Strip control characters except tab; they are never meaningful data
        // and are a common vector for breaking downstream consumers.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return trim($value);
    }

    /**
     * @return list<string> Header labels from the first row.
     */
    public static function readHeader(string $path, string $delimiter = ','): array
    {
        $handle = self::open($path);
        $row = fgetcsv($handle, 0, $delimiter, '"', '');
        fclose($handle);

        if (!is_array($row)) {
            return [];
        }
        return array_map(static fn ($cell): string => self::cleanCell((string) $cell), $row);
    }

    /**
     * Reads up to $limit data rows for the import preview.
     *
     * @return list<list<string>>
     */
    public static function preview(string $path, string $delimiter = ',', bool $hasHeader = true, int $limit = 10): array
    {
        $handle = self::open($path);
        $rows = [];
        $index = 0;

        while (count($rows) < $limit && ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }
            if ($index === 0 && $hasHeader) {
                $index++;
                continue;
            }
            $rows[] = array_map(static fn ($cell): string => self::cleanCell((string) $cell), $row);
            $index++;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Counts data rows without loading the file into memory.
     * Quote-aware so an embedded newline does not inflate the count.
     */
    public static function countDataRows(string $path, string $delimiter = ',', bool $hasHeader = true): int
    {
        $handle = self::open($path);
        $count = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $count++;
        }
        fclose($handle);

        if ($hasHeader && $count > 0) {
            $count--;
        }
        return $count;
    }

    /**
     * Streams a bounded batch of rows starting at a byte offset.
     *
     * @param callable(list<string>, int):void $handler receives (row, rowNumber)
     * @return array{rows:int, offset:int, row_number:int, eof:bool}
     */
    public static function streamBatch(
        string $path,
        int $startOffset,
        int $startRowNumber,
        int $maxRows,
        string $delimiter,
        bool $hasHeader,
        callable $handler,
        ?float $deadline = null
    ): array {
        $handle = self::open($path);

        if ($startOffset > 0) {
            if (fseek($handle, $startOffset) !== 0) {
                fclose($handle);
                throw new RuntimeException('Unable to resume CSV at the recorded offset.');
            }
        } elseif ($hasHeader) {
            // Skip the header row exactly once, at the start of the file.
            fgetcsv($handle, 0, $delimiter, '"', '');
        }

        $rowNumber = $startRowNumber;
        $processed = 0;
        $eof = false;

        while ($processed < $maxRows) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }
            $row = fgetcsv($handle, 0, $delimiter, '"', '');
            if ($row === false) {
                $eof = true;
                break;
            }
            if ($row === [null]) {
                continue;
            }

            $rowNumber++;
            $processed++;
            $handler(array_map(static fn ($cell): string => self::cleanCell((string) $cell), $row), $rowNumber);
        }

        $offset = ftell($handle);
        fclose($handle);

        return [
            'rows'       => $processed,
            'offset'     => $offset === false ? $startOffset : $offset,
            'row_number' => $rowNumber,
            'eof'        => $eof,
        ];
    }

    /** @return resource */
    private static function open(string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file for reading.');
        }
        // Consume a UTF-8 BOM so the first header cell matches cleanly.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        return $handle;
    }
}
