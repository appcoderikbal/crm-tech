<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services;

use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Support\EmailHtml;
use Techzapp\Crm\Support\Tokens;

/**
 * Public tracking endpoints: open pixel, click redirect and unsubscribe links.
 *
 * Every URL carries an opaque recipient token plus an HMAC bound to the purpose
 * and the parameters. A link therefore cannot be edited into another
 * recipient's link, and the click endpoint never accepts a destination from the
 * URL — only a link id that was registered when the campaign was built.
 */
final class TrackingService
{
    public const PURPOSE_OPEN = 'open';
    public const PURPOSE_CLICK = 'click';
    public const PURPOSE_UNSUBSCRIBE = 'unsub';

    public static function baseUrl(): string
    {
        $configured = Settings::string('tracking_base_url');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        return Config::load()->appUrl();
    }

    public static function openPixelUrl(string $recipientToken): string
    {
        $signature = Tokens::sign(self::PURPOSE_OPEN, $recipientToken);
        return self::baseUrl() . '/t/o.php?r=' . urlencode($recipientToken) . '&s=' . urlencode($signature);
    }

    public static function clickUrl(string $recipientToken, int $linkId): string
    {
        $signature = Tokens::sign(self::PURPOSE_CLICK, $recipientToken, (string) $linkId);
        return self::baseUrl() . '/t/c.php?r=' . urlencode($recipientToken)
            . '&l=' . $linkId . '&s=' . urlencode($signature);
    }

    /** Campaign-scoped unsubscribe link (preferred: records which campaign). */
    public static function unsubscribeUrl(string $recipientToken): string
    {
        $signature = Tokens::sign(self::PURPOSE_UNSUBSCRIBE, $recipientToken);
        return self::baseUrl() . '/u/?r=' . urlencode($recipientToken) . '&s=' . urlencode($signature);
    }

    /** Contact-scoped unsubscribe link, for messages outside a campaign. */
    public static function contactUnsubscribeUrl(string $contactToken): string
    {
        $signature = Tokens::sign(self::PURPOSE_UNSUBSCRIBE, 'c:' . $contactToken);
        return self::baseUrl() . '/u/?c=' . urlencode($contactToken) . '&s=' . urlencode($signature);
    }

    /**
     * Resolves a recipient token after verifying its signature.
     *
     * @return array<string,mixed>|null
     */
    public static function resolveRecipient(string $token, string $signature, string $purpose, string ...$extra): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        if (!Tokens::verify($signature, $purpose, $token, ...$extra)) {
            return null;
        }

        return Database::first(
            'SELECT r.id, r.campaign_id, r.contact_id, r.recipient_email_snapshot, r.status,
                    r.first_opened_at, r.first_clicked_at, r.unsubscribed_at,
                    c.track_opens, c.track_clicks, c.name AS campaign_name
               FROM campaign_recipients r
               JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.tracking_token = :token',
            ['token' => $token]
        );
    }

    /**
     * Records an open.
     *
     * Repeated pixel loads within the same minute collapse into one row via the
     * dedupe key, so a mail client that re-renders a message a dozen times does
     * not inflate "total opens". Unique opens are counted as distinct
     * recipients regardless.
     */
    public static function recordOpen(array $recipient, string $userAgent = '', string $ip = ''): void
    {
        $recipientId = (int) $recipient['id'];
        $bucket = gmdate('Y-m-d H:i');
        $dedupe = hash('sha256', "open|{$recipientId}|{$bucket}");

        $inserted = EventService::record(
            campaignId: (int) $recipient['campaign_id'],
            recipientId: $recipientId,
            contactId: $recipient['contact_id'] === null ? null : (int) $recipient['contact_id'],
            eventType: 'open',
            dedupeKey: $dedupe,
            providerEventId: null,
            timestamp: Database::nowUtc(),
            metadata: [
                'user_agent' => substr($userAgent, 0, 255),
                'ip_prefix'  => self::anonymiseIp($ip),
                'source'     => 'pixel',
            ]
        );

        if ($inserted) {
            Database::execute(
                'UPDATE campaign_recipients
                    SET first_opened_at = COALESCE(first_opened_at, UTC_TIMESTAMP())
                  WHERE id = :id',
                ['id' => $recipientId]
            );
        }
    }

    /** @return string|null the validated destination URL, or null if unusable */
    public static function recordClick(array $recipient, int $linkId, string $userAgent = '', string $ip = ''): ?string
    {
        $url = Database::scalar(
            'SELECT url FROM campaign_links WHERE id = :id AND campaign_id = :campaign',
            ['id' => $linkId, 'campaign' => (int) $recipient['campaign_id']]
        );

        if (!is_string($url) || !EmailHtml::isSafeRedirectTarget($url)) {
            return null;
        }

        $recipientId = (int) $recipient['id'];
        $bucket = gmdate('Y-m-d H:i');
        $dedupe = hash('sha256', "click|{$recipientId}|{$linkId}|{$bucket}");

        $inserted = EventService::record(
            campaignId: (int) $recipient['campaign_id'],
            recipientId: $recipientId,
            contactId: $recipient['contact_id'] === null ? null : (int) $recipient['contact_id'],
            eventType: 'click',
            dedupeKey: $dedupe,
            providerEventId: null,
            timestamp: Database::nowUtc(),
            metadata: [
                'link_id'    => $linkId,
                'url'        => substr($url, 0, 500),
                'user_agent' => substr($userAgent, 0, 255),
                'ip_prefix'  => self::anonymiseIp($ip),
                'source'     => 'redirect',
            ]
        );

        if ($inserted) {
            Database::execute(
                'UPDATE campaign_recipients
                    SET first_clicked_at = COALESCE(first_clicked_at, UTC_TIMESTAMP()),
                        first_opened_at  = COALESCE(first_opened_at, UTC_TIMESTAMP())
                  WHERE id = :id',
                ['id' => $recipientId]
            );
        }

        return $url;
    }

    /**
     * Simple per-IP rate limit for the public endpoints.
     * Returns false when the caller has exceeded the window budget.
     */
    public static function allowPublicRequest(string $scope, string $ip): bool
    {
        $limit = Config::load()->array('security.public_rate_limit', ['hits' => 120, 'window' => 60]);
        $maxHits = (int) ($limit['hits'] ?? 120);
        $window = max(1, (int) ($limit['window'] ?? 60));

        $windowStart = gmdate('Y-m-d H:i:s', (int) (floor(time() / $window) * $window));
        $bucket = substr($scope . '|' . ($ip === '' ? 'unknown' : $ip), 0, 190);

        Database::execute(
            'INSERT INTO rate_limit_counters (bucket_key, window_start, hits)
             VALUES (:bucket, :start, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['bucket' => $bucket, 'start' => $windowStart]
        );

        $hits = (int) Database::scalar(
            'SELECT hits FROM rate_limit_counters WHERE bucket_key = :bucket AND window_start = :start',
            ['bucket' => $bucket, 'start' => $windowStart]
        );

        return $hits <= $maxHits;
    }

    /** Keeps only the network portion of an address for abuse diagnostics. */
    public static function anonymiseIp(string $ip): string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . '::';
        }
        $parts = explode('.', $ip);
        return $parts[0] . '.' . ($parts[1] ?? '0') . '.' . ($parts[2] ?? '0') . '.0';
    }

    /** 1x1 transparent GIF used by the open pixel endpoint. */
    public static function pixelBytes(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true) ?: '';
    }
}
