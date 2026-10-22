<?php

declare(strict_types=1);

namespace TechzappCrm\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use HTMLPurifier;
use HTMLPurifier_Config;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign HTML handling: sanitization, plain-text generation, click-tracking
 * rewrites and tracking-pixel injection.
 *
 * Sanitization uses HTMLPurifier (a maintained, whitelist-based sanitizer).
 * Anything it cannot prove safe is removed — scripts, event handlers,
 * javascript:/data: URLs, iframes, forms and embedded objects.
 */
final class EmailHtml
{
    /**
     * Placeholder href emitted for each tracked link, replaced per recipient.
     *
     * It has to be a syntactically valid URL: DOMDocument percent-encodes
     * anything that is not, which would mangle a brace-style token. The
     * .invalid TLD is reserved and never resolves, so an unsubstituted
     * placeholder is inert rather than a live link to somewhere unexpected.
     */
    public const LINK_TOKEN_PREFIX = 'https://tracked-link.invalid/';
    public const PIXEL_TOKEN = '{{!open_pixel}}';
    public const UNSUBSCRIBE_TOKEN = '{{!unsubscribe_url}}';

    private static ?HTMLPurifier $purifier = null;

    public static function sanitize(string $html, ?string $cacheDirectory = null): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Personalization placeholders are protected across purification: a
        // relative href like {{website}} is not a valid URI to the purifier.
        $protected = self::protectPlaceholders($html);
        $clean = self::purifier($cacheDirectory)->purify($protected);

