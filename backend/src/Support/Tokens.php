<?php

declare(strict_types=1);

namespace Techzapp\Crm\Support;

use Techzapp\Crm\Core\Config;

/**
 * Random identifiers and HMAC signatures for public (unauthenticated) URLs.
 *
 * Tracking and unsubscribe links never contain a database id: they carry an
 * opaque random token plus a signature bound to the purpose, so a URL cannot be
 * mutated into a different recipient's link or enumerated.
 */
final class Tokens
{
    /** 32 hex characters — used for campaign_recipients.tracking_token. */
    public static function tracking(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 43 base64url characters — used for contacts.unsubscribe_token. */
    public static function contact(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    public static function random(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Signature over a purpose-scoped payload, truncated for URL friendliness. */
    public static function sign(string $purpose, string ...$parts): string
    {
        $message = $purpose . "\0" . implode("\0", $parts);
        $digest = hash_hmac('sha256', $message, self::key(), true);
        return substr(self::base64UrlEncode($digest), 0, 27);
    }

    public static function verify(string $signature, string $purpose, string ...$parts): bool
    {
        if ($signature === '') {
            return false;
        }
        return hash_equals(self::sign($purpose, ...$parts), $signature);
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }

    /** Constant-time comparison helper for opaque tokens. */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    private static function key(): string
    {
        return Config::load()->secretKey();
    }
}
