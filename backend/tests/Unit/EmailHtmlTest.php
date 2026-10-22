<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Support\EmailHtml;

final class EmailHtmlTest extends TestCase
{
    // ------------------------------------------------------- sanitization

    #[DataProvider('dangerousMarkup')]
    public function testDangerousMarkupIsRemoved(string $html, string $mustNotContain): void
    {
        $clean = EmailHtml::sanitize($html);

        self::assertStringNotContainsStringIgnoringCase($mustNotContain, $clean);
    }

    public static function dangerousMarkup(): array
    {
        return [
            'script tag'      => ['<p>hi</p><script>alert(1)</script>', '<script'],
            'inline handler'  => ['<p onclick="alert(1)">hi</p>', 'onclick'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
            'data uri href'   => ['<a href="data:text/html;base64,PHA+">x</a>', 'data:'],
            'iframe'          => ['<iframe src="https://evil.test"></iframe>', '<iframe'],
            'form'            => ['<form action="https://evil.test"><input name="pw"></form>', '<form'],
            'object'          => ['<object data="evil.swf"></object>', '<object'],
            'style tag'       => ['<style>body{display:none}</style><p>x</p>', '<style'],
            'svg onload'      => ['<svg onload="alert(1)"></svg>', 'onload'],
            'meta refresh'    => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', 'http-equiv'],
        ];
    }

    public function testSafeFormattingSurvives(): void
    {
        $clean = EmailHtml::sanitize(
            '<p style="color:#333"><strong>Bold</strong> and <em>italic</em></p>'
            . '<ul><li>one</li></ul>'
            . '<a href="https://techzapp.test/x" title="go">link</a>'
        );

        self::assertStringContainsString('<strong>Bold</strong>', $clean);
        self::assertStringContainsString('<em>italic</em>', $clean);
        self::assertStringContainsString('<li>one</li>', $clean);
        self::assertStringContainsString('https://techzapp.test/x', $clean);
        self::assertStringContainsString('color:#333', $clean);
    }

    public function testPersonalizationPlaceholdersSurviveSanitization(): void
    {
        $clean = EmailHtml::sanitize('<p>Hello {{first_name}} from {{ company_name }}</p>');

        self::assertStringContainsString('{{first_name}}', $clean);
        self::assertStringContainsString('{{company_name}}', $clean);
    }

    // ------------------------------------------------------ link tracking

    public function testLinksAreRewrittenToPlaceholderTokens(): void
    {
        $registered = [];
        $result = EmailHtml::rewriteLinksForTracking(
            '<p><a href="https://techzapp.test/a">A</a> and <a href="https://techzapp.test/b">B</a></p>',
            static function (string $url) use (&$registered): int {
                $registered[] = $url;
                return count($registered);
            }
        );

        self::assertSame(['https://techzapp.test/a', 'https://techzapp.test/b'], $registered);
        self::assertStringContainsString(EmailHtml::linkToken(1), $result['html']);
        self::assertStringContainsString(EmailHtml::linkToken(2), $result['html']);
        self::assertStringNotContainsString('techzapp.test/a', $result['html']);
    }

    public function testPlaceholderTokenSurvivesDomSerialisation(): void
    {
        // Regression: a brace-style token was percent-encoded by DOMDocument,
        // which silently disabled click tracking.
        $result = EmailHtml::rewriteLinksForTracking(
            '<a href="https://techzapp.test/a">A</a>',
            static fn (string $url): int => 7
        );

        self::assertStringContainsString('href="https://tracked-link.invalid/7/"', $result['html']);
        self::assertStringNotContainsString('%7B', $result['html']);
    }

    public function testUntrackableLinksAreLeftAlone(): void
    {
        $called = 0;
        $html = '<a href="mailto:hi@techzapp.test">mail</a>'
            . '<a href="#anchor">anchor</a>'
            . '<a href="https://techzapp.test/{{first_name}}">personalised</a>';

        $result = EmailHtml::rewriteLinksForTracking($html, static function () use (&$called): int {
            $called++;
            return $called;
        });

        self::assertSame(0, $called);
        self::assertStringContainsString('mailto:hi@techzapp.test', $result['html']);
        self::assertStringContainsString('#anchor', $result['html']);
    }

    // ------------------------------------------------------ open redirect

    #[DataProvider('unsafeRedirectTargets')]
    public function testUnsafeRedirectTargetsAreRejected(string $url): void
    {
        self::assertFalse(EmailHtml::isSafeRedirectTarget($url));
    }

    public static function unsafeRedirectTargets(): array
    {
        return [
            'javascript'       => ['javascript:alert(1)'],
            'data'             => ['data:text/html,<script>alert(1)</script>'],
            'file'             => ['file:///etc/passwd'],
            'protocol relative'=> ['//evil.test/path'],
            'relative'         => ['/dashboard'],
            'empty'            => [''],
            'credentials'      => ['https://user:pass@evil.test/'],
            'newline'          => ["https://evil.test/\r\nSet-Cookie: x=1"],
            'placeholder'      => ['https://tracked-link.invalid/3/'],
        ];
    }

    public function testSafeRedirectTargetsAreAccepted(): void
    {
        self::assertTrue(EmailHtml::isSafeRedirectTarget('https://techzapp.test/offer?a=1&b=2'));
        self::assertTrue(EmailHtml::isSafeRedirectTarget('http://techzapp.test'));
        self::assertTrue(EmailHtml::isSafeRedirectTarget('https://sub.domain.techzapp.test/path#fragment'));
    }

    // -------------------------------------------------------- plain text

    public function testPlainTextConversionKeepsStructureAndUrls(): void
    {
        $text = EmailHtml::toPlainText(
            '<h1>Title</h1><p>Hello <strong>there</strong>.</p>'
            . '<ul><li>one</li><li>two</li></ul>'
            . '<p><a href="https://techzapp.test/x">Read more</a></p>'
        );

        self::assertStringContainsString('Title', $text);
        self::assertStringContainsString('Hello there.', $text);
        self::assertStringContainsString('- one', $text);
        self::assertStringContainsString('Read more ( https://techzapp.test/x )', $text);
        self::assertStringNotContainsString('<', $text);
    }

    public function testPlainTextDecodesEntities(): void
    {
        self::assertSame('Ben & Jerry\'s "best"', EmailHtml::toPlainText('<p>Ben &amp; Jerry&#39;s &quot;best&quot;</p>'));
    }

    // ----------------------------------------------------------- footers

    public function testUnsubscribeFooterIsAppendedOnce(): void
    {
        $once = EmailHtml::appendUnsubscribeFooter('<p>Body</p>', '1 Example Street');
        $twice = EmailHtml::appendUnsubscribeFooter($once, '1 Example Street');

        self::assertSame(1, substr_count($twice, EmailHtml::UNSUBSCRIBE_TOKEN));
        self::assertStringContainsString('1 Example Street', $once);
    }

    public function testPixelTokenGoesInsideBodyWhenPresent(): void
    {
        $html = EmailHtml::appendPixelToken('<html><body><p>Hi</p></body></html>');

        self::assertStringContainsString(EmailHtml::PIXEL_TOKEN, $html);
        self::assertLessThan(strpos($html, '</body>'), strpos($html, EmailHtml::PIXEL_TOKEN));
    }
}
