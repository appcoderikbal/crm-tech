<?php

declare(strict_types=1);

namespace TechzappCrm\Services;

use TechzappCrm\Core\Database;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\Mail\Message;
use TechzappCrm\Support\EmailHtml;
use TechzappCrm\Support\Personalization;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a campaign into a per-recipient message.
 *
 * The expensive work (sanitizing, parsing the DOM, registering tracked links)
 * happens once per campaign in buildTemplate(). Per recipient we only do string
 * substitution, which is what makes a 40,000-recipient send affordable on
 * shared hosting.
 */
final class CampaignRenderer
{
    /**
     * @param array<string,mixed> $campaign
     * @return array{subject:string, preview_text:string, html:string, text:string, links:array<int,string>}
     */
    public static function buildTemplate(array $campaign, bool $persistLinks = true): array
    {
        $campaignId = (int) $campaign['id'];
        $html = (string) ($campaign['html_body'] ?? '');
        $text = trim((string) ($campaign['text_body'] ?? ''));

        // Plain text is derived before link rewriting so the text part carries
        // real destinations rather than tracking redirects.
        if ($text === '') {
            $text = EmailHtml::toPlainText($html);
        }

        $links = [];
        if (!empty($campaign['track_clicks']) && $html !== '') {
            $result = EmailHtml::rewriteLinksForTracking(
                $html,
                static function (string $url) use ($campaignId, $persistLinks, &$links): int {
                    $id = $persistLinks
                        ? self::registerLink($campaignId, $url)
                        : -(count($links) + 1); // preview mode: synthetic ids
                    $links[$id] = $url;
                    return $id;
                }
            );
            $html = $result['html'];
        }

        $postalAddress = Settings::string('company_postal_address');
        $html = EmailHtml::appendUnsubscribeFooter($html, $postalAddress);
        $text = EmailHtml::appendUnsubscribeFooterText($text, $postalAddress);

        $preview = trim((string) ($campaign['preview_text'] ?? ''));
        if ($preview !== '') {
            $html = self::injectPreheader($html, $preview);
        }

        if (!empty($campaign['track_opens'])) {
            $html = EmailHtml::appendPixelToken($html);
        }

        return [
            'subject'      => (string) $campaign['subject'],
            'preview_text' => $preview,
            'html'         => $html,
            'text'         => $text,
            'links'        => $links,
        ];
    }

    /** Idempotent: the same URL in the same campaign always maps to one id. */
    public static function registerLink(int $campaignId, string $url): int
    {
        $hash = hash('sha256', $url);

        $existing = Database::scalar(
            'SELECT id FROM {campaign_links} WHERE campaign_id = :campaign AND url_hash = :hash',
            ['campaign' => $campaignId, 'hash' => $hash]
        );
        if ($existing !== null) {
            return (int) $existing;
        }

        Database::execute(
            'INSERT INTO {campaign_links} (campaign_id, url_hash, url, created_at)
             VALUES (:campaign, :hash, :url, :created_at)
             ON DUPLICATE KEY UPDATE url = VALUES(url)',
            ['campaign' => $campaignId, 'hash' => $hash, 'url' => $url, 'created_at' => Database::nowUtc()]
        );

        $id = Database::lastInsertId();
        if ($id > 0) {
            return $id;
        }

        return (int) Database::scalar(
            'SELECT id FROM {campaign_links} WHERE campaign_id = :campaign AND url_hash = :hash',
            ['campaign' => $campaignId, 'hash' => $hash]
        );
    }