        return self::restorePlaceholders($clean);
    }

    private static function purifier(?string $cacheDirectory): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', implode(',', [
            'p[style|align]', 'br', 'span[style]', 'div[style|align]',
            'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'small',
            'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]', 'h5[style]', 'h6[style]',
            'ul[style]', 'ol[style]', 'li[style]', 'blockquote[style]', 'pre', 'code', 'hr',
            'a[href|title|target|rel|style]',
            'img[src|alt|width|height|style|border]',
            'table[style|width|border|cellpadding|cellspacing|align]',
            'thead', 'tbody', 'tfoot',
            'tr[style|align|valign]',
            'td[style|align|valign|colspan|rowspan|width|height|bgcolor]',
            'th[style|align|valign|colspan|rowspan|width|height|bgcolor]',
        ]));
        $config->set('CSS.AllowedProperties', implode(',', [
            'color', 'background-color', 'background', 'font-size', 'font-family', 'font-weight',
            'font-style', 'text-align', 'text-decoration', 'line-height', 'letter-spacing',
            'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
            'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
            'border', 'border-top', 'border-bottom', 'border-left', 'border-right',
            'border-collapse', 'width', 'max-width', 'height',
            'vertical-align', 'white-space',
        ]));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', false);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('Core.RemoveInvalidImg', true);

        // HTMLPurifier wants a writable cache directory. Shared hosting may not
        // offer one; fall back to no definition cache rather than failing.
        $cacheDirectory ??= \TechzappCrm\Core\Config::storagePath('tmp/htmlpurifier');
        if (!is_dir($cacheDirectory)) {
            wp_mkdir_p($cacheDirectory);
        }
        if (is_dir($cacheDirectory) && is_writable($cacheDirectory)) {
            $config->set('Cache.SerializerPath', $cacheDirectory);
            $config->set('Cache.SerializerPermissions', 0750);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        self::$purifier = new HTMLPurifier($config);
        return self::$purifier;
    }

    /** Resets the cached purifier. Tests only. */
    public static function resetPurifier(): void
    {
        self::$purifier = null;
    }

    private static function protectPlaceholders(string $html): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]{1,40})\s*\}\}/i',
            static fn (array $m): string => 'PH-' . strtolower($m[1]) . '-PH',
            $html
        ) ?? $html;
    }

    private static function restorePlaceholders(string $html): string
    {
        return preg_replace_callback(
            '/PH-([a-z0-9_]{1,40})-PH/i',
            static fn (array $m): string => '{{' . strtolower($m[1]) . '}}',
            $html
        ) ?? $html;
    }

    /**
     * Replaces trackable hrefs with per-link placeholder tokens.
     *
     * Links that contain a personalization placeholder, mailto:, anchors and the
     * unsubscribe token are left untouched — their destination is not a fixed,
     * pre-approved URL and therefore cannot be registered for click tracking.
     *
     * @param callable(string):int $registerLink receives the URL, returns a link id
     * @return array{html:string, links:array<int,string>}
     */
    public static function rewriteLinksForTracking(string $html, callable $registerLink): array
    {
        if (trim($html) === '') {
            return ['html' => $html, 'links' => []];
        }

        $document = self::loadDocument($html);
        if ($document === null) {
            return ['html' => $html, 'links' => []];
        }

        $links = [];
        $xpath = new DOMXPath($document);
        /** @var iterable<DOMElement> $anchors */
        $anchors = $xpath->query('//a[@href]') ?: [];

        foreach ($anchors as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if (!self::isTrackableUrl($href)) {
                continue;
            }
            $linkId = $registerLink($href);
            $links[$linkId] = $href;
            $anchor->setAttribute('href', self::linkToken($linkId));
        }

        return ['html' => self::saveDocument($document), 'links' => $links];
    }

    /** The placeholder href for a registered link id. */
    public static function linkToken(int $linkId): string
    {
        return self::LINK_TOKEN_PREFIX . $linkId . '/';
    }

    public static function isTrackableUrl(string $url): bool
    {
        if ($url === '' || str_starts_with($url, '#')) {
            return false;
        }
        if (str_starts_with($url, self::LINK_TOKEN_PREFIX)) {
            return false; // already rewritten
        }
        if (str_contains($url, '{{')) {
            return false; // personalized or already a tracking placeholder
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }

    /** Validates a redirect destination before the tracking endpoint uses it. */
    public static function isSafeRedirectTarget(string $url): bool
    {
        if (str_starts_with($url, self::LINK_TOKEN_PREFIX)) {
            return false;
        }
        if ($url === '' || strlen($url) > 2000) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        // A host containing credentials or whitespace is a phishing/bypass shape.
        return !isset($parts['user'], $parts['pass']) && preg_match('/^[a-z0-9.\-\[\]:]+$/i', $parts['host']) === 1;
    }

    /** Appends the open-tracking pixel placeholder to the document body. */
    public static function appendPixelToken(string $html): string
    {
        $pixel = '<img src="' . self::PIXEL_TOKEN . '" width="1" height="1" alt="" '
            . 'style="display:block;width:1px;height:1px;border:0;" />';

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('#</body>#i', $pixel . '</body>', $html, 1);
        }
        return $html . $pixel;
    }

    /**
     * Ensures a visible unsubscribe link exists. If the author already placed
     * one (via the {{!unsubscribe_url}} token), nothing is appended.
     */
    public static function appendUnsubscribeFooter(string $html, string $postalAddress = ''): string
    {
        if (str_contains($html, self::UNSUBSCRIBE_TOKEN)) {
            return $html;
        }

        $address = $postalAddress === ''
            ? ''
            : '<div style="margin-bottom:8px;">' . htmlspecialchars($postalAddress, ENT_QUOTES, 'UTF-8') . '</div>';

        $footer = '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b7280;text-align:center;">'
            . $address
            . '<div>You are receiving this email because you opted in or did business with us.</div>'
            . '<div style="margin-top:6px;"><a href="' . self::UNSUBSCRIBE_TOKEN
            . '" style="color:#6b7280;text-decoration:underline;">Unsubscribe from these emails</a></div>'
            . '</div>';

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('#</body>#i', $footer . '</body>', $html, 1);
        }
        return $html . $footer;
    }

    /** Appends the unsubscribe URL token to a plain-text body. */
    public static function appendUnsubscribeFooterText(string $text, string $postalAddress = ''): string
    {
        if (str_contains($text, self::UNSUBSCRIBE_TOKEN)) {
            return $text;
        }
        $lines = [rtrim($text), '', str_repeat('-', 40)];
        if ($postalAddress !== '') {
            $lines[] = $postalAddress;
        }
        $lines[] = 'Unsubscribe: ' . self::UNSUBSCRIBE_TOKEN;
        return implode("\n", $lines) . "\n";
    }

    /**
     * Produces a readable plain-text alternative from HTML.
     * Link destinations are kept inline so the text part is not link-less.
     */
    public static function toPlainText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $text = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // Keep anchor destinations visible, unless they are tracking tokens.
        $text = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $label = trim(strip_tags($m[2]));
                $href = trim($m[1]);
                if ($href === '' || $label === '') {
                    return $label !== '' ? $label : $href;
                }
                if (str_starts_with($href, self::LINK_TOKEN_PREFIX) || str_contains($href, '{{')) {
                    return $label . ' ( ' . $href . ' )';
                }
                return str_contains($label, $href) ? $label : $label . ' ( ' . $href . ' )';
            },
            $text
        ) ?? $text;

        $text = preg_replace('#<li\b[^>]*>#i', "\n  - ", $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|tr|h[1-6]|ul|ol|blockquote|table)>#i', "\n\n", $text) ?? $text;
        $text = preg_replace('#<hr\s*/?>#i', "\n" . str_repeat('-', 40) . "\n", $text) ?? $text;

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function loadDocument(string $html): ?DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $wrapped = '<?xml encoding="UTF-8" ?><!DOCTYPE html><html><body>' . $html . '</body></html>';
            $loaded = $document->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            return $loaded ? $document : null;
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function saveDocument(DOMDocument $document): string
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return (string) $document->saveHTML();
        }

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= (string) $document->saveHTML($child);
        }
        return $html;
    }
}
