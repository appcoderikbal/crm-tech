<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Support\Tokens;

final class TokensTest extends TestCase
{
    public function testTrackingTokensAreUnguessableAndUnique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 200; $i++) {
            $token = Tokens::tracking();
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
            $tokens[$token] = true;
        }

        self::assertCount(200, $tokens, 'Tracking tokens must not repeat.');
    }

    public function testContactTokensAreUrlSafe(): void
    {
        $token = Tokens::contact();

        self::assertSame(43, strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    public function testSignatureIsStableForTheSameInput(): void
    {
        self::assertSame(
            Tokens::sign('open', 'abc123'),
            Tokens::sign('open', 'abc123')
        );
    }

    public function testSignatureIsBoundToPurpose(): void
    {
        // A signature issued for the open pixel must not unlock the click
        // endpoint for the same recipient.
        self::assertNotSame(Tokens::sign('open', 'abc123'), Tokens::sign('click', 'abc123'));
        self::assertFalse(Tokens::verify(Tokens::sign('open', 'abc123'), 'click', 'abc123'));
    }

    public function testSignatureIsBoundToEveryParameter(): void
    {
        $signature = Tokens::sign('click', 'token', '7');

        self::assertTrue(Tokens::verify($signature, 'click', 'token', '7'));
        self::assertFalse(Tokens::verify($signature, 'click', 'token', '8'), 'Link id must be covered.');
        self::assertFalse(Tokens::verify($signature, 'click', 'other', '7'), 'Recipient must be covered.');
    }

    public function testParameterBoundariesCannotBeShifted(): void
    {
        // Without a separator, ("ab","c") and ("a","bc") would sign identically.
        self::assertNotSame(Tokens::sign('click', 'ab', 'c'), Tokens::sign('click', 'a', 'bc'));
    }

    public function testEmptySignatureIsRejected(): void
    {
        self::assertFalse(Tokens::verify('', 'open', 'abc123'));
    }

    public function testBase64UrlRoundTrip(): void
    {
        $raw = random_bytes(33);
        $encoded = Tokens::base64UrlEncode($raw);

        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
        self::assertSame($raw, Tokens::base64UrlDecode($encoded));
    }
}
