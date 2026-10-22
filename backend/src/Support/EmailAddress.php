<?php

declare(strict_types=1);

namespace Techzapp\Crm\Support;

/**
 * Email normalization and validation.
 *
 * Normalization is deliberately conservative: trim, strip display name/angle
 * brackets, lowercase. We do NOT strip Gmail dots or +tags — two addresses that
 * differ there are different records as far as the recipient's provider is
 * concerned, and collapsing them would merge contacts that are not the same
 * person. The normalized form is what the UNIQUE index and all suppression
 * checks use.
 */
final class EmailAddress
{
    public const MAX_LENGTH = 254;

    public static function normalize(string $email): string
    {
        $email = trim($email);
        $email = trim($email, "\"' \t\n\r\0\x0B");

        // "Display Name <user@example.com>" -> "user@example.com"
        if (preg_match('/<([^>]+)>\s*$/', $email, $matches) === 1) {
            $email = trim($matches[1]);
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return mb_strtolower($email, 'UTF-8');
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        // The local part is case-sensitive per RFC 5321, but in practice every
        // mailbox provider treats it case-insensitively; lowercasing both halves
        // is what makes duplicate detection work on real-world imports.
        return mb_strtolower($local, 'UTF-8') . '@' . mb_strtolower(rtrim($domain, '.'), 'UTF-8');
    }

    public static function isValid(string $email): bool
    {
        // Header-injection guard. This runs on the raw value: trim() strips a
        // trailing NUL or CR, which would let an injection payload validate.
        if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1) {
            return false;
        }

        $email = trim($email, " \t");

        if ($email === '' || strlen($email) > self::MAX_LENGTH) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $at = strrpos($email, '@');
        $domain = substr($email, $at + 1);

        // Require a dotted domain with a plausible TLD; SES rejects the rest anyway.
        return preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,63}$/i', $domain) === 1;
    }

    /** Validates a value destined for a mail header (From/Reply-To). */
    public static function isSafeForHeader(string $value): bool
    {
        return preg_match('/[\r\n\x00]/', $value) !== 1;
    }

    /** Strips anything that could break out of a header line. */
    public static function sanitizeHeaderValue(string $value): string
    {
        $value = preg_replace('/[\r\n\x00]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    public static function domain(string $email): string
    {
        $normalized = self::normalize($email);
        $at = strrpos($normalized, '@');
        return $at === false ? '' : substr($normalized, $at + 1);
    }

    /** Formats "Name <email>" with the display name quoted and sanitized. */
    public static function formatSender(string $name, string $email): string
    {
        $email = self::sanitizeHeaderValue($email);
        $name = self::sanitizeHeaderValue($name);
        if ($name === '') {
            return $email;
        }
        // Quote and escape so a comma or quote in the name cannot add a recipient.
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
        return '"' . $escaped . '" <' . $email . '>';
    }
}