    /**
     * Renders one recipient's message.
     *
     * Order matters: tracking tokens are substituted first, then contact data.
     * Doing it the other way round would let an imported field that happens to
     * contain a placeholder token turn into a live tracking URL.
     *
     * @param array{subject:string,preview_text:string,html:string,text:string,links:array<int,string>} $template
     * @param array<string,mixed> $recipient  campaign_recipients row (tracking_token, …)
     * @param array<string,mixed> $contact    contact columns for personalization
     * @return array{message:Message, missing:list<string>}
     */
    public static function renderMessage(array $campaign, array $template, array $recipient, array $contact): array
    {
        $token = (string) $recipient['tracking_token'];
        $unsubscribeUrl = TrackingService::unsubscribeUrl($token);

        $replacements = [
            EmailHtml::PIXEL_TOKEN       => TrackingService::openPixelUrl($token),
            EmailHtml::UNSUBSCRIBE_TOKEN => $unsubscribeUrl,
        ];
        foreach ($template['links'] as $linkId => $_url) {
            $replacements[EmailHtml::linkToken((int) $linkId)] = TrackingService::clickUrl($token, (int) $linkId);
        }

        $html = strtr($template['html'], $replacements);
        $text = strtr($template['text'], $replacements);

        $values = Personalization::valuesForContact($contact);
        $fallbacks = Settings::json('personalization_fallbacks', []);

        $missing = [];
        $subject = Personalization::render($template['subject'], $values, $fallbacks, false, $missingSubject);
        $html = Personalization::render($html, $values, $fallbacks, true, $missingHtml);
        $text = Personalization::render($text, $values, $fallbacks, false, $missingText);
        $missing = array_values(array_unique(array_merge($missingSubject, $missingHtml, $missingText)));

        $message = new Message(
            toEmail: (string) $recipient['recipient_email_snapshot'],
            fromEmail: (string) $campaign['from_email'],
            fromName: (string) $campaign['from_name'],
            replyTo: (string) ($campaign['reply_to'] ?? ''),
            subject: $subject,
            htmlBody: $html,
            textBody: $text,
            headers: [
                // RFC 8058 one-click unsubscribe. The POST target performs the
                // opt-out without any further interaction.
                'List-Unsubscribe'      => '<' . $unsubscribeUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
            tags: [
                'campaign_id'  => (string) $campaign['id'],
                'recipient_id' => (string) $recipient['id'],
            ],
        );

        return ['message' => $message, 'missing' => $missing];
    }

    /**
     * Renders a campaign with sample or real contact data for the preview
     * screen. Tracking tokens are replaced with inert placeholders so the
     * preview cannot register fake opens or clicks.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,mixed>|null $contact
     * @return array{subject:string, html:string, text:string, missing:list<string>}
     */
    public static function renderPreview(array $campaign, ?array $contact = null): array
    {
        $template = self::buildTemplate($campaign, false);

        $replacements = [
            EmailHtml::PIXEL_TOKEN       => 'about:blank',
            EmailHtml::UNSUBSCRIBE_TOKEN => '#unsubscribe-preview',
        ];
        foreach ($template['links'] as $linkId => $url) {
            $replacements[EmailHtml::linkToken((int) $linkId)] = $url;
        }

        $html = strtr($template['html'], $replacements);
        $text = strtr($template['text'], $replacements);

        $values = $contact === null
            ? Personalization::sampleValues()
            : Personalization::valuesForContact($contact);
        $fallbacks = Settings::json('personalization_fallbacks', []);

        $subject = Personalization::render($template['subject'], $values, $fallbacks, false, $missingSubject);
        $html = Personalization::render($html, $values, $fallbacks, true, $missingHtml);
        $text = Personalization::render($text, $values, $fallbacks, false, $missingText);

        return [
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
            'missing' => array_values(array_unique(array_merge($missingSubject, $missingHtml, $missingText))),
        ];
    }

    /**
     * Hidden preheader text — the snippet most clients show next to the
     * subject. The trailing whitespace characters stop the client from pulling
     * body copy into the preview.
     */
    private static function injectPreheader(string $html, string $preview): string
    {
        $escaped = htmlspecialchars($preview, ENT_QUOTES, 'UTF-8');
        $preheader = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;'
            . 'font-size:1px;line-height:1px;color:#ffffff;opacity:0;">'
            . $escaped . str_repeat('&#847;&zwnj;&nbsp;', 30)
            . '</div>';

        if (stripos($html, '<body') !== false) {
            return (string) preg_replace('#(<body[^>]*>)#i', '$1' . $preheader, $html, 1);
        }
        return $preheader . $html;
    }
}
