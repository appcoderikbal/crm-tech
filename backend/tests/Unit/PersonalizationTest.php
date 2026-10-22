<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Support\Personalization;

final class PersonalizationTest extends TestCase
{
    public function testSubstitutesValues(): void
    {
        $result = Personalization::render(
            'Hello {{first_name}} at {{company_name}}',
            ['first_name' => 'Jo', 'company_name' => 'Acme']
        );

        self::assertSame('Hello Jo at Acme', $result);
    }

    public function testIsCaseAndWhitespaceInsensitive(): void
    {
        $result = Personalization::render(
            '{{ First_Name }} / {{FIRST_NAME}}',
            ['first_name' => 'Jo']
        );

        self::assertSame('Jo / Jo', $result);
    }

    public function testUsesFallbackAndReportsMissingVariables(): void
    {
        $result = Personalization::render(
            'Hi {{first_name}}, how is {{company_name}}?',
            ['first_name' => '  '],
            ['first_name' => 'there', 'company_name' => 'your company'],
            false,
            $missing
        );

        self::assertSame('Hi there, how is your company?', $result);
        self::assertSame(['first_name', 'company_name'], $missing);
    }

    public function testMissingVariableWithoutFallbackRendersEmpty(): void
    {
        $result = Personalization::render('Hi {{first_name}}!', [], [], false, $missing);

        self::assertSame('Hi !', $result);
        self::assertSame(['first_name'], $missing);
    }

    public function testHtmlContextEscapesContactData(): void
    {
        $result = Personalization::render(
            '<p>{{company_name}}</p>',
            ['company_name' => '<script>alert(1)</script>'],
            [],
            true
        );

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testHtmlContextEscapesAttributeBreakout(): void
    {
        $result = Personalization::render(
            '<a href="https://x.test" title="{{company_name}}">x</a>',
            ['company_name' => '" onmouseover="alert(1)'],
            [],
            true
        );

        self::assertStringNotContainsString('onmouseover="alert(1)"', $result);
        self::assertStringContainsString('&quot;', $result);
    }

    public function testTextContextStripsHeaderInjection(): void
    {
        $result = Personalization::render(
            'Hello {{first_name}}',
            ['first_name' => "Jo\r\nBcc: victim@example.com"],
            [],
            false
        );

        self::assertStringNotContainsString("\r", $result);
        self::assertStringNotContainsString("\n", $result);
        self::assertSame('Hello Jo Bcc: victim@example.com', $result);
    }

    public function testInternalRenderTokensAreNotTreatedAsVariables(): void
    {
        // Internal tokens use a URL form, not {{…}}, so they must be untouched
        // by personalization and must never appear as "variables".
        $template = 'See https://tracked-link.invalid/4/ and {{first_name}}';

        self::assertSame(['first_name'], Personalization::variablesIn($template));
        self::assertSame(
            'See https://tracked-link.invalid/4/ and Jo',
            Personalization::render($template, ['first_name' => 'Jo'])
        );
    }

    public function testVariablesInListsDistinctNames(): void
    {
        $found = Personalization::variablesIn('{{a}} {{b}} {{A}} {{c}}');

        self::assertSame(['a', 'b', 'c'], $found);
    }

    public function testValuesForContactIncludesDerivedAndCustomFields(): void
    {
        $values = Personalization::valuesForContact([
            'first_name'         => 'Jo',
            'last_name'          => 'Bloggs',
            'email'              => 'jo@acme.test',
            'company_name'       => 'Acme',
            'website'            => 'https://acme.test',
            'country'            => 'UK',
            'custom_fields_json' => '{"Plan":"Pro","seats":"12"}',
        ]);

        self::assertSame('Jo Bloggs', $values['full_name']);
        self::assertSame('Pro', $values['plan'], 'Custom field names are lower-cased.');
        self::assertSame('12', $values['seats']);
    }

    public function testStandardFieldsWinOverCustomFieldsOfTheSameName(): void
    {
        $values = Personalization::valuesForContact([
            'first_name'         => 'Real',
            'custom_fields_json' => '{"first_name":"Spoofed"}',
        ]);

        self::assertSame('Real', $values['first_name']);
    }
}
