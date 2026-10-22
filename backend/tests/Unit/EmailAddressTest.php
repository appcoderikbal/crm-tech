<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Support\EmailAddress;

final class EmailAddressTest extends TestCase
{
    #[DataProvider('normalizationCases')]
    public function testNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, EmailAddress::normalize($input));
    }

    public static function normalizationCases(): array
    {
        return [
            'lowercases'              => ['Person@Example.COM', 'person@example.com'],
            'trims whitespace'        => ["  person@example.com \n", 'person@example.com'],
            'strips display name'     => ['Jane Doe <Jane@Example.com>', 'jane@example.com'],
            'strips angle brackets'   => ['<jane@example.com>', 'jane@example.com'],
            'strips quotes'           => ['"jane@example.com"', 'jane@example.com'],
            'drops the root dot'      => ['jane@example.com.', 'jane@example.com'],
            'keeps plus addressing'   => ['jane+news@example.com', 'jane+news@example.com'],
            'keeps dots in the local' => ['ja.ne@gmail.com', 'ja.ne@gmail.com'],
        ];
    }

    #[DataProvider('validityCases')]
    public function testValidity(string $input, bool $valid): void
    {
        self::assertSame($valid, EmailAddress::isValid($input));
    }

    public static function validityCases(): array
    {
        return [
            'simple'              => ['person@example.com', true],
            'subdomain'           => ['person@mail.example.co.uk', true],
            'plus tag'            => ['person+tag@example.com', true],
            'no at sign'          => ['person.example.com', false],
            'no tld'              => ['person@localhost', false],
            'trailing dot domain' => ['person@example.', false],
            'empty'               => ['', false],
            'spaces'              => ['per son@example.com', false],
            'newline injection'   => ["person@example.com\nBcc: victim@example.com", false],
            'carriage return'     => ["person@example.com\r", false],
            'null byte'           => ["person@example.com\0", false],
        ];
    }

    public function testHeaderSafetyRejectsLineBreaks(): void
    {
        self::assertTrue(EmailAddress::isSafeForHeader('Techzapp Marketing'));
        self::assertFalse(EmailAddress::isSafeForHeader("Techzapp\r\nBcc: victim@example.com"));
        self::assertFalse(EmailAddress::isSafeForHeader("Techzapp\nX-Header: evil"));
    }

    public function testSanitizeHeaderValueCollapsesInjectedBreaks(): void
    {
        self::assertSame(
            'Techzapp Bcc: victim@example.com',
            EmailAddress::sanitizeHeaderValue("Techzapp\r\nBcc: victim@example.com")
        );
    }

    public function testFormatSenderQuotesAndEscapesDisplayName(): void
    {
        self::assertSame(
            '"Techzapp, Marketing" <news@techzapp.test>',
            EmailAddress::formatSender('Techzapp, Marketing', 'news@techzapp.test')
        );
        self::assertSame(
            '"He said \"hi\"" <news@techzapp.test>',
            EmailAddress::formatSender('He said "hi"', 'news@techzapp.test')
        );
        self::assertSame('news@techzapp.test', EmailAddress::formatSender('', 'news@techzapp.test'));
    }

    public function testFormatSenderStripsHeaderInjection(): void
    {
        $formatted = EmailAddress::formatSender("Evil\r\nBcc: victim@example.com", 'news@techzapp.test');

        self::assertStringNotContainsString("\r", $formatted);
        self::assertStringNotContainsString("\n", $formatted);
    }

    public function testDomainExtraction(): void
    {
        self::assertSame('example.com', EmailAddress::domain('Person@Example.com'));
        self::assertSame('mail.example.co.uk', EmailAddress::domain('p@mail.example.co.uk'));
        self::assertSame('', EmailAddress::domain('not-an-email'));
    }
}
