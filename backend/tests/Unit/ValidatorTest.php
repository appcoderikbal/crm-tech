<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Validator;

final class ValidatorTest extends TestCase
{
    public function testRequiredFieldsAreEnforced(): void
    {
        $validator = Validator::make([]);
        $validator->string('name', true);

        self::assertTrue($validator->fails());
        self::assertSame(['name' => 'This field is required.'], $validator->errors());
    }

    public function testBlankStringCountsAsMissing(): void
    {
        $validator = Validator::make(['name' => '   ']);
        $validator->string('name', true);

        self::assertTrue($validator->fails());
    }

    public function testLengthBoundsAreEnforced(): void
    {
        $validator = Validator::make(['name' => str_repeat('a', 300)]);
        $validator->string('name', true, 190);

        self::assertArrayHasKey('name', $validator->errors());
    }

    public function testControlCharactersAreStripped(): void
    {
        $validator = Validator::make(['name' => "Acme\x00\x07 Ltd"]);

        self::assertSame('Acme Ltd', $validator->string('name'));
    }

    public function testIntegerBounds(): void
    {
        $validator = Validator::make(['rate' => '500']);
        self::assertSame(500, $validator->int('rate', true, 1, 1000));

        $tooHigh = Validator::make(['rate' => '5000']);
        $tooHigh->int('rate', true, 1, 1000);
        self::assertTrue($tooHigh->fails());

        $notNumeric = Validator::make(['rate' => '12abc']);
        $notNumeric->int('rate', true);
        self::assertTrue($notNumeric->fails());
    }

    public function testEnumRejectsUnknownValues(): void
    {
        $validator = Validator::make(['mode' => 'delete_everything']);
        $validator->enum('mode', ['skip', 'update'], true);

        self::assertTrue($validator->fails());
    }

    public function testIntListRejectsNonNumericIdentifiers(): void
    {
        $validator = Validator::make(['ids' => [1, 2, 'DROP TABLE contacts']]);
        $validator->intList('ids', true);

        self::assertTrue($validator->fails());
    }

    public function testIntListDeduplicates(): void
    {
        $validator = Validator::make(['ids' => [3, 3, 4]]);

        self::assertSame([3, 4], $validator->intList('ids', true));
    }

    public function testIntListEnforcesMaximumSize(): void
    {
        $validator = Validator::make(['ids' => range(1, 50)]);
        $validator->intList('ids', true, 10);

        self::assertTrue($validator->fails());
    }

    public function testUrlRejectsExecutableSchemes(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', 'vbscript:msgbox', 'file:///etc/passwd'] as $url) {
            $validator = Validator::make(['website' => $url]);
            $validator->url('website');

            self::assertTrue($validator->fails(), "Expected {$url} to be rejected.");
        }
    }

    public function testUrlAcceptsBareDomains(): void
    {
        $validator = Validator::make(['website' => 'acme.test']);

        self::assertSame('acme.test', $validator->url('website'));
        self::assertFalse($validator->fails());
    }

    public function testTimezoneValidation(): void
    {
        $validator = Validator::make(['timezone' => 'Europe/London']);
        self::assertSame('Europe/London', $validator->timezone('timezone'));

        $invalid = Validator::make(['timezone' => 'Mars/Olympus']);
        $invalid->timezone('timezone');
        self::assertTrue($invalid->fails());
    }

    public function testLocalDateTimeIsConvertedToUtc(): void
    {
        $validator = Validator::make(['scheduled_at' => '2026-07-01 09:30:00']);

        // Europe/London is UTC+1 in July.
        self::assertSame('2026-07-01 08:30:00', $validator->dateTimeToUtc('scheduled_at', 'Europe/London', true));
    }

    public function testInvalidDateIsRejected(): void
    {
        $validator = Validator::make(['scheduled_at' => 'not a date']);
        $validator->dateTimeToUtc('scheduled_at', 'UTC', true);

        self::assertTrue($validator->fails());
    }

    public function testJsonObjectRejectsUnsafeKeys(): void
    {
        $validator = Validator::make(['custom_fields' => ['bad key!' => 'x']]);
        $validator->jsonObject('custom_fields');

        self::assertTrue($validator->fails());
    }

    public function testJsonObjectNormalisesKeysToLowerCase(): void
    {
        $validator = Validator::make(['custom_fields' => ['Plan' => 'Pro']]);

        self::assertSame(['plan' => 'Pro'], $validator->jsonObject('custom_fields'));
    }

    public function testValidateThrowsApiExceptionWithFieldDetails(): void
    {
        $validator = Validator::make(['email' => 'nope']);
        $validator->email('email', true);

        try {
            $validator->validate();
            self::fail('Expected a validation exception.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->statusCode());
            self::assertSame('validation_failed', $e->errorCode());
            self::assertArrayHasKey('email', $e->details()['fields']);
        }
    }
}
